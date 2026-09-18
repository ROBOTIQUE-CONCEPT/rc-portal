<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

defined('ABSPATH') || exit;

/**
 * Turns the raw rows/records a browser extracted client-side from a KUKA
 * message-log file (via assets/js-src/message-logs.js) into the shape the
 * report's UI renders.
 *
 * This class never touches the log file itself: reading it happens
 * entirely in the browser (see MessageLogAjaxHandler for how the browser's
 * JSON payload reaches here). Two source formats feed into the same
 * unified entry shape:
 *
 * - "jet": rows read from a `KukaLog.mdb` Jet/Access database with the
 *   `mdb-reader` JS library. An earlier version of this tool shelled out to
 *   the `mdbtools` command-line utility on the server instead — unavailable
 *   on most shared hosting — so this replaces that server-side dependency
 *   entirely; the logic below (timestamp conversion, corrupted-row
 *   filtering) is unchanged from that earlier version, only its input
 *   changed (raw rows from the browser instead of `mdb-export` CSV output).
 * - "evt": records read from a classic Windows Event Log file
 *   (`KrcLog*.evt`) with a small hand-written binary parser (see
 *   parseEvtLog() in message-logs.js — no library needed, the format is
 *   small, stable and publicly documented). KUKA packs its own semantic
 *   fields (message code, module, key — mirroring the Jet DB's own
 *   `LogMessage`/`LogDBModul`/`LogDBKey` columns) into that record's single
 *   insertion string as `\n`-delimited lines; see parseEvtMessage() below
 *   for the exact layout, reverse-engineered from real sample archives.
 */
final class MessageLogProcessor
{
    /**
     * Message-log tables this tool understands (and asks the browser to
     * read, via the `logTables` list embedded in the page — see
     * Ui\ToolsPages::renderMessageLogs()). The `...Param` companion tables
     * (structured parameters for message-template substitution) and
     * `Version` are deliberately not read: without KUKA's own
     * message-template dictionary they can't be turned into readable text,
     * so this class exposes the raw log rows (code, source, class, level)
     * instead of attempting a partial, potentially misleading substitution.
     */
    public const LOG_TABLES = [
        'LogBootE', 'LogBootW', 'LogBootI',
        'LogInstallationE', 'LogInstallationW', 'LogInstallationI',
        'LogProcessE', 'LogProcessW', 'LogProcessI',
        'LogSystemE', 'LogSystemW', 'LogSystemI',
        'LogUserActionE', 'LogUserActionW', 'LogUserActionI',
        'LogNotClassified',
    ];

    /**
     * Classic Windows Event Log `EventType` values (MS-EVEN) that KUKA's
     * `.evt` files use, mapped to an internal label consumed by
     * EVT_LEVEL_LABELS below.
     */
    private const EVT_EVENT_TYPES = [
        1 => 'ERROR',
        2 => 'WARNING',
        4 => 'INFORMATION',
        8 => 'AUDIT_SUCCESS',
        16 => 'AUDIT_FAILURE',
    ];

    /** French display labels for the `level` column, keyed by EVT_EVENT_TYPES's internal label. */
    private const EVT_LEVEL_LABELS = [
        'ERROR' => 'Erreur',
        'WARNING' => 'Avertissement',
        'INFORMATION' => 'Info',
        'AUDIT_SUCCESS' => 'Audit (succès)',
        'AUDIT_FAILURE' => 'Audit (échec)',
    ];

    /**
     * KUKA's own naming convention for the `.evt` files found under a
     * `Log Files` folder, keyed by lowercased filename stem (without
     * extension). A filename this tool doesn't recognize falls back to its
     * own stem in evtCategoryFromFileName() below, so an unfamiliar log
     * still gets a sensible category rather than disappearing.
     */
    private const EVT_CATEGORY_LABELS = [
        'krclog' => 'Général',
        'krclogb' => 'Démarrage',
        'krclogi' => 'Installation',
        'krclogp' => 'Process',
        'krclogs' => 'Système',
        'krclogu' => 'Action utilisateur',
    ];

