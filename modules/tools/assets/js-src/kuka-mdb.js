/**
 * Reads the KUKA message-log Jet/Access database(s) (`KukaLog.mdb` and its
 * `.bkp`/`.tmp` siblings) entirely in the browser using `mdb-reader`
 * (https://www.npmjs.com/package/mdb-reader, pure JS/TS, MIT), then posts
 * just the raw table rows to a small WordPress AJAX endpoint
 * (MessageLogAjaxHandler) that applies the already-validated PHP-side logic
 * (the empirically reverse-engineered FILETIME conversion, the corrupted-row
 * filter, sorting/capping, and HTML rendering) and returns a ready-to-inject
 * HTML fragment.
 *
 * This deliberately replaces an earlier server-side approach that shelled
 * out to the `mdbtools` command-line utility, which is commonly unavailable
 * on shared hosting (no `shell_exec()`/`mdb-export` binary). A pure-JS
 * reader running in the visitor's own browser works identically regardless
 * of what the server can do, and needs no server-side native dependency at
 * all.
 *
 * The bytes for each candidate database are already embedded in the page
 * (base64, in a `<script type="text/plain">` sibling of each
 * `[data-rc-kuka-mdb]` card) by ToolsPages::renderMessageLogs() — nothing is
 * fetched separately, and nothing here is ever written to disk; the raw
 * bytes exist only in page/script memory for the duration of the analysis.
 *
 * Rebuilding this bundle (only needed if this file changes):
 *   cd modules/tools/assets/js-src && npm install && npm run build
 * — produces ../js/kuka-mdb.bundle.js, which is what ToolsPages enqueues.
 */

import MDBReader from "mdb-reader";
import { Buffer } from "buffer";

(function () {
    "use strict";

    ready(function () {
        var configEl = document.getElementById("rc-tools-kuka-mdb-config");
        if (!configEl) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            return;
        }

        var cards = document.querySelectorAll("[data-rc-kuka-mdb]");
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
        var dataEl = card.querySelector(".rc-kuka-mdb__data");
        var body = card.querySelector(".rc-kuka-mdb__body");
        if (!dataEl || !body) {
            return;
        }

        var fileName = dataEl.getAttribute("data-filename") || "";
        var size = parseInt(dataEl.getAttribute("data-size") || "0", 10) || 0;
        var lastModified = parseInt(dataEl.getAttribute("data-lastmodified") || "0", 10) || 0;
        var base64 = (dataEl.textContent || "").trim();

        var extracted;
        try {
            extracted = extractPayload(base64, config.logTables || []);
        } catch (err) {
            showError(body, errorMessage(err));
            return;
        }

        var payload = {
            nonce: config.nonce,
            fileName: fileName,
            size: size,
            lastModified: lastModified,
            header: extracted.header,
            tables: extracted.tables,
        };

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

    function extractPayload(base64, logTables) {
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

        return { header: header, tables: tables };
    }

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
        var base = "La base n’a pas pu être analysée dans le navigateur";
        if (err && err.message) {
            return base + " : " + err.message;
        }
        return base + ".";
    }
})();
