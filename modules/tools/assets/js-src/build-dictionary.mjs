// Regenerates ../data/kuka-message-dictionary.json from a raw KUKA message
// dictionary export (a Jet/Access .mdb with the Items+Messages+Languages
// schema — the file KUKA itself calls something like "Kuka_tab.mdb").
// Only needs re-running if a newer/more complete export becomes available.
//
// What this does and why: the source .mdb carries every message in every
// KUKA-supported language (in one real export: 8217 Items × up to 25
// Languages = ~128k Messages rows, ~11 MB) — but this tool only ever shows
// one language per message (French, falling back to English then German —
// see DICTIONARY_LANGUAGE_PRIORITY in message-logs.js, kept in sync with
// the constant below). Flattening ahead of time cuts the shipped asset to
// under 1 MB and means the browser never has to open a multi-megabyte
// Jet/Access file (or bundle mdb-reader's Buffer/BigInt polyfills) just to
// load static translations — a plain JSON fetch() is all it takes.
//
// The output has two maps: `byModuleKey` ("Module#KeyString" → text, exact)
// and `byKey` (bare "KeyString" → text, first module wins on a collision —
// ~7% of KeyStrings in a real export are reused across more than one
// module). The fallback exists because a `.evt` record's own embedded
// message sub-format sometimes omits its module (see parseEvtMessage() in
// MessageLogProcessor.php), leaving no way to form the exact key; a
// same-named key almost always means the same message regardless of
// module, so the ambiguity risk is worth the extra coverage.
//
// Usage:
//   cd modules/tools/assets/js-src && npm install
//   node build-dictionary.mjs /path/to/Kuka_tab.mdb
// — overwrites ../data/kuka-message-dictionary.json.

import MDBReader from "mdb-reader";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const DICTIONARY_LANGUAGE_PRIORITY = [12, 9, 7]; // French, English, German — see message-logs.js

const sourcePath = process.argv[2];
if (!sourcePath) {
    console.error("usage: node build-dictionary.mjs /path/to/Kuka_tab.mdb");
    process.exit(1);
}

const reader = new MDBReader(fs.readFileSync(sourcePath));
const tableNames = reader.getTableNames();
if (tableNames.indexOf("Items") === -1 || tableNames.indexOf("Messages") === -1) {
    console.error(`${sourcePath} doesn't look like a KUKA message dictionary (no Items/Messages tables found).`);
    process.exit(1);
}

const items = reader.getTable("Items").getData();
const messages = reader.getTable("Messages").getData();

const textsByKeyId = {};
for (const row of messages) {
    if (!row || !row.String) {
        continue;
    }
    textsByKeyId[row.Key_id] = textsByKeyId[row.Key_id] || {};
    textsByKeyId[row.Key_id][row.Language_id] = row.String;
}

const byModuleKey = {};
const byKey = {};
let matched = 0;
for (const item of items) {
    if (!item || !item.Module || !item.KeyString) {
        continue;
    }
    const texts = textsByKeyId[item.Key_id];
    if (!texts) {
        continue;
    }

    let text = null;
    for (const languageId of DICTIONARY_LANGUAGE_PRIORITY) {
        if (texts[languageId]) {
            text = texts[languageId];
            break;
        }
    }
    if (!text) {
        for (const languageId of Object.keys(texts)) {
            if (texts[languageId]) {
                text = texts[languageId];
                break;
            }
        }
    }

    if (text) {
        byModuleKey[`${item.Module}#${item.KeyString}`] = text;
        if (!(item.KeyString in byKey)) {
            byKey[item.KeyString] = text;
        }
        matched++;
    }
}

const outPath = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "data", "kuka-message-dictionary.json");
fs.writeFileSync(outPath, JSON.stringify({ byModuleKey, byKey }));

console.log(`Wrote ${matched} entries (of ${items.length} Items rows) to ${outPath} (${fs.statSync(outPath).size} bytes).`);
