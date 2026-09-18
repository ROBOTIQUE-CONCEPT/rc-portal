<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

defined('ABSPATH') || exit;

/**
 * Parses a KUKA KRC controller archive (.zip, produced by KUKA's own
 * "Archive Manager" backup tool) into a KukaArchiveReport, entirely in
 * memory, for field-diagnostic purposes.
 *
 * Every extractor here is defensive by design: a missing or malformed file
 * inside the archive yields `null`/`[]` for that section, never an
 * exception — one bad or absent file must never break the rest of the
 * report. Nothing is written to disk, and the archive is never extracted
 * (`ZipArchive::extractTo()` is deliberately never called) — only specific,
 * known-pattern text entries are read fully via `getFromName()`, and large
 * binary entries are only header-sniffed via `getStream()`.
 *
 * Scope note (v1): the KUKA message-log databases (`KukaLog.mdb` and its
 * `.bkp`/`.tmp` siblings) are genuine Microsoft Jet/Access databases. Fully
 * parsing that binary format in pure PHP — with no `mdbtools`/ODBC driver
 * assumed on shared hosting, and `exec()` commonly disabled there — is a
 * substantial undertaking of its own; v1 only detects and lists these files
 * (see parseUnparsedDatabases()) rather than attempting a partial parser.
 */
final class KukaArchiveAnalyzer
{
    /** Per-entry cap for a fully-read text file (ini/.dat/.log are all tiny; this is generous). */
    private const MAX_TEXT_ENTRY_BYTES = 2 * 1024 * 1024;

    /** Guard against pathological archives (zip bombs, junk uploads) — a real KRC backup has a few hundred entries. */
    private const MAX_ENTRIES = 20000;

    /** Extensions worth header-sniffing for a Jet/Access signature (message-log databases). */
    private const JET_CANDIDATE_EXTENSIONS = ['mdb', 'bkp', 'tmp'];

