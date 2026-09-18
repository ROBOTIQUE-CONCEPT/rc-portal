<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

defined('ABSPATH') || exit;

/**
 * WordPress AJAX endpoint consuming the raw rows/records a browser
 * extracted client-side (see assets/js-src/message-logs.js) from every
 * KUKA message-log file in one archive — Jet/Access databases
 * (`format: "jet"`) and classic Windows Event Log files
 * (`format: "evt"`) alike — in a single request, and returning one merged,
 * translated, JSON-encoded list of entries. The browser renders the actual
 * table (with its pagination/filter/search UI — see message-logs.js);
 * this endpoint only applies the already-validated PHP-side business logic
 * (timestamp handling, corrupted-row filtering, message-code translation)
 * that was already proven out per-file before this endpoint learned to
 * merge them.
 *
 * Nothing here is written to disk or persisted anywhere: the JSON request
 * body is processed and discarded once the response is sent, exactly like
 * the main archive upload/analysis request (see the module's "no
 * persistence" requirement, documented on ToolsModule).
 */
final class MessageLogAjaxHandler
{
    public const ACTION = 'rc_tools_message_log_parse';
    public const NONCE_ACTION = 'rc_tools_message_log_parse';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (! is_user_logged_in() || ! current_user_can('rc_tools_use')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rc-portal')], 403);
        }

        $raw = file_get_contents('php://input');
        $payload = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (! is_array($payload)) {
            wp_send_json_error(['message' => __('Requête invalide.', 'rc-portal')], 400);
        }

        $nonce = isset($payload['nonce']) ? (string) $payload['nonce'] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('La demande a expiré, veuillez recharger la page.', 'rc-portal')], 403);
        }

        $dictionary = self::sanitizeDictionary($payload['dictionary'] ?? null);

        $files = isset($payload['files']) && is_array($payload['files']) ? $payload['files'] : [];

        $entries = [];
        $headers = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            if (isset($file['format']) && $file['format'] === 'evt') {
                $category = isset($file['category']) ? sanitize_text_field((string) $file['category']) : '';
                $records = isset($file['records']) && is_array($file['records']) ? $file['records'] : [];

                foreach ($records as $record) {
                    if (! is_array($record)) {
                        continue;
                    }
                    $entries[] = MessageLogProcessor::processEvtRecord($record, $category, $dictionary);
                }

                continue;
            }

            // "jet" (default): a file with no matching LOG_TABLES — e.g. a
            // pure message-dictionary database like MessAppli.mdb — simply
            // contributes zero entries here, which is expected, not an error.
            $headerRows = isset($file['header']) && is_array($file['header']) ? $file['header'] : [];
            if ($headerRows !== []) {
                $header = MessageLogProcessor::processHeader($headerRows);
                if ($header !== []) {
                    $headers[] = $header;
                }
            }

            $tablesPayload = isset($file['tables']) && is_array($file['tables']) ? $file['tables'] : [];
            foreach (MessageLogProcessor::LOG_TABLES as $table) {
                if (! isset($tablesPayload[$table]) || ! is_array($tablesPayload[$table])) {
                    continue;
                }
                foreach (MessageLogProcessor::processLogTable($tablesPayload[$table], $table, $dictionary) as $row) {
                    $entries[] = $row;
                }
            }
        }

        // The browser does all display-side sorting/filtering/pagination
        // (see message-logs.js) over plain JSON, so DateTimeImmutable is
        // serialized to a Unix timestamp (or null) here rather than shipped
        // as an object — JS reconstructs it with `new Date(seconds * 1000)`.
        foreach ($entries as &$entry) {
            $entry['date'] = $entry['date']?->getTimestamp();
        }
        unset($entry);

        wp_send_json_success(['entries' => $entries, 'headers' => $headers]);
    }

    /**
     * @param mixed $raw the payload's `dictionary` field, straight from json_decode()
     * @return array{byModuleKey:array<string,string>,byKey:array<string,string>}
     */
    private static function sanitizeDictionary(mixed $raw): array
    {
        $result = ['byModuleKey' => [], 'byKey' => []];

        if (! is_array($raw)) {
            return $result;
        }

        foreach (['byModuleKey', 'byKey'] as $mapName) {
            if (! isset($raw[$mapName]) || ! is_array($raw[$mapName])) {
                continue;
            }
            foreach ($raw[$mapName] as $key => $value) {
                if (! is_string($key) || $key === '' || ! is_scalar($value)) {
                    continue;
                }
                $result[$mapName][$key] = sanitize_text_field((string) $value);
            }
        }

        return $result;
    }
}
