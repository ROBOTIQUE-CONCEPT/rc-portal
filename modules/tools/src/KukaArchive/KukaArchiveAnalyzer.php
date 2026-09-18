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
 * report. Nothing is written to disk beyond a strictly transient temp file
 * (the uploaded archive is never extracted via `ZipArchive::extractTo()`,
 * and any temp file this class creates — for mdbtools, see below — is
 * unlinked before the corresponding method returns).
 *
 * Message-log databases (`KukaLog.mdb` and its `.bkp`/`.tmp` siblings) are
 * genuine Microsoft Jet/Access databases. This class attempts to actually
 * read them via the `mdbtools` command-line utility when it's present on
 * the server (`shell_exec()`/`popen()` enabled, `mdb-export` on PATH) —
 * common on a VPS or dedicated box, absent on most shared hosting — and
 * falls back to reporting name/size/last-modified only when it isn't. This
 * is a runtime feature check, not a build-time assumption, so the same code
 * gets more useful automatically on a host that can support it.
 *
 * The internal timestamp KUKA stores for each message-log row
 * (`LogLowDateTime`/`LogHighDateTime`) isn't documented publicly; the
 * conversion used here (see filetimeToDate()) was reverse-engineered by
 * cross-referencing it against this same tool's own independently-parsed
 * tt.log timestamps on a real sample archive — see that method's docblock.
 */
final class KukaArchiveAnalyzer
{
    /** Per-entry cap for a fully-read text file (ini/.dat/.log are all tiny; this is generous). */
    private const MAX_TEXT_ENTRY_BYTES = 2 * 1024 * 1024;

    /** Guard against pathological archives (zip bombs, junk uploads) — a real KRC backup has a few hundred entries. */
    private const MAX_ENTRIES = 20000;

    /** Extensions worth header-sniffing for a Jet/Access signature (message-log databases). */
    private const JET_CANDIDATE_EXTENSIONS = ['mdb', 'bkp', 'tmp'];

    /**
     * Message-log tables read when mdbtools is available. The `...Param`
     * companion tables (structured parameters for message-template
     * substitution) and `Version` are deliberately not read: without KUKA's
     * own message-template dictionary they can't be turned into readable
     * text, so this class exposes the raw log rows (code, source, class,
     * level) instead of attempting a partial, potentially misleading
     * substitution.
     */
    private const LOG_TABLES = [
        'LogBootE', 'LogBootW', 'LogBootI',
        'LogInstallationE', 'LogInstallationW', 'LogInstallationI',
        'LogProcessE', 'LogProcessW', 'LogProcessI',
        'LogSystemE', 'LogSystemW', 'LogSystemI',
        'LogUserActionE', 'LogUserActionW', 'LogUserActionI',
        'LogNotClassified',
    ];

    private static ?bool $mdbToolsAvailableCache = null;

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

            $configEntry = self::findConfigDatEntry($entries, $warnings);
            $configText = $configEntry !== null ? self::readText($zip, $configEntry) : null;