    /**
     * @param array<int,mixed> $rows raw `LogHeader` rows (Name/Value columns), as extracted by mdb-reader
     * @return array<string,string>
     */
    public static function processHeader(array $rows): array
    {
        $header = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['Name'])) {
                continue;
            }
            $header[(string) $row['Name']] = KukaArchiveAnalyzer::normalizeEncoding((string) ($row['Value'] ?? ''));
        }

        return $header;
    }

    /**
     * @param array<int,mixed> $rows raw rows of one log table, as extracted by mdb-reader
     * @return array<int,array{category:string,date:?\DateTimeImmutable,source:?string,instance:?string,messageCode:?string,level:?string,module:?string,key:?string,class:?string,type:?string}>
     */
    public static function processLogTable(array $rows, string $table): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $source = KukaArchiveAnalyzer::normalizeEncoding((string) ($row['LogSource'] ?? ''));
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
                'instance' => KukaArchiveAnalyzer::normalizeEncoding((string) ($row['LogInstance'] ?? '')) ?: null,
                'messageCode' => isset($row['LogMessage']) ? (string) $row['LogMessage'] : null,
                'level' => isset($row['LogLevel']) ? (string) $row['LogLevel'] : null,
                'module' => KukaArchiveAnalyzer::normalizeEncoding((string) ($row['LogDBModul'] ?? '')) ?: null,
                'key' => isset($row['LogDBKey']) ? (string) $row['LogDBKey'] : null,
                'class' => isset($row['LogClass']) ? (string) $row['LogClass'] : null,
                'type' => isset($row['LogType']) ? (string) $row['LogType'] : null,
            ];
        }

        return $out;
    }

    /**
     * Converts KUKA's split 64-bit internal timestamp (`LogLowDateTime` +
     * `LogHighDateTime`, as read by mdb-reader from the message-log
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
     *
     * Re-validated after moving from mdbtools' CSV export (decimal strings)
     * to mdb-reader's native JS numbers (`LogLowDateTime`/`LogHighDateTime`
     * as JS `number`, JSON-decoded here as PHP `int`): same field values,
     * same formula, same result — see the type union below, which simply
     * accepts either representation.
     */
    public static function filetimeToDate(int|string|null $low, int|string|null $high): ?\DateTimeImmutable
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

    /**
     * Maps a `.evt` file's own name to the French category label the UI
     * shows (the same role `$table` plays for the Jet format's
     * processLogTable()). Unrecognized names fall back to their own
     * filename stem so nothing silently disappears.
     */
    public static function evtCategoryFromFileName(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $key = strtolower($stem);

        return self::EVT_CATEGORY_LABELS[$key] ?? $stem;
    }

    /**
     * Builds one unified report entry from a raw classic Windows Event Log
     * record, as extracted client-side by parseEvtLog() in
     * message-logs.js. `$category` is precomputed once per file (via
     * evtCategoryFromFileName(), at initial page render — see
     * Ui\ToolsPages::renderMessageLogs()) and passed straight through, so
     * this method never needs the file name itself.
     *
     * `timeGenerated` is a standard Unix `time_t`, confirmed UTC by the
     * MS-EVEN spec — unlike the Jet format's LogLowDateTime/LogHighDateTime,
     * it needs no reverse-engineered conversion. The record's own insertion
     * string (`message`) additionally carries a local timestamp with UTC
     * offset, but that one is informational only and is not used here — see
     * parseEvtMessage() and the class docblock.
     *
     * @param array{timeGenerated?:mixed,eventType?:mixed,eventCategory?:mixed,sourceName?:mixed,message?:mixed} $record
     * @return array{category:string,date:?\DateTimeImmutable,source:?string,instance:?string,messageCode:?string,level:?string,module:?string,key:?string,class:?string,type:?string}
     */
    public static function processEvtRecord(array $record, string $category): array
    {
        $date = null;
        $timeGenerated = $record['timeGenerated'] ?? null;
        if (is_numeric($timeGenerated)) {
            $timestamp = (int) $timeGenerated;
            if ($timestamp > 0) {
                $date = (new \DateTimeImmutable())->setTimestamp($timestamp);
            }
        }

        $level = null;
        $eventType = isset($record['eventType']) && is_numeric($record['eventType']) ? (int) $record['eventType'] : null;
        if ($eventType !== null && isset(self::EVT_EVENT_TYPES[$eventType])) {
            $level = self::EVT_LEVEL_LABELS[self::EVT_EVENT_TYPES[$eventType]] ?? null;
        }

        // Every sample source name carries a redundant "KUKA-" prefix (e.g.
        // "KUKA-SystemS"); the category column already conveys that, so
        // it's stripped here for a cleaner Source column.
        $source = isset($record['sourceName']) && is_string($record['sourceName']) ? $record['sourceName'] : '';
        $source = preg_replace('/^KUKA-/', '', $source) ?? $source;

        $message = isset($record['message']) && is_string($record['message']) ? $record['message'] : '';
        $parsed = self::parseEvtMessage($message);

        return [
            'category' => $category,
            'date' => $date,
            'source' => $source !== '' ? $source : null,
            'instance' => null,
            'messageCode' => $parsed['messageCode'],
            'level' => $level,
            'module' => $parsed['module'],
            'key' => $parsed['key'],
            'class' => null,
            'type' => null,
        ];
    }

    /**
     * Parses KUKA's own message sub-format packed into a `.evt` record's
     * single insertion string — empirically reverse-engineered from real
     * sample archives (NumStrings was always 1 across every tested
     * record). It's a `\n`-delimited list of lines:
     *
     *   [0] "LogManager"                          — constant tag, ignored
     *   [1] local timestamp with UTC offset       — informational, ignored
     *       (e.g. "2026-06-18 12:59:50'801 +01:00"; see processEvtRecord())
     *   [2] numeric message code                  — matches the Jet DB's `LogMessage`
     *   [3] "Module#Key" (or just "Key" alone)     — matches `LogDBModul`#`LogDBKey`
     *   [4] "Module#ShortKey"                      — matches `LogDBShortKey`, unused here
     *   [5] "N - parameters"                       — declares the parameter count, unused here
     *   [6..6+N-1] "idx-value" parameter pairs      — unused here
     *   [6+N] "Optional: <value>"                   — unused here
     *
     * Defensive by design: a missing or malformed line simply yields a
     * null field rather than throwing, since this embedded sub-format is
     * not documented by KUKA and could plausibly vary between archives.
     *
     * @return array{messageCode:?string,module:?string,key:?string}
     */
    public static function parseEvtMessage(string $message): array
    {
        $result = ['messageCode' => null, 'module' => null, 'key' => null];

        if (trim($message) === '') {
            return $result;
        }

        $lines = preg_split('/\r\n|\r|\n/', $message);
        if ($lines === false) {
            return $result;
        }

        if (isset($lines[2])) {
            $code = trim($lines[2]);
            $result['messageCode'] = $code !== '' ? $code : null;
        }

        if (isset($lines[3])) {
            $moduleKey = trim($lines[3]);
            if ($moduleKey !== '') {
                if (str_contains($moduleKey, '#')) {
                    [$module, $key] = explode('#', $moduleKey, 2);
                    $result['module'] = $module !== '' ? $module : null;
                    $result['key'] = $key !== '' ? $key : null;
                } else {
                    $result['key'] = $moduleKey;
                }
            }
        }

        return $result;
    }
}
