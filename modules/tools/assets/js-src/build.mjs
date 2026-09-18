// Bundles kuka-mdb.js (+ mdb-reader and its browser polyfills) into the
// single file ToolsPages enqueues: ../js/kuka-mdb.bundle.js.
//
// Run after editing kuka-mdb.js:
//   npm install
//   npm run build

import * as esbuild from "esbuild";
import { polyfillNode } from "esbuild-plugin-polyfill-node";

await esbuild.build({
    entryPoints: ["kuka-mdb.js"],
    bundle: true,
    platform: "browser",
    format: "iife",
    target: ["es2021"],
    outfile: "../js/kuka-mdb.bundle.js",
    plugins: [polyfillNode({})],
    minify: true,
    legalComments: "none",
});

console.log("Built ../js/kuka-mdb.bundle.js");
