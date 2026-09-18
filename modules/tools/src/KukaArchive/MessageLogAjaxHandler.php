<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

use RC\Portal\Modules\Tools\Ui\ToolsPages;

defined('ABSPATH') || exit;

/**
 * WordPress AJAX endpoint consuming the raw rows/records a browser
 * extracted client-side (see assets/js-src/message-logs.js) from one KUKA
 * message-log file — a Jet/Access database (`format: "jet"`) or a classic
 * Windows Event Log file (`format: "evt"`) — and returning the rendered
 * HTML fragment for that file's card (Ui\ToolsPages::renderMessageLogDatabaseCard()).
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

        // fileName/size/lastModified are deliberately not read from the
        // payload: the card's header (filename, size, last-modified) was
        // already rendered server-side in the placeholder this response
        // replaces the *body* of — see ToolsPages::renderMessageLogs() and
        // renderMessageLogDatabaseCard().
        $format = isset($payload['format']) && $payload['format'] === 'evt' ? 'evt' : 'jet';

        // Built client-side across every Jet dictionary file present in the
        // archive (see MessageLogProcessor's class docblock and
        // buildDictionary() in message-logs.js) and sent along with every
        // card's own payload, since a log entry and the dictionary that
        // translates it are often in two different files.
        $dictionary = [];
        if (isset($payload['dictionary']) && is_array($payload['dictionary'])) {
            foreach ($payload['dictionary'] as $dictKey => $dictValue) {
                if (! is_string($dictKey) || $dictKey === '' || ! is_scalar($dictValue)) {
                    continue;
                }
                $dictionary[$dictKey] = sanitize_text_field((string) $dictValue);
            }
        }

        if ($format === 'evt') {
            $category = isset($payload['category']) ? sanitize_text_field((string) $payload['category']) : '';
            $records = isset($payload['records']) && is_array($payload['records']) ? $payload['records'] : [];

            $entries = [];
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $entries[] = MessageLogProcessor::processEvtRecord($record, $category, $dictionary);
            }

            $database = [
                'header' => null,
                'entries' => $entries,
                'error' => null,
            ];
        } else {
            $headerRows = isset($payload['header']) && is_array($payload['header']) ? $payload['header'] : [];
            $header = $headerRows !== [] ? MessageLogProcessor::processHeader($headerRows) : null;

            $tablesPayload = isset($payload['tables']) && is_array($payload['tables']) ? $payload['tables'] : [];

            $entries = [];
            foreach (MessageLogProcessor::LOG_TABLES as $table) {
                if (! isset($tablesPayload[$table]) || ! is_array($tablesPayload[$table])) {
                    continue;
                }
                foreach (MessageLogProcessor::processLogTable($tablesPayload[$table], $table, $dictionary) as $row) {
                    $entries[] = $row;
                }
            }

            $database = [
                'header' => $header,
                'entries' => $entries,
                'error' => null,
            ];
        }

        $html = (new ToolsPages())->renderMessageLogDatabaseCard($database);

        wp_send_json_success(['html' => $html]);
    }
}
