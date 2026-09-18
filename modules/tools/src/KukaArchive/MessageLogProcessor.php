<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

defined('ABSPATH') || exit;

/**
 * Turns the raw table rows a browser extracted client-side from a KUKA
 * message-log Jet/Access database (via the `mdb-reader` JS library — see
 * assets/js-src/kuka-mdb.js) into the shape the report's UI renders.
 *
 * This class never touches the database file itself: reading the
 * Jet/Access binary format happens entirely in the browser (see
 * MessageLogAjaxHandler for how the browser's JSON payload of raw rows
 * reaches here). An earlier version of this tool shelled out to the
 * `mdbtools` command-line utility on the server instead — unavailable on
 * most shared hosting — so this replaces that server-side dependency
 * entirely; the logic below (timestamp conversion, corrupted-row
 * filtering) is unchanged from that earlier version, only its input
 * changed (raw rows from the browser instead of `mdb-export` CSV output).
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
}
