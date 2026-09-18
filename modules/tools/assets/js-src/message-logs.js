/**
 * Reads every KUKA controller message-log file in the archive entirely in
 * the browser, then posts just the raw rows/records — for every file, in a
 * single request — to a small WordPress AJAX endpoint (MessageLogAjaxHandler)
 * that applies the already-validated PHP-side business logic (timestamp
 * handling, corrupted-row filtering, message-code translation) and returns
 * one merged, JSON-encoded list of entries. This script then renders that
 * list as one table — merging every file rather than one per file — with
 * client-side pagination (100 rows/page), category/level filters, a
 * message+code text search, and a date/time range filter; all of that is
 * plain in-memory filtering over the already-fetched list, so changing a
 * filter never triggers another network request. Nothing here is ever
 * written to disk — the bytes for each candidate file are already embedded
 * in the page (base64) by ToolsPages::renderMessageLogs(), and exist only
 * in page/script memory for the duration of the analysis.
 *
 * Two message-log file formats are supported, detected server-side
 * (KukaArchiveAnalyzer::parseMessageLogs()) and told apart by each data
 * element's `data-format` attribute:
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
 * Neither format carries human-readable text, only a short module+key pair
 * per entry. Two dictionary sources are merged into one flat lookup before
 * any file is processed:
 *
 * - This plugin's own bundled, generic dictionary
 *   (assets/data/kuka-message-dictionary.json — see build-dictionary.mjs),
 *   fetched once here, covering KUKA's built-in system-level messages.
 * - Any archive-embedded Jet/Access "message dictionary" (an
 *   `Items`+`Messages` schema, typically in its own file, e.g.
 *   `MessAppli.mdb` — distinct from the message-log schema above) covering
 *   messages an integrator configured for their own application.
 *   buildDictionary() below opens every Jet-format file once looking for
 *   that schema, and its findings are merged over the bundled dictionary
 *   (so an archive-specific entry always wins on a collision).
 *
 * See MessageLogProcessor::translateMessage() on the PHP side for where
 * the merged dictionary is actually applied (including the "%1"-style
 * parameter substitution some `evt` messages need — see
 * MessageLogProcessor::parseEvtMessage()). Even with both sources, most
 * entries — particularly ones this plugin's bundled export doesn't carry —
 * legitimately have no translation.
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

    var PAGE_SIZE = 100;

    /**
     * Preferred language, in order, when a dictionary entry has text in
     * more than one — French first since this tool's UI is French, then
     * English, then German (the KUKA HMI's own default authoring
     * language), matching Languages.Language_id in the Jet DBs seen so
     * far. Kept in sync with build-dictionary.mjs's own copy of this list.
     */
    var DICTIONARY_LANGUAGE_PRIORITY = [12, 9, 7];

    ready(function () {
        var configEl = document.getElementById("rc-tools-message-log-config");
        var resultsEl = document.querySelector("[data-rc-message-log-results]");
        if (!configEl || !resultsEl) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            return;
        }

        var dataEls = document.querySelectorAll(".rc-message-log__data");
        if (dataEls.length === 0) {
            return;
        }

        setStatus(resultsEl, "Analyse en cours dans le navigateur…");

        fetchBundledDictionary(config.dictionaryUrl)
            .catch(function () {
                // A failed fetch of the bundled dictionary just means fewer
                // messages get translated — not a reason to stop the analysis.
                return { byModuleKey: {}, byKey: {} };
            })
            .then(function (bundledDictionary) {
                var dictionary = mergeDictionaries(bundledDictionary, buildDictionary(dataEls));

                var files = [];
                var parseErrors = [];
                for (var i = 0; i < dataEls.length; i++) {
                    try {
                        files.push(buildFilePayload(dataEls[i], config.logTables || []));
                    } catch (err) {
                        parseErrors.push(errorMessage(err));
                    }
                }

                setStatus(resultsEl, "Envoi pour analyse…");

                return fetch(config.ajaxUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ nonce: config.nonce, dictionary: dictionary, files: files }),
                    credentials: "same-origin",
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (json) {
                        if (!json || !json.success) {
                            throw new Error((json && json.data && json.data.message) || "");
                        }
                        renderResults(resultsEl, json.data.entries || [], json.data.headers || [], parseErrors);
                    });
            })
            .catch(function (err) {
                showError(resultsEl, errorMessage(err));
            });
    });

    function ready(fn) {
        if (document.readyState !== "loading") {
            fn();
        } else {
            document.addEventListener("DOMContentLoaded", fn);
        }
    }

    /** Builds this one file's AJAX payload shape from its data element — the dictionary/nonce are added once, at the top level of the combined request. */
    function buildFilePayload(dataEl, logTables) {
        var format = dataEl.getAttribute("data-format") || "jet";
        var base64 = (dataEl.textContent || "").trim();

        if (format === "evt") {
            return {
                format: "evt",
                category: dataEl.getAttribute("data-category") || "",
                records: parseEvtLog(base64ToUint8Array(base64)),
            };
        }

        return buildJetPayload(base64, logTables || []);
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

    function fetchBundledDictionary(url) {
        if (!url) {
            return Promise.resolve({ byModuleKey: {}, byKey: {} });
        }
        return fetch(url, { credentials: "same-origin" }).then(function (response) {
            if (!response.ok) {
                throw new Error("dictionary fetch failed: " + response.status);
            }
            return response.json();
        });
    }

    /**
     * Scans every Jet-format file up front (before any of them are
     * actually processed) for the `Items`+`Messages` "message dictionary"
     * schema — distinct from the `LogXxx` message-log schema
     * buildJetPayload() reads — and merges whatever it finds into one
     * `{byModuleKey, byKey}` lookup (see MessageLogProcessor::translateMessage()
     * for why there are two). A file that fails to open, or has neither
     * table, simply contributes nothing; this never throws, since the
     * dictionary is a bonus enrichment, not something any file's own
     * analysis depends on.
     */
    function buildDictionary(dataEls) {
        var dictionary = { byModuleKey: {}, byKey: {} };

        for (var i = 0; i < dataEls.length; i++) {
            var dataEl = dataEls[i];
            if ((dataEl.getAttribute("data-format") || "jet") !== "jet") {
                continue;
            }

            try {
                var buffer = Buffer.from((dataEl.textContent || "").trim(), "base64");
                var reader = new MDBReader(buffer);
                var tableNames = reader.getTableNames();
                if (tableNames.indexOf("Items") === -1 || tableNames.indexOf("Messages") === -1) {
                    continue;
                }

                var items = reader.getTable("Items").getData();
                var messages = reader.getTable("Messages").getData();

                var textsByKeyId = {};
                for (var m = 0; m < messages.length; m++) {
                    var row = messages[m];
                    if (!row || !row.String) {
                        continue;
                    }
                    textsByKeyId[row.Key_id] = textsByKeyId[row.Key_id] || {};
                    textsByKeyId[row.Key_id][row.Language_id] = row.String;
                }

                for (var it = 0; it < items.length; it++) {
                    var item = items[it];
                    if (!item || !item.Module || !item.KeyString) {
                        continue;
                    }
                    var texts = textsByKeyId[item.Key_id];
                    if (!texts) {
                        continue;
                    }

                    var text = null;
                    for (var lp = 0; lp < DICTIONARY_LANGUAGE_PRIORITY.length; lp++) {
                        if (texts[DICTIONARY_LANGUAGE_PRIORITY[lp]]) {
                            text = texts[DICTIONARY_LANGUAGE_PRIORITY[lp]];
                            break;
                        }
                    }
                    if (!text) {
                        var languageIds = Object.keys(texts);
                        for (var li = 0; li < languageIds.length; li++) {
                            if (texts[languageIds[li]]) {
                                text = texts[languageIds[li]];
                                break;
                            }
                        }
                    }

                    if (text) {
                        dictionary.byModuleKey[item.Module + "#" + item.KeyString] = text;
                        if (!(item.KeyString in dictionary.byKey)) {
                            dictionary.byKey[item.KeyString] = text;
                        }
                    }
                }
            } catch (e) {
                // Not every Jet file is a dictionary — a genuine message-log
                // database (KukaLog.mdb) will simply lack these tables and
                // land here or in the "continue" above; either way, skip it.
            }
        }

        return dictionary;
    }

    /** `b`'s entries win over `a`'s on a collision — used to let an archive's own dictionary override the bundled generic one. */
    function mergeDictionaries(a, b) {
        return {
            byModuleKey: Object.assign({}, a.byModuleKey || {}, b.byModuleKey || {}),
            byKey: Object.assign({}, a.byKey || {}, b.byKey || {}),
        };
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

    // -- Merged results: filters, search, pagination, table ---------------

    /**
     * Renders the whole results panel — file headers (if any), a toolbar
     * (category/level selects, a code+message search, a date/time range),
     * the current page's table, and pagination — from the merged JSON
     * entries the AJAX endpoint returned. Every control here just
     * re-filters/re-slices the in-memory `entries` array and redraws;
     * nothing triggers another request.
     */
    function renderResults(container, entries, headers, parseErrors) {
        entries = entries.slice().sort(function (a, b) {
            return (b.date || 0) - (a.date || 0);
        });

        container.innerHTML = "";

        for (var i = 0; i < parseErrors.length; i++) {
            var errorDiv = document.createElement("div");
            errorDiv.className = "rc-portal-alert rc-portal-alert--error";
            errorDiv.textContent = parseErrors[i];
            container.appendChild(errorDiv);
        }

        appendHeaders(container, headers);

        if (entries.length === 0) {
            var empty = document.createElement("p");
            empty.className = "rc-field--help";
            empty.textContent = "Aucun message dans les fichiers de journal détectés.";
            container.appendChild(empty);
            return;
        }

        var state = { category: "", level: "", search: "", dateFrom: "", dateTo: "", page: 1 };

        var toolbar = buildToolbar(distinctSorted(entries, "category"), distinctSorted(entries, "level"), function (patch) {
            Object.assign(state, patch);
            state.page = 1;
            redraw();
        });
        var summary = document.createElement("p");
        summary.className = "rc-field--help";
        var tableWrap = document.createElement("div");
        tableWrap.className = "rc-table-wrap";
        var pagination = document.createElement("nav");
        pagination.className = "rc-pagination";
        pagination.setAttribute("aria-label", "Pagination");

        container.appendChild(toolbar);
        container.appendChild(summary);
        container.appendChild(tableWrap);
        container.appendChild(pagination);

        redraw();

        function redraw() {
            var filtered = applyFilters(entries, state);
            var totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
            state.page = Math.min(Math.max(1, state.page), totalPages);
            var pageEntries = filtered.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

            summary.textContent = filtered.length === entries.length
                ? filtered.length + " messages, du plus récent au plus ancien."
                : filtered.length + " messages sur " + entries.length + " au total.";

            renderTable(tableWrap, pageEntries);
            renderPagination(pagination, state.page, totalPages, function (newPage) {
                state.page = newPage;
                redraw();
            });
        }
    }

    function appendHeaders(container, headers) {
        var grid = document.createElement("div");
        grid.className = "rc-field-grid";

        for (var h = 0; h < headers.length; h++) {
            var header = headers[h];
            var keys = Object.keys(header);
            for (var k = 0; k < keys.length; k++) {
                var value = header[keys[k]];
                if (!value || String(value).trim() === "") {
                    continue;
                }
                var field = document.createElement("div");
                field.className = "rc-field";
                var label = document.createElement("label");
                label.textContent = keys[k];
                var span = document.createElement("span");
                span.textContent = value;
                field.appendChild(label);
                field.appendChild(span);
                grid.appendChild(field);
            }
        }

        if (grid.children.length > 0) {
            container.appendChild(grid);
        }
    }

    function distinctSorted(entries, field) {
        var seen = {};
        var values = [];
        for (var i = 0; i < entries.length; i++) {
            var value = entries[i][field];
            if (value && !seen[value]) {
                seen[value] = true;
                values.push(value);
            }
        }
        values.sort();
        return values;
    }

    function applyFilters(entries, state) {
        var search = state.search.trim().toLowerCase();
        var fromTs = state.dateFrom ? Math.floor(new Date(state.dateFrom).getTime() / 1000) : null;
        var toTs = state.dateTo ? Math.floor(new Date(state.dateTo).getTime() / 1000) : null;

        return entries.filter(function (entry) {
            if (state.category && entry.category !== state.category) {
                return false;
            }
            if (state.level && entry.level !== state.level) {
                return false;
            }
            if (fromTs !== null && (!entry.date || entry.date < fromTs)) {
                return false;
            }
            if (toTs !== null && (!entry.date || entry.date > toTs)) {
                return false;
            }
            if (search) {
                var haystack = ((entry.messageCode || "") + " " + (entry.message || "")).toLowerCase();
                if (haystack.indexOf(search) === -1) {
                    return false;
                }
            }
            return true;
        });
    }

    function buildToolbar(categories, levels, onChange) {
        var toolbar = document.createElement("div");
        toolbar.className = "rc-toolbar";

        var categorySelect = document.createElement("select");
        categorySelect.appendChild(new Option("Toutes les catégories", ""));
        for (var c = 0; c < categories.length; c++) {
            categorySelect.appendChild(new Option(categories[c], categories[c]));
        }
        categorySelect.addEventListener("change", function () {
            onChange({ category: categorySelect.value });
        });
        toolbar.appendChild(wrapField("Catégorie", categorySelect));

        var levelSelect = document.createElement("select");
        levelSelect.appendChild(new Option("Tous les niveaux", ""));
        for (var l = 0; l < levels.length; l++) {
            levelSelect.appendChild(new Option(levels[l], levels[l]));
        }
        levelSelect.addEventListener("change", function () {
            onChange({ level: levelSelect.value });
        });
        toolbar.appendChild(wrapField("Niveau", levelSelect));

        var searchInput = document.createElement("input");
        searchInput.type = "search";
        searchInput.placeholder = "Code ou message…";
        var searchTimer = null;
        searchInput.addEventListener("input", function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                onChange({ search: searchInput.value });
            }, 150);
        });
        toolbar.appendChild(wrapField("Recherche", searchInput));

        var fromInput = document.createElement("input");
        fromInput.type = "datetime-local";
        fromInput.addEventListener("change", function () {
            onChange({ dateFrom: fromInput.value });
        });
        toolbar.appendChild(wrapField("Du", fromInput));

        var toInput = document.createElement("input");
        toInput.type = "datetime-local";
        toInput.addEventListener("change", function () {
            onChange({ dateTo: toInput.value });
        });
        toolbar.appendChild(wrapField("Au", toInput));

        return toolbar;
    }

    function wrapField(labelText, control) {
        var label = document.createElement("label");
        label.appendChild(document.createTextNode(labelText));
        label.appendChild(control);
        return label;
    }

    var TABLE_COLUMNS = [
        { label: "Date", get: function (entry) { return formatDate(entry.date); } },
        { label: "Catégorie", get: function (entry) { return entry.category || "—"; } },
        { label: "Niveau", get: function (entry) { return entry.level || "—"; } },
        { label: "Source", get: function (entry) { return entry.source || "—"; } },
        { label: "Code", get: function (entry) { return entry.messageCode || "—"; } },
        { label: "Clé", get: function (entry) { return entry.key || "—"; } },
        { label: "Message", get: function (entry) { return entry.message || "—"; } },
    ];

    function renderTable(container, entries) {
        var table = document.createElement("table");
        table.className = "rc-table";

        var thead = document.createElement("thead");
        var headRow = document.createElement("tr");
        for (var c = 0; c < TABLE_COLUMNS.length; c++) {
            var th = document.createElement("th");
            th.textContent = TABLE_COLUMNS[c].label;
            headRow.appendChild(th);
        }
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = document.createElement("tbody");
        for (var i = 0; i < entries.length; i++) {
            var row = document.createElement("tr");
            for (var col = 0; col < TABLE_COLUMNS.length; col++) {
                var td = document.createElement("td");
                td.textContent = TABLE_COLUMNS[col].get(entries[i]);
                row.appendChild(td);
            }
            tbody.appendChild(row);
        }
        table.appendChild(tbody);

        container.innerHTML = "";
        container.appendChild(table);
    }

    function renderPagination(container, page, totalPages, onPageChange) {
        container.innerHTML = "";
        if (totalPages <= 1) {
            return;
        }

        var prev = document.createElement("button");
        prev.type = "button";
        prev.className = "rc-button";
        prev.textContent = "← Précédent";
        prev.disabled = page <= 1;
        prev.addEventListener("click", function () {
            onPageChange(page - 1);
        });
        container.appendChild(prev);

        var status = document.createElement("span");
        status.className = "rc-pagination__status";
        status.textContent = "Page " + page + " sur " + totalPages;
        container.appendChild(status);

        var next = document.createElement("button");
        next.type = "button";
        next.className = "rc-button";
        next.textContent = "Suivant →";
        next.disabled = page >= totalPages;
        next.addEventListener("click", function () {
            onPageChange(page + 1);
        });
        container.appendChild(next);
    }

    /** Formats a Unix-seconds timestamp in the viewer's own local timezone (no server timezone is sent along), matching the "d/m/Y H:i:s" shape this tool has always used. */
    function formatDate(unixSeconds) {
        if (!unixSeconds) {
            return "—";
        }
        var date = new Date(unixSeconds * 1000);
        function pad(n) {
            return n < 10 ? "0" + n : String(n);
        }
        return pad(date.getDate()) + "/" + pad(date.getMonth() + 1) + "/" + date.getFullYear()
            + " " + pad(date.getHours()) + ":" + pad(date.getMinutes()) + ":" + pad(date.getSeconds());
    }

    // -- Shared UI helpers -------------------------------------------------

    function setStatus(container, message) {
        var p = document.createElement("p");
        p.className = "rc-field--help";
        p.textContent = message || "…";
        container.innerHTML = "";
        container.appendChild(p);
    }

    function showError(container, message) {
        var div = document.createElement("div");
        div.className = "rc-portal-alert rc-portal-alert--error";
        div.textContent = message;
        container.innerHTML = "";
        container.appendChild(div);
    }

    function errorMessage(err) {
        var base = "Ce fichier n’a pas pu être analysé dans le navigateur";
        if (err && err.message) {
            return base + " : " + err.message;
        }
        return base + ".";
    }
})();
