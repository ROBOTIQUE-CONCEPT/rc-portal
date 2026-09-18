/**
 * Reads the KUKA controller's message-log files entirely in the browser,
 * then posts just the raw rows/records to a small WordPress AJAX endpoint
 * (MessageLogAjaxHandler) that applies the already-validated PHP-side
 * business logic (timestamp handling, corrupted-row filtering, sorting/
 * capping) and returns a ready-to-inject HTML fragment. Nothing here is
 * ever written to disk — the bytes for each candidate file are already
 * embedded in the page (base64) by ToolsPages::renderMessageLogs(), and
 * exist only in page/script memory for the duration of the analysis.
 *
 * Two message-log file formats are supported, detected server-side
 * (KukaArchiveAnalyzer::parseMessageLogs()) and told apart by each
 * placeholder's `data-format` attribute:
 *
 * - "jet": `KukaLog.mdb` and its `.bkp`/`.tmp` siblings — genuine Microsoft
 *   Jet/Access databases, read with `mdb-reader`
 *   (https://www.npmjs.com/package/mdb-reader, pure JS/TS, MIT). This
 *   deliberately replaces an earlier server-side approach that shelled out
 *   to the `mdbtools` command-line utility, commonly unavailable on shared
 *   hosting.
 * - "evt": classic Windows Event Log files (`KrcLog*.evt`, found in some
 *   archives' `Log Files/` folder) — the same binary format Windows
 *   2000/XP/2003 itself used (MS-EVEN's EVENTLOGRECORD, unrelated to the
 *   later XML-based .evtx). No library is needed for this one: the format
 *   is small, stable, and fully public (see
 *   https://learn.microsoft.com/en-us/openspecs/windows_protocols/ms-even/541de33d-cee4-4c16-976c-e1e2ea152e65),
 *   so parseEvtLog() below reads it directly with plain DataView/
 *   TextDecoder calls.
 *
 * Rebuilding this bundle (only needed if this file changes):
 *   cd modules/tools/assets/js-src && npm install && npm run build
 * — produces ../js/message-logs.bundle.js, which is what ToolsPages
 * enqueues.
 */

import MDBReader from "mdb-reader";
import { Buffer } from "buffer";

(function () {
    "use strict";

    ready(function () {
        var configEl = document.getElementById("rc-tools-message-log-config");
        if (!configEl) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            return;
        }

        var cards = document.querySelectorAll("[data-rc-message-log]");
        for (var i = 0; i < cards.length; i++) {
            processCard(cards[i], config);
        }
    });

    function ready(fn) {
        if (document.readyState !== "loading") {
            fn();
        } else {
            document.addEventListener("DOMContentLoaded", fn);
        }
    }

    function processCard(card, config) {
        var dataEl = card.querySelector(".rc-message-log__data");
        var body = card.querySelector(".rc-message-log__body");
        if (!dataEl || !body) {
            return;
        }

        var format = dataEl.getAttribute("data-format") || "jet";
        var category = dataEl.getAttribute("data-category") || "";
        var base64 = (dataEl.textContent || "").trim();

        var payload;
        try {
            payload = format === "evt"
                ? { format: "evt", category: category, records: parseEvtLog(base64ToUint8Array(base64)) }
                : buildJetPayload(base64, config.logTables || []);
        } catch (err) {
            showError(body, errorMessage(err));
            return;
        }

        payload.nonce = config.nonce;

        setStatus(body, config.i18n && config.i18n.sending);

        fetch(config.ajaxUrl, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
            credentials: "same-origin",
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || "");
                }
                body.innerHTML = json.data.html;
            })
            .catch(function (err) {
                showError(body, errorMessage(err));
            });
    }

    // -- Jet/Access (mdb-reader) ------------------------------------------

    function buildJetPayload(base64, logTables) {
        var buffer = Buffer.from(base64, "base64");
        var reader = new MDBReader(buffer);
        var tableNames = reader.getTableNames();

        var header = null;
        if (tableNames.indexOf("LogHeader") !== -1) {
            try {
                header = reader.getTable("LogHeader").getData();
            } catch (e) {
                header = null;
            }
        }

        var tables = {};
        for (var i = 0; i < logTables.length; i++) {
            var table = logTables[i];
            if (tableNames.indexOf(table) === -1) {
                continue;
            }
            try {
                tables[table] = reader.getTable(table).getData();
            } catch (e) {
                // One unreadable table must not block the others.
            }
        }

        return { format: "jet", header: header, tables: tables };
    }

    // -- Classic Windows Event Log (.evt) ---------------------------------

    /**
     * Reads a classic Windows Event Log (.evt) file: the 48-byte
     * EVENTLOGHEADER, then EVENTLOGRECORD entries back to back until the
     * signature stops matching (the trailing EOF/cursor record, or the end
     * of the buffer). Every integer is little-endian, per the format.
     * Never throws on a single bad record — parsing just stops there,
     * returning whatever was read successfully.
     */
    function parseEvtLog(bytes) {
        var records = [];
        if (bytes.length < 8) {
            return records;
        }

        var view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        var decoder = new TextDecoder("utf-16le");

        function readCString16(offset, maxOffset) {
            var end = offset;
            while (end + 1 < maxOffset && !(bytes[end] === 0 && bytes[end + 1] === 0)) {
                end += 2;
            }
            return { text: decoder.decode(bytes.subarray(offset, end)), end: end };
        }

        var headerLength = view.getUint32(0, true);
        var pos = headerLength;

        while (pos + 8 <= bytes.length) {
            var length = view.getUint32(pos, true);
            if (length === 0 || pos + length > bytes.length) {
                break;
            }
            var sig = String.fromCharCode(bytes[pos + 4], bytes[pos + 5], bytes[pos + 6], bytes[pos + 7]);
            if (sig !== "LfLe") {
                break;
            }

            var timeGenerated = view.getUint32(pos + 12, true);
            var eventType = view.getUint16(pos + 24, true);
            var numStrings = view.getUint16(pos + 26, true);
            var eventCategory = view.getUint16(pos + 28, true);
            var stringOffset = view.getUint32(pos + 36, true);

            var recEnd = pos + length;
            var source = readCString16(pos + 56, recEnd);

            var strings = [];
            var sp = pos + stringOffset;
            for (var i = 0; i < numStrings && sp < recEnd; i++) {
                var result = readCString16(sp, recEnd);
                strings.push(result.text);
                sp = result.end + 2;
            }

            records.push({
                timeGenerated: timeGenerated,
                eventType: eventType,
                eventCategory: eventCategory,
                sourceName: source.text,
                message: strings.length > 0 ? strings.join("\n") : null,
            });

            pos += length;
        }

        return records;
    }

    function base64ToUint8Array(base64) {
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    // -- Shared UI helpers -------------------------------------------------

    function setStatus(body, message) {
        var p = document.createElement("p");
        p.className = "rc-field--help";
        p.textContent = message || "…";
        body.innerHTML = "";
        body.appendChild(p);
    }

    function showError(body, message) {
        var div = document.createElement("div");
        div.className = "rc-portal-alert rc-portal-alert--error";
        div.textContent = message;
        body.innerHTML = "";
        body.appendChild(div);
    }

    function errorMessage(err) {
        var base = "Ce fichier n’a pas pu être analysé dans le navigateur";
        if (err && err.message) {
            return base + " : " + err.message;
        }
        return base + ".";
    }
})();