            return new KukaArchiveReport(
                sourceFileName: $originalFileName,
                sourceSize: $sourceSize,
                entryCount: count($entries),
                generatedAt: new \DateTimeImmutable(),
                warnings: $warnings,
                archive: $archive,
                cellVersion: self::parseCellVersion($zip, $entries),
                robots: self::parseRobots($zip, $entries, $archive),
                techPacks: self::parseTechPacks($zip, $entries),
                homePositions: self::parseHomePositions($configText),
                bases: self::parseNamedFrameArray($configText, 'BASE_DATA', 'BASE_NAME'),
                tools: self::parseNamedFrameArray($configText, 'TOOL_DATA', 'TOOL_NAME'),
                loads: self::parseLoads($configText),
                additionalLoads: self::parseAdditionalLoads($configText),
                workspaces: self::parseWorkspaces($zip, $entries),
                signals: self::parseSignals($configText),
                calibrations: self::parseCalibrations($zip, $entries),
                masteringEvents: $masteringEvents,
                masteringSerialChanges: self::detectSerialChanges($masteringEvents),
                warmStartErrors: self::parseWarmErrLog($zip, $entries),
                fatalErrors: self::parseTtLog($zip, $entries),
                debugDumps: self::parseDebugDumps($entries),
                programs: self::parsePrograms($entries),
                messageLogs: self::parseMessageLogs($zip, $entries)
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
            techPacks: [],
            homePositions: [],
            bases: ['items' => [], 'total' => 0],
            tools: ['items' => [], 'total' => 0],
            loads: ['items' => [], 'total' => 0],
            additionalLoads: [],
            workspaces: ['items' => [], 'total' => 0],
            signals: [],
            calibrations: [],
            masteringEvents: [],
            masteringSerialChanges: [],
            warmStartErrors: [],
            fatalErrors: [],
            debugDumps: [],
            programs: [],
            messageLogs: ['available' => false, 'databases' => []]
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
     * KUKA's installed "TechPacks" (optional software options) and their
     * versions, from am.ini's `[TechPacks]` section — a pipe-delimited
     * `TechPacks=` name list, then one `<name>=<version>` line per name.
     *
     * @param array<int,string> $entries
     * @return array<int,array{name:string,version:string}>
     */
    private static function parseTechPacks(\ZipArchive $zip, array $entries): array
    {
        $entry = self::findFirst($entries, '#(?:^|/)am\.ini$#i');
        if ($entry === null) {
            return [];
        }

        $text = self::readText($zip, $entry);
        if ($text === null || preg_match('/^TechPacks=(.*)$/mi', $text, $listMatch) !== 1) {
            return [];
        }

        $names = array_filter(array_map('trim', explode('|', $listMatch[1])), static fn (string $n): bool => $n !== '');

        $techPacks = [];
        foreach ($names as $name) {
            $version = '—';
            if (preg_match('/^' . preg_quote($name, '/') . '=(.*)$/m', $text, $versionMatch) === 1) {
                $version = trim($versionMatch[1]) !== '' ? trim($versionMatch[1]) : '—';
            }
            $techPacks[] = ['name' => $name, 'version' => self::normalizeEncoding($version)];
        }

        return $techPacks;
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
     * One entry per `KRC/R<n>` robot axis group found in the archive. The
     * per-robot serial number isn't stored in `$machine.dat`/`$robcor.dat`
     * (only model/version tags are) — when the archive describes exactly
     * one robot, am.ini's own `IRSerialNr` (which names a single "Roboter")
     * unambiguously refers to it; for more than one robot there is no
     * reliable way to attribute a serial per axis without guessing, so it's
     * left `null` rather than risk mislabeling.
     *
     * @param array<int,string> $entries
     * @param array{serialNumber:?string,...}|null $archive
     * @return array<int,array{axis:string,numAxes:?int,madaVersion:?string,robcorVersion:?string,modelName:?string,serialNumber:?string}>
     */
    private static function parseRobots(\ZipArchive $zip, array $entries, ?array $archive): array
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
                'serialNumber' => null,
            ];
        }

        usort($robots, static fn (array $a, array $b): int => $a['axis'] <=> $b['axis']);

        if (count($robots) === 1 && $archive !== null && ($archive['serialNumber'] ?? null) !== null) {
            $robots[0]['serialNumber'] = $archive['serialNumber'];
        }