    public static function analyze(string $zipPath, string $originalFileName, int $sourceSize): KukaArchiveReport
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::RDONLY);

        if ($opened !== true) {
            return self::emptyReport(
                $originalFileName,
                $sourceSize,
                [__("Le fichier n'a pas pu être ouvert comme archive ZIP valide.", 'rc-portal')]
            );
        }

        try {
            $entries = self::listEntries($zip);

            if (count($entries) > self::MAX_ENTRIES) {
                return self::emptyReport(
                    $originalFileName,
                    $sourceSize,
                    [__("L'archive contient un nombre de fichiers anormalement élevé — analyse interrompue par sécurité.", 'rc-portal')]
                );
            }

            $warnings = [];
            $archive = self::parseAmIni($zip, $entries, $warnings);
            $masteringEvents = self::parseMasteryLog($zip, $entries);

            return new KukaArchiveReport(
                sourceFileName: $originalFileName,
                sourceSize: $sourceSize,
                entryCount: count($entries),
                generatedAt: new \DateTimeImmutable(),
                warnings: $warnings,
                archive: $archive,
                cellVersion: self::parseCellVersion($zip, $entries),
                robots: self::parseRobots($zip, $entries),
                calibrations: self::parseCalibrations($zip, $entries),
                masteringEvents: $masteringEvents,
                masteringSerialChanges: self::detectSerialChanges($masteringEvents),
                warmStartErrors: self::parseWarmErrLog($zip, $entries),
                fatalErrors: self::parseTtLog($zip, $entries),
                debugDumps: self::parseDebugDumps($entries),
                programs: self::parsePrograms($entries),
                unparsedDatabases: self::parseUnparsedDatabases($zip, $entries)
            );
        } finally {
            $zip->close();
        }
    }

    /** @param array<int,string> $warnings */
    private static function emptyReport(string $originalFileName, int $sourceSize, array $warnings): KukaArchiveReport
    {
        return new KukaArchiveReport(
            sourceFileName: $originalFileName,
            sourceSize: $sourceSize,
            entryCount: 0,
            generatedAt: new \DateTimeImmutable(),
            warnings: $warnings,
            archive: null,
            cellVersion: null,
            robots: [],
            calibrations: [],
            masteringEvents: [],
            masteringSerialChanges: [],
            warmStartErrors: [],
            fatalErrors: [],
            debugDumps: [],
            programs: [],
            unparsedDatabases: []
        );
    }

    // -- Archive Manager metadata ---------------------------------------

    /**
     * @param array<int,string> $entries
     * @param array<int,string> $warnings
     * @return array{name:?string,date:?string,id:?string,toolVersion:?string,robotName:?string,serialNumber:?string}|null
     */
    private static function parseAmIni(\ZipArchive $zip, array $entries, array &$warnings): ?array
    {
        $entry = self::findFirst($entries, '#(?:^|/)am\.ini$#i');
        if ($entry === null) {
            $warnings[] = __("am.ini introuvable — cette archive ne ressemble pas à un backup KUKA Archive Manager standard.", 'rc-portal');
            return null;
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            $warnings[] = __("am.ini n'a pas pu être lu (fichier trop volumineux ou illisible).", 'rc-portal');
            return null;
        }

        $get = static function (string $key) use ($text): ?string {
            return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/mi', $text, $m) === 1 ? trim($m[1]) : null;
        };

        return [
            'name' => $get('Name'),
            'date' => $get('Date'),
            'id' => $get('ID'),
            'toolVersion' => $get('Version'),
            'robotName' => $get('RobName'),
            'serialNumber' => $get('IRSerialNr'),
        ];
    }

    /**
     * `$V_STEUMADA` is the cell-level ("STEU" — Steuerung/controller) KSS
     * version tag, distinct from each robot's own `$V_R<n>MADA` tag.
     *
     * @param array<int,string> $entries
     */
    private static function parseCellVersion(\ZipArchive $zip, array $entries): ?string
    {
        $entry = self::findFirst($entries, '#KRC/STEU/Mada/\$machine\.dat$#i');
        if ($entry === null) {
            return null;
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            return null;
        }

        return preg_match('/\$V_STEUMADA\[\]="([^"]*)"/', $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * One entry per `KRC/R<n>` robot axis group found in the archive.
     *
     * @param array<int,string> $entries
     * @return array<int,array{axis:string,numAxes:?int,madaVersion:?string,robcorVersion:?string,modelName:?string}>
     */
    private static function parseRobots(\ZipArchive $zip, array $entries): array
    {
        $robots = [];

        foreach (self::findAll($entries, '#KRC/R(\d+)/Mada/\$machine\.dat$#i') as $entry) {
            if (preg_match('#KRC/R(\d+)/Mada/\$machine\.dat$#i', $entry, $m) !== 1) {
                continue;
            }
            $robotNumber = $m[1];

            $madaVersion = null;
            $numAxes = null;
            $text = self::readText($zip, $entry);
            if ($text !== null) {
                if (preg_match('/\$V_R\d+MADA\[\]="([^"]*)"/', $text, $mm) === 1) {
                    $madaVersion = $mm[1];
                }
                if (preg_match('/\$NUM_AX=(\d+)/', $text, $mm) === 1) {
                    $numAxes = (int) $mm[1];
                }
            }

            $robcorVersion = null;
            $modelName = null;
            $robcorEntry = self::findFirst($entries, '#KRC/R' . $robotNumber . '/Mada/\$robcor\.dat$#i');
            if ($robcorEntry !== null) {
                $robcorText = self::readText($zip, $robcorEntry);
                if ($robcorText !== null) {
                    if (preg_match('/\$V_ROBCOR\[\]="([^"]*)"/', $robcorText, $mm) === 1) {
                        $robcorVersion = $mm[1];
                    }
                    if (preg_match('/\$MODEL_NAME\[\]="([^"]*)"/', $robcorText, $mm) === 1) {
                        $modelName = $mm[1];
                    }
                }
            }

            $robots[] = [
                'axis' => 'R' . $robotNumber,
                'numAxes' => $numAxes,
                'madaVersion' => $madaVersion,
                'robcorVersion' => $robcorVersion,
                'modelName' => $modelName,
            ];
        }

        usort($robots, static fn (array $a, array $b): int => $a['axis'] <=> $b['axis']);

        return $robots;
    }

    /**
     * One entry per `<serial>.cal` calibration file under `Ir_Spec/`. A
     * non-zero `CalibrationDifference` is surfaced as `hasDrift` — reported
     * as a fact for a technician to interpret, never as a pass/fail verdict.
     *
     * @param array<int,string> $entries
     * @return array<int,array{serialNumber:string,firstEncoderValues:array<int,int>,calibrationDifferences:array<int,float>,hasDrift:bool}>
     */
    private static function parseCalibrations(\ZipArchive $zip, array $entries): array
    {
        $calibrations = [];

        foreach (self::findAll($entries, '#C/KRC/Roboter/Ir_Spec/([^/]+)\.cal$#i') as $entry) {
            if (preg_match('#([^/]+)\.cal$#i', $entry, $m) !== 1) {
                continue;
            }
            $serialNumber = $m[1];

            $text = self::readText($zip, $entry);
            if ($text === null) {
                continue;
            }

            $firstEncoderValues = [];
            if (preg_match('/\[FirstEncoderValues\](.*?)(?=\n\[|\z)/s', $text, $section) === 1
                && preg_match_all('/Axis(\d+)\s*=\s*(-?\d+)/', $section[1], $pairs, PREG_SET_ORDER)
            ) {
                foreach ($pairs as $pair) {
                    $firstEncoderValues[(int) $pair[1]] = (int) $pair[2];
                }
            }

            $calibrationDifferences = [];
            if (preg_match('/\[CalibrationDifference\](.*?)(?=\n\[|\z)/s', $text, $section) === 1
                && preg_match_all('/Axis(\d+)\s*=\s*(-?[\d.]+)/', $section[1], $pairs, PREG_SET_ORDER)
            ) {
                foreach ($pairs as $pair) {
                    $calibrationDifferences[(int) $pair[1]] = (float) $pair[2];
                }
            }

            $hasDrift = false;
            foreach ($calibrationDifferences as $value) {
                if (abs($value) > 0.0) {
                    $hasDrift = true;
                    break;
                }
            }

            $calibrations[] = [
                'serialNumber' => $serialNumber,
                'firstEncoderValues' => $firstEncoderValues,
                'calibrationDifferences' => $calibrationDifferences,
                'hasDrift' => $hasDrift,
            ];
        }

        return $calibrations;
    }

    /**
     * @param array<int,string> $entries
     * @return array<int,array{date:?\DateTimeImmutable,axis:int,serialNumber:string,label:string,firstEncoderValue:?int}>
     */
    private static function parseMasteryLog(\ZipArchive $zip, array $entries): array
    {
        $entry = self::findFirst($entries, '#C/KRC/Roboter/log/Mastery\.log$#i');
        if ($entry === null) {
            return [];
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            return [];
        }

        $events = [];
        $pattern = '/^Date:\s*(\S+)\s+Time:\s*(\S+)\s+Axis\s+(\d+)\s+Serialno\.:\s*(\S+)\s+(.+?)(?:\s*\(FirstEncoderValue:\s*(-?\d+)\))?\s*$/m';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $events[] = [
                    'date' => self::parseKukaDate($match[1], $match[2]),
                    'axis' => (int) $match[3],
                    'serialNumber' => $match[4],
                    'label' => trim($match[5]),
                    'firstEncoderValue' => isset($match[6]) && $match[6] !== '' ? (int) $match[6] : null,
                ];
            }
        }

        usort($events, static fn (array $a, array $b): int => ($a['date']?->getTimestamp() ?? 0) <=> ($b['date']?->getTimestamp() ?? 0));

        return $events;
    }

    /**
     * Flags an axis that was mastered under more than one distinct serial
     * number over the archive's history — a possible motor/resolver/encoder
     * swap, worth a technician's attention rather than a routine remastering.
     *
     * @param array<int,array{date:?\DateTimeImmutable,axis:int,serialNumber:string,label:string,firstEncoderValue:?int}> $masteringEvents
     * @return array<int,array{axis:int,serialNumbers:array<int,string>}>
     */
    private static function detectSerialChanges(array $masteringEvents): array
    {
        $byAxis = [];
        foreach ($masteringEvents as $event) {
            $byAxis[$event['axis']][$event['serialNumber']] = true;
        }

        $changes = [];
        foreach ($byAxis as $axis => $serials) {
            if (count($serials) > 1) {
                $changes[] = ['axis' => $axis, 'serialNumbers' => array_keys($serials)];
            }
        }

        usort($changes, static fn (array $a, array $b): int => $a['axis'] <=> $b['axis']);

        return $changes;
    }

    /**
     * Groups recurring warm-start failures (`WarmErr.log`) by their German
     * `Grund:` (reason) line, since the same underlying cause typically
     * repeats verbatim many times over a controller's life.
     *
     * @param array<int,string> $entries
     * @return array<int,array{reason:string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}>
     */
    private static function parseWarmErrLog(\ZipArchive $zip, array $entries): array
    {
        $entry = self::findFirst($entries, '#C/KRC/Roboter/log/WarmErr\.log$#i');
        if ($entry === null) {
            return [];
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            return [];
        }

        $blocks = preg_split('/^\s*={5,}\s*$/m', $text) ?: [];
        $grouped = [];

        foreach ($blocks as $block) {
            if (preg_match('/Datum:\s*(\S+)\s+Zeit:\s*(\S+)/', $block, $dateMatch) !== 1) {
                continue;
            }

            $reason = null;
            if (preg_match('/Grund:.*?\n==\s*(.+?)\s*(?:==)?\s*\n/s', $block, $reasonMatch) === 1) {
                $reason = self::normalizeEncoding(trim($reasonMatch[1]));
            }
            $reason ??= __('Raison non détectée', 'rc-portal');

            $date = self::parseKukaDate($dateMatch[1], $dateMatch[2]);

            $grouped[$reason] ??= ['reason' => $reason, 'count' => 0, 'firstDate' => null, 'lastDate' => null];
            self::accumulateGroup($grouped[$reason], $date);
        }

        $result = array_values($grouped);
        usort($result, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Groups fatal system errors (`tt.log`) by task + error code, since
     * KUKA repeats the same failure signature verbatim across occurrences.
     *
     * @param array<int,string> $entries
     * @return array<int,array{task:?string,errorCode:?string,mode:?string,robotModel:?string,serialNumber:?string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}>
     */
    private static function parseTtLog(\ZipArchive $zip, array $entries): array
    {
        $entry = self::findFirst($entries, '#C/KRC/Roboter/log/tt\.log$#i');
        if ($entry === null) {
            return [];
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            return [];
        }

        $blocks = preg_split('/^\s*={5,}\s*$/m', $text) ?: [];
        $grouped = [];

        foreach ($blocks as $block) {
            if (preg_match('/Datum:\s*(\S+)\s+Zeit:\s*(\S+)/', $block, $dateMatch) !== 1) {
                continue;
            }

            preg_match('/Verursachende TASK:\s*(\S+)\s+Systemfehler\s+(\S+)/', $block, $taskMatch);
            preg_match('/Betriebsart:\s*(\S+)/', $block, $modeMatch);
            preg_match('/Roboter:\s*(.+?)\s+Seriennummer:\s*(\S+)/', $block, $robotMatch);

            $task = $taskMatch[1] ?? null;
            $errorCode = $taskMatch[2] ?? null;
            $key = ($task ?? '?') . '|' . ($errorCode ?? '?');
            $date = self::parseKukaDate($dateMatch[1], $dateMatch[2]);

            $grouped[$key] ??= [
                'task' => $task,
                'errorCode' => $errorCode,
                'mode' => $modeMatch[1] ?? null,
                'robotModel' => isset($robotMatch[1]) ? self::normalizeEncoding(trim($robotMatch[1])) : null,
                'serialNumber' => $robotMatch[2] ?? null,
                'count' => 0,
                'firstDate' => null,
                'lastDate' => null,
            ];
            self::accumulateGroup($grouped[$key], $date);
        }

        $result = array_values($grouped);
        usort($result, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * @param array{count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable} $group
     */
    private static function accumulateGroup(array &$group, ?\DateTimeImmutable $date): void
    {
        $group['count']++;
        if ($date === null) {
            return;
        }
        if ($group['firstDate'] === null || $date < $group['firstDate']) {
            $group['firstDate'] = $date;
        }
        if ($group['lastDate'] === null || $date > $group['lastDate']) {
            $group['lastDate'] = $date;
        }
    }

    /**
     * Windows-side HMI crash dumps; the filename's own timestamp is the most
     * reliable one available (no reliable in-content date was found across
     * samples).
     *
     * @param array<int,string> $entries
     * @return array<int,array{fileName:string,timestamp:?\DateTimeImmutable}>
     */
    private static function parseDebugDumps(array $entries): array
    {
        $dumps = [];

        foreach (self::findAll($entries, '#DebugDump_(\d{8})_(\d{6})\.log$#i') as $entry) {
            $timestamp = null;
            if (preg_match('#DebugDump_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.log$#i', $entry, $m) === 1) {
                $formatted = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $formatted);
                $timestamp = $parsed !== false ? $parsed : null;
            }

            $dumps[] = ['fileName' => basename($entry), 'timestamp' => $timestamp];
        }

        usort($dumps, static fn (array $a, array $b): int => ($a['timestamp']?->getTimestamp() ?? 0) <=> ($b['timestamp']?->getTimestamp() ?? 0));

        return $dumps;
    }

    /**
     * @param array<int,string> $entries
     * @return array<int,array{robot:string,programs:array<int,string>}>
     */
    private static function parsePrograms(array $entries): array
    {
        $byRobot = [];

        foreach (self::findAll($entries, '#KRC/R(\d+)/Program/([^/]+)\.src$#i') as $entry) {
            if (preg_match('#KRC/R(\d+)/Program/([^/]+)\.src$#i', $entry, $m) !== 1) {
                continue;
            }
            $byRobot['R' . $m[1]][] = $m[2];
        }

        ksort($byRobot);

        $programs = [];
        foreach ($byRobot as $robot => $names) {
            sort($names);
            $programs[] = ['robot' => $robot, 'programs' => $names];
        }

        return $programs;
    }

    /**
     * Detects the Jet/Access message-log databases (`KukaLog.mdb` and its
     * `.bkp`/`.tmp` siblings) by sniffing each candidate entry's own header
     * for the format's real signature (`Standard Jet`/`Standard ACE` at byte
     * offset 4) rather than trusting the file extension alone, then reports
     * name/size/last-modified only — see this class's docblock for why full
     * parsing is out of scope for v1.
     *
     * @param array<int,string> $entries
     * @return array<int,array{fileName:string,size:int,lastModified:?\DateTimeImmutable}>
     */
    private static function parseUnparsedDatabases(\ZipArchive $zip, array $entries): array
    {
        $databases = [];

        foreach ($entries as $entry) {
            $extension = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::JET_CANDIDATE_EXTENSIONS, true)) {
                continue;
            }

            $stat = $zip->statName($entry);
            if ($stat === false || ! self::looksLikeJetDatabase($zip, $entry)) {
                continue;
            }

            $lastModified = null;
            if (isset($stat['mtime']) && $stat['mtime'] > 0) {
                $lastModified = (new \DateTimeImmutable())->setTimestamp((int) $stat['mtime']);
            }

            $databases[] = [
                'fileName' => basename($entry),
                'size' => (int) $stat['size'],
                'lastModified' => $lastModified,
            ];
        }

        usort($databases, static fn (array $a, array $b): int => strcasecmp($a['fileName'], $b['fileName']));

        return $databases;
    }

    private static function looksLikeJetDatabase(\ZipArchive $zip, string $entry): bool
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return false;
        }

        $header = fread($stream, 32);
        fclose($stream);

        if ($header === false || strlen($header) < 20) {
            return false;
        }

        $signature = substr($header, 4, 15);

        return str_starts_with($signature, 'Standard Jet') || str_starts_with($signature, 'Standard ACE');
    }

    // -- Zip / text helpers ----------------------------------------------

    /** @return array<int,string> */
    private static function listEntries(\ZipArchive $zip): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    /** @param array<int,string> $entries */
    private static function findFirst(array $entries, string $pattern): ?string
    {
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) === 1) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $entries
     * @return array<int,string>
     */
    private static function findAll(array $entries, string $pattern): array
    {
        $matches = [];
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) === 1) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    private static function readText(\ZipArchive $zip, string $entryName, int $maxBytes = self::MAX_TEXT_ENTRY_BYTES): ?string
    {
        $stat = $zip->statName($entryName);
        if ($stat === false || $stat['size'] > $maxBytes) {
            return null;
        }

        $contents = $zip->getFromName($entryName);
        if ($contents === false) {
            return null;
        }

        return self::normalizeEncoding(str_replace("\r\n", "\n", $contents));
    }

    /**
     * KUKA text files (KRC1/KRC2-era, European industrial equipment) are
     * commonly Windows-1252/ISO-8859-1 rather than UTF-8.
     */
    private static function normalizeEncoding(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        return is_string($converted) ? $converted : $text;
    }

    /**
     * KUKA logs use dot-separated dates (`D.M.YY`/`DD.MM.YY`, 2-digit year)
     * and inconsistently zero-padded times (`H:M:S`). Never throws — any
     * unparseable input yields `null` so a malformed line never breaks the
     * whole report.
     */
    private static function parseKukaDate(string $date, string $time): ?\DateTimeImmutable
    {
        $dateParts = explode('.', trim($date));
        $timeParts = explode(':', trim($time));
        if (count($dateParts) !== 3 || count($timeParts) !== 3) {
            return null;
        }

        [$day, $month, $year] = array_map('intval', $dateParts);
        [$hour, $minute, $second] = array_map('intval', $timeParts);

        if ($year < 100) {
            $year += 2000;
        }

        if (! checkdate($month, $day, $year)
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $second < 0 || $second > 59
        ) {
            return null;
        }

        $formatted = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $formatted);

        return $parsed !== false ? $parsed : null;
    }
}
