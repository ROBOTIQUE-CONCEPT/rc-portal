// Bundles message-logs.js (+ mdb-reader and its browser polyfills — the
// .evt parser needs no library at all, see that file) into the single file
// ToolsPages enqueues: ../js/message-logs.bundle.js.
//
// Run after editing message-logs.js:
//   npm install
//   npm run build

import * as esbuild from "esbuild";
import { polyfillNode } from "esbuild-plugin-polyfill-node";

await esbuild.build({
    entryPoints: ["message-logs.js"],
    bundle: true,
    platform: "browser",
    format: "iife",
    target: ["es2021"],
    outfile: "../js/message-logs.bundle.js",
    plugins: [polyfillNode({})],
    minify: true,
    legalComments: "none",
});

console.log("Built ../js/message-logs.bundle.js");