        return $robots;
    }

    // -- Global configuration ($config.dat / $custom.dat) ----------------

    /**
     * Locates the per-robot `System/$config.dat` that carries the cell's
     * user-global data (home positions, bases, tools, loads, signals) —
     * the STEU-level `$config.dat` is just an empty template stub on every
     * archive inspected. Multi-robot archives get a warning and only the
     * first robot (by number) is analyzed — attributing bases/tools/signals
     * to the wrong robot would be worse than not showing a second one.
     *
     * @param array<int,string> $entries
     * @param array<int,string> $warnings
     */
    private static function findConfigDatEntry(array $entries, array &$warnings): ?string
    {
        $matches = self::findAll($entries, '#KRC/R(\d+)/System/\$config\.dat$#i');
        if ($matches === []) {
            return null;
        }

        sort($matches);

        if (count($matches) > 1) {
            $warnings[] = __("Plusieurs robots détectés dans l'archive — seules les données globales (positions, bases, outils, charges, signaux) du premier robot ont été analysées dans cette version.", 'rc-portal');
        }

        return $matches[0];
    }

    /**
     * Generic "brace struct" reader: `{KEY1 value1,KEY2 value2,...}` where a
     * value is either numeric or a `#SOME_ENUM` token (e.g. `MODE #OFF`).
     * Returns raw string values — numeric formatting/display is the
     * caller's job.
     *
     * @return array<string,string>
     */
    private static function parseStructPairs(string $braces): array
    {
        $pairs = [];
        if (preg_match_all('/([A-Za-z][A-Za-z0-9_]*)\s+(-?[\d.]+|#\w+)/', $braces, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[$match[1]] = $match[2];
            }
        }

        return $pairs;
    }

    /**
     * `E6AXIS XHOME[n]?={A1 ..,...,E6 ..}` home-position definitions, keyed
     * by their exact variable name (`XHOME`, `XHOME1`..`XHOME5`, however
     * many the file actually declares).
     *
     * @return array<string,array<string,string>>
     */
    private static function parseHomePositions(?string $text): array
    {
        $positions = [];
        if ($text !== null && preg_match_all('/E6AXIS\s+(XHOME\d*)\s*=\s*\{([^}]*)\}/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $positions[$match[1]] = self::parseStructPairs($match[2]);
            }
        }

        return $positions;
    }

    /**
     * Reads a `<dataVar>[n]={...}` FRAME array alongside its
     * `<nameVar>[n,]="..."` label array (bases/tools share this exact
     * shape). A slot is treated as unconfigured — and filtered out — when
     * its name is blank AND every frame value is zero; `total` reflects how
     * many slots the file actually declares (32 bases / 16 tools on a
     * standard KRC, but this doesn't assume that).
     *
     * @return array{items:array<int,array{index:int,name:?string,frame:array<string,string>}>,total:int}
     */
    private static function parseNamedFrameArray(?string $text, string $dataVar, string $nameVar): array
    {
        if ($text === null) {
            return ['items' => [], 'total' => 0];
        }

        $frames = [];
        if (preg_match_all('/' . preg_quote($dataVar, '/') . '\[(\d+)\]\s*=\s*\{([^}]*)\}/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $frames[(int) $match[1]] = self::parseStructPairs($match[2]);
            }
        }

        $names = [];
        if (preg_match_all('/' . preg_quote($nameVar, '/') . '\[(\d+),\]\s*=\s*"([^"]*)"/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $names[(int) $match[1]] = self::normalizeEncoding(trim($match[2]));
            }
        }

        $items = [];
        foreach ($frames as $index => $frame) {
            $name = $names[$index] ?? '';
            $hasNonZero = false;
            foreach ($frame as $value) {
                if (is_numeric($value) && (float) $value !== 0.0) {
                    $hasNonZero = true;
                    break;
                }
            }
            if ($name === '' && ! $hasNonZero) {
                continue;
            }
            $items[] = ['index' => $index, 'name' => $name !== '' ? $name : null, 'frame' => $frame];
        }

        ksort($items);

        return ['items' => array_values($items), 'total' => count($frames)];
    }

    /**
     * `LOAD_DATA[n]={M mass,CM {...},J {...}}` — a slot with `M=-1` is
     * KUKA's own convention for "not configured" and is filtered out.
     *
     * @return array{items:array<int,array{index:int,mass:float,centerOfMass:array<string,string>,inertia:array<string,string>}>,total:int}
     */
    private static function parseLoads(?string $text): array
    {
        if ($text === null) {
            return ['items' => [], 'total' => 0];
        }

        $items = [];
        $total = 0;
        if (preg_match_all('/LOAD_DATA\[(\d+)\]\s*=\s*\{M\s+(-?[\d.]+),CM\s*\{([^}]*)\},J\s*\{([^}]*)\}\}/', $text, $matches, PREG_SET_ORDER)) {
            $total = count($matches);
            foreach ($matches as $match) {
                $mass = (float) $match[2];
                if ($mass < 0) {
                    continue;
                }
                $items[] = [
                    'index' => (int) $match[1],
                    'mass' => $mass,
                    'centerOfMass' => self::parseStructPairs($match[3]),
                    'inertia' => self::parseStructPairs($match[4]),
                ];
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * `LOAD_A1_DATA`/`LOAD_A2_DATA`/`LOAD_A3_DATA` — additional loads
     * carried on axes 1-3, same shape and same `M=-1` filtering as
     * parseLoads().
     *
     * @return array<int,array{axis:int,mass:float,centerOfMass:array<string,string>,inertia:array<string,string>}>
     */
    private static function parseAdditionalLoads(?string $text): array
    {
        $items = [];
        if ($text !== null && preg_match_all('/LOAD_A(\d)_DATA\s*=\s*\{M\s+(-?[\d.]+),CM\s*\{([^}]*)\},J\s*\{([^}]*)\}\}/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $mass = (float) $match[2];
                if ($mass < 0) {
                    continue;
                }
                $items[] = [
                    'axis' => (int) $match[1],
                    'mass' => $mass,
                    'centerOfMass' => self::parseStructPairs($match[3]),
                    'inertia' => self::parseStructPairs($match[4]),
                ];
            }
        }

        return $items;
    }

    /**
     * `$WORKSPACE[n]={...,MODE #xxx}` cartesian envelopes and their
     * `$WORKSPACE_NAMEn[]="..."` labels, from the cell-level
     * `KRC/STEU/Mada/$custom.dat`. An envelope with `MODE=#OFF` (KUKA's own
     * "not active" value) is filtered out.
     *
     * @param array<int,string> $entries
     * @return array{items:array<int,array{index:int,name:?string,mode:?string,params:array<string,string>}>,total:int}
     */
    private static function parseWorkspaces(\ZipArchive $zip, array $entries): array
    {
        $entry = self::findFirst($entries, '#KRC/STEU/Mada/\$custom\.dat$#i');
        if ($entry === null) {
            return ['items' => [], 'total' => 0];
        }

        $text = self::readText($zip, $entry);
        if ($text === null) {
            return ['items' => [], 'total' => 0];
        }

        $frames = [];
        if (preg_match_all('/\$WORKSPACE\[(\d+)\]\s*=\s*\{([^}]*)\}/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $frames[(int) $match[1]] = self::parseStructPairs($match[2]);
            }
        }

        $names = [];
        if (preg_match_all('/\$WORKSPACE_NAME(\d+)\[\]\s*=\s*"([^"]*)"/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $names[(int) $match[1]] = self::normalizeEncoding(trim($match[2]));
            }
        }

        $items = [];
        foreach ($frames as $index => $params) {
            $mode = $params['MODE'] ?? null;
            if ($mode === null || $mode === '#OFF') {
                continue;
            }
            $items[] = [
                'index' => $index,
                'name' => isset($names[$index]) && $names[$index] !== '' ? $names[$index] : null,
                'mode' => $mode,
                'params' => $params,
            ];
        }

        ksort($items);

        return ['items' => array_values($items), 'total' => count($frames)];
    }

    /**
     * `SIGNAL <name> $TYPE[n] [TO $TYPE[n2]] [;comment]` declarations.
     *
     * @return array<int,array{name:string,target:string,targetTo:?string,comment:?string}>
     */
    private static function parseSignals(?string $text): array
    {
        $signals = [];
        if ($text !== null && preg_match_all('/^SIGNAL\s+(\S+)\s+(\$\w+\[\d+\])(?:\s+TO\s+(\$\w+\[\d+\]))?\s*(?:;\s*(.*))?$/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comment = isset($match[4]) ? trim($match[4]) : '';
                $signals[] = [
                    'name' => $match[1],
                    'target' => $match[2],
                    'targetTo' => isset($match[3]) && $match[3] !== '' ? $match[3] : null,
                    'comment' => $comment !== '' ? self::normalizeEncoding($comment) : null,
                ];
            }
        }

        return $signals;
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

    // -- Message-log databases (Jet/Access) -------------------------------

    /**
     * Detects the Jet/Access message-log databases by sniffing each
     * candidate entry's own header for the format's real signature
     * (`Standard Jet`/`Standard ACE` at byte offset 4) rather than trusting
     * the file extension alone, then either parses each one with mdbtools
     * (when available on this server) or reports name/size/last-modified
     * only.
     *
     * @param array<int,string> $entries
     * @return array{available:bool,databases:array<int,array{fileName:string,size:int,lastModified:?\DateTimeImmutable,parsed:bool,header:?array<string,string>,entries:array<int,array{category:string,date:?\DateTimeImmutable,source:?string,instance:?string,messageCode:?string,level:?string,module:?string,key:?string,class:?string,type:?string}>,error:?string}>}
     */
    private static function parseMessageLogs(\ZipArchive $zip, array $entries): array
    {
        $candidates = [];
        foreach ($entries as $entry) {
            $extension = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::JET_CANDIDATE_EXTENSIONS, true)) {
                continue;
            }
            if (self::looksLikeJetDatabase($zip, $entry)) {
                $candidates[] = $entry;
            }
        }

        if ($candidates === []) {
            return ['available' => false, 'databases' => []];
        }

        $mdbToolsAvailable = self::mdbToolsAvailable();
        $databases = [];

        foreach ($candidates as $entry) {
            $stat = $zip->statName($entry);
            $lastModified = null;
            if ($stat !== false && isset($stat['mtime']) && $stat['mtime'] > 0) {
                $lastModified = (new \DateTimeImmutable())->setTimestamp((int) $stat['mtime']);
            }

            $database = [
                'fileName' => basename($entry),
                'size' => $stat !== false ? (int) $stat['size'] : 0,
                'lastModified' => $lastModified,
                'parsed' => false,
                'header' => null,
                'entries' => [],
                'error' => null,
            ];

            if ($mdbToolsAvailable) {
                $database = self::parseJetDatabase($zip, $entry, $database);
            }

            $databases[] = $database;
        }

        usort($databases, static fn (array $a, array $b): int => strcasecmp($a['fileName'], $b['fileName']));

        return ['available' => $mdbToolsAvailable, 'databases' => $databases];
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

    /**
     * @param array{fileName:string,size:int,lastModified:?\DateTimeImmutable,parsed:bool,header:?array<string,string>,entries:array<int,mixed>,error:?string} $database
     * @return array{fileName:string,size:int,lastModified:?\DateTimeImmutable,parsed:bool,header:?array<string,string>,entries:array<int,mixed>,error:?string}
     */
    private static function parseJetDatabase(\ZipArchive $zip, string $entry, array $database): array
    {
        $bytes = $zip->getFromName($entry);
        if ($bytes === false) {
            $database['error'] = __('Lecture impossible dans l’archive.', 'rc-portal');
            return $database;
        }

        $tmpPath = rtrim(sys_get_temp_dir(), '/') . '/rc-tools-kuka-' . bin2hex(random_bytes(8)) . '.mdb';
        if (@file_put_contents($tmpPath, $bytes) === false) {
            $database['error'] = __('Écriture temporaire impossible.', 'rc-portal');
            return $database;
        }
        @chmod($tmpPath, 0600);

        try {
            $availableTables = self::listMdbTables($tmpPath);
            if ($availableTables === null) {
                $database['error'] = __('La base n’a pas pu être ouverte par mdbtools (fichier corrompu ou format non reconnu).', 'rc-portal');
                return $database;
            }

            if (in_array('LogHeader', $availableTables, true)) {
                $database['header'] = self::exportMdbHeader($tmpPath);
            }

            $entriesOut = [];
            foreach (self::LOG_TABLES as $table) {
                if (! in_array($table, $availableTables, true)) {
                    continue;
                }
                foreach (self::exportMdbLogTable($tmpPath, $table) as $row) {
                    $entriesOut[] = $row;
                }
            }

            usort($entriesOut, static fn (array $a, array $b): int => ($a['date']?->getTimestamp() ?? 0) <=> ($b['date']?->getTimestamp() ?? 0));

            $database['entries'] = $entriesOut;
            $database['parsed'] = true;
        } finally {
            @unlink($tmpPath);
        }

        return $database;
    }

    private static function mdbToolsAvailable(): bool
    {
        if (self::$mdbToolsAvailableCache !== null) {
            return self::$mdbToolsAvailableCache;
        }

        if (! function_exists('shell_exec') || ! function_exists('popen')) {
            return self::$mdbToolsAvailableCache = false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true) || in_array('popen', $disabled, true)) {
            return self::$mdbToolsAvailableCache = false;
        }

        $which = @shell_exec('command -v mdb-export 2>/dev/null');

        return self::$mdbToolsAvailableCache = is_string($which) && trim($which) !== '';
    }

    /** @return array<int,string>|null */
    private static function listMdbTables(string $path): ?array
    {
        $output = @shell_exec('mdb-tables -1 ' . escapeshellarg($path) . ' 2>/dev/null');
        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $t): bool => $t !== ''));
    }

    /** @return array<int,array<string,string>> */
    private static function exportMdbCsv(string $path, string $table): array
    {
        $handle = @popen('mdb-export ' . escapeshellarg($path) . ' ' . escapeshellarg($table) . ' 2>/dev/null', 'r');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $header = fgetcsv($handle);
        if ($header !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }
                $rows[] = array_combine($header, $row);
            }
        }
        pclose($handle);

        return $rows;
    }

    /** @return array<string,string> */
    private static function exportMdbHeader(string $path): array
    {
        $header = [];
        foreach (self::exportMdbCsv($path, 'LogHeader') as $row) {
            if (isset($row['Name'])) {
                $header[$row['Name']] = self::normalizeEncoding($row['Value'] ?? '');
            }
        }

        return $header;
    }

    /** @return array<int,array{category:string,date:?\DateTimeImmutable,source:?string,instance:?string,messageCode:?string,level:?string,module:?string,key:?string,class:?string,type:?string}> */
    private static function exportMdbLogTable(string $path, string $table): array
    {
        $out = [];
        foreach (self::exportMdbCsv($path, $table) as $row) {
            $source = self::normalizeEncoding($row['LogSource'] ?? '');
            // Some `.tmp`/mid-write working-copy databases yield structurally
            // valid rows whose actual field bytes are corrupted (seen as a
            // run of the same non-alphanumeric character, e.g.
            // "################"); skip those rather than show garbage.
            if ($source !== '' && preg_match('/[A-Za-z0-9]/', $source) !== 1) {
                continue;
            }

            $out[] = [
                'category' => $table,
                'date' => self::filetimeToDate($row['LogLowDateTime'] ?? null, $row['LogHighDateTime'] ?? null),
                'source' => $source ?: null,
                'instance' => self::normalizeEncoding($row['LogInstance'] ?? '') ?: null,
                'messageCode' => $row['LogMessage'] ?? null,
                'level' => $row['LogLevel'] ?? null,
                'module' => self::normalizeEncoding($row['LogDBModul'] ?? '') ?: null,
                'key' => $row['LogDBKey'] ?? null,
                'class' => $row['LogClass'] ?? null,
                'type' => $row['LogType'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Converts KUKA's split 64-bit internal timestamp (`LogLowDateTime` +
     * `LogHighDateTime`, as exported by mdb-export from the message-log
     * databases) to a calendar date/time.
     *
     * This isn't documented by KUKA. It was empirically reverse-engineered
     * against a real sample archive: reading the combined 64-bit value
     * (`high * 2^32 + low`, both taken as unsigned) as a Windows FILETIME
     * (100ns ticks since 1601-01-01) lands 400+ years in the future — but
     * at exactly DOUBLE that tick rate (i.e. dividing by 2×10,000,000
     * instead of 10,000,000), the same archive's `LogSystemE` table's
     * oldest entry comes out to 2025-10-27 12:12:59, within 34 minutes of a
     * fatal error this same tool independently parses from that archive's
     * plain-text `tt.log` at 27.10.25 12:46:51 — and the newest entries
     * across `LogSystemE/W/I` land within a few hours of the archive's own
     * backup timestamp (am.ini `Date=`). Sorting a table by `LogID` also
     * makes this value strictly increasing, confirming it's a real
     * monotonic clock reading and not noise. Given that convergence across
     * three independent references, "double-rate FILETIME" is treated as
     * confirmed for this log format — but never throws, and rejects
     * anything outside a plausible 2000-2100 window rather than surface an
     * implausible date if a future archive's encoding differs.
     */
    private static function filetimeToDate(?string $low, ?string $high): ?\DateTimeImmutable
    {
        if ($low === null || $high === null || $low === '' || $high === '') {
            return null;
        }

        $lowInt = (int) $low;
        $highInt = (int) $high;
        $lowUnsigned = $lowInt < 0 ? $lowInt + 4294967296 : $lowInt;
        $highUnsigned = $highInt < 0 ? $highInt + 4294967296 : $highInt;
        $combined = $highUnsigned * 4294967296 + $lowUnsigned;

        if ($combined <= 0) {
            return null;
        }

        $unixSeconds = intdiv($combined, 20000000) - 11644473600;

        // Plausibility guard (2000-01-01 .. 2100-01-01): reject rather than
        // show a clearly-wrong date if the encoding doesn't hold for a given
        // archive.
        if ($unixSeconds < 946684800 || $unixSeconds > 4102444800) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($unixSeconds);
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
