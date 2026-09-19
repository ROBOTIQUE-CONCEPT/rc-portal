# AGENTS.md — rc-portal/modules

This file governs everything under `modules/`. It supplements, and does not
duplicate, `modules/README.md`'s seven rules (isolation, no cross-module
table access, no direct ERP HTTP, cross-domain reads via RC Core contracts,
cross-domain notifications via events, independent app/schema versioning,
old versions live in Git not parallel runtime folders) — read that file
too, it's short. This file adds the mechanics, the current enforcement
reality, and an evidence-based map of which modules actually have code
behind them today.

## Repository purpose

Each directory under `modules/` is one isolated business bounded context,
embedded into (but not owned by) the RC Portal runtime. A module owns its
own domain logic, persistence, and UI content; RC Portal owns only
discovery, routing, and the shared lifecycle contract.

## Architecture boundaries

- A module directory is a bounded context: its own domain logic, its own
  persistence, its own migrations (schema version is independent of the
  module's own semantic version — `ModuleDescriptor` carries both
  `version` and `schemaVersion` as separate fields).
- A module may use RC Portal's public primitives (`RC\Portal\Http\*`,
  `RC\Portal\Module\*`) and RC Core's contracts
  (`WPRC\Core\Contracts\*`, reached either via a direct `use` — as
  `products` does for its ERP contracts — or via the global bridge
  functions `rc_register_capabilities()`/`rc_register_ui_page()` that
  every module uses).
- A module never imports another module's namespace
  (`RC\Portal\Modules\{OtherModule}\*`) and never reads or writes another
  module's database tables/CPTs directly. This holds in code today for
  every module that exists (confirmed by grep — every `use
  RC\Portal\Modules\...` statement in the repo references only the
  importing file's own module) and is mechanically checked by
  `rc-portal/tools/preflight.php`'s cross-module-import regex.
- **Presentation (decided 2026-09-19, no exception):** a module never
  builds HTML for its pages. The target contract is RC Core's declarative
  `PageDefinition`/`TableDefinition` data (`rc-core/docs/PORTAL-UI.md`) —
  register that data with the UI Registry and let Portal/the theme render
  it. This is **not yet implemented**: `products` and `tools` both
  currently return a pre-built HTML string from their page's `renderer`
  callback (`RouteContext::$pageHtml`), which the theme outputs verbatim.
  Do not copy that shape for a new module page — it's migration debt being
  carried forward, not the pattern to follow. A module may still register
  and enqueue its own page-specific JS/CSS for its own pages (e.g.
  `modules/tools/assets/js/message-logs.bundle.js`); that's unaffected by
  this rule.
- Cross-domain reads go through an RC Core contract. The working example
  in this codebase: `products` publishes
  `ProductCatalogProviderInterface` into RC Core's `ServiceRegistry`
  (`rc_core()->services()->instance(ProductCatalogProviderInterface::class, ...)`)
  so a future module could read enriched product data without importing
  `products` directly. Follow this pattern — publish a Core contract
  implementation, don't import the other module.
- Cross-domain **notifications**: `modules/README.md` names an "RC Core
  event bus" as the mechanism. No such dedicated bus currently exists in
  `rc-core` — there is no `Events/` namespace or event-dispatch service
  there today, only standard WordPress action hooks (e.g. Core's own
  `rc_core_logged` action). Until Core defines a formal event contract, a
  module that needs to notify another should fire a plain, clearly-named
  WordPress action hook from the owning module and document it — don't
  invent a bespoke pub/sub mechanism of your own, and don't block on a
  bus that doesn't exist yet. This is a real gap between
  `modules/README.md`'s stated rule and current implementation; it hasn't
  mattered in practice yet because no two modules currently need to
  notify each other (see the module map below).
- Controllers/adapters that touch WordPress (hooks, `$_POST`, rewrite
  handling) stay thin; business validation stays inside the module's own
  domain classes, not in the WordPress-facing `Ui`/controller layer. The
  existing modules mostly follow this (e.g. `products`'s domain objects —
  `ProductRecord`, `ProductSpecs`, `ProductTranslations`,
  `ProductFamilies` — are separate from `ProductsPages.php`'s
  WordPress-facing rendering/dispatch code) — keep new work in the same
  shape rather than putting validation logic inline in a page-render
  method.

## Mandatory rules

- Every route or AJAX action a module registers declares and verifies a
  capability (`current_user_can()`), via `rc_register_ui_page(...,
  capability: 'rc_{module}_{verb}', ...)` at minimum, and — for anything
  that mutates state — an explicit re-check inside the handler too (don't
  rely on registration-time declaration alone for a write path).
- Every mutation requires capability **and** a scoped nonce **and**
  server-side validation of the submitted data — follow the existing
  per-action nonce naming pattern (`'rc_{module}_{action}_' . $id`) rather
  than one nonce per page.
- Database access uses WordPress APIs (`WP_Query`, `wp_insert_post`,
  `update_post_meta`, etc.) or `$wpdb` with prepared statements
  (`$wpdb->prepare()`) — never raw string-interpolated SQL. `products` is
  the reference example for both patterns (CPT/postmeta via WP APIs, plus
  a prepared raw query for its dashboard's search/sort/pagination).
- Output is escaped for its context (`esc_html()`, `esc_attr()`,
  `esc_url()`, `wp_kses()` for anything that must carry limited HTML).
  This holds throughout the existing modules; keep it that way.
- User-supplied files are never trusted by extension/MIME claim alone and
  are never written under the WordPress public uploads directory by
  default. The established pattern (`modules/tools`) is: validate via
  content sniffing where relevant, read from PHP's own upload temp path,
  process entirely in memory, delete the temp file when done — no
  business file this platform handles is persisted anywhere today. If a
  future module genuinely needs to retain a user file, that's a real
  architecture decision (storage location, retention, access control) —
  don't default into writing it under `wp-content/uploads/` without
  raising that decision first.
- A module never calls `wp_remote_*()`, `switch_to_blog()`, or references
  `$wpdb->base_prefix` — all three are checked by
  `rc-portal/tools/preflight.php` and will fail the build.

## Security rules

(See also `rc-portal/AGENTS.md`'s Security rules, which apply to every
module equally.)

- Capability names follow `rc_{module}_{verb}` (e.g. `rc_products_read`,
  `rc_tools_use`) and are registered once, in the module's `register()`,
  via `rc_register_capabilities($descriptor->id, $descriptor->capabilities)`
  — never registered ad hoc elsewhere in the module.
- An AJAX handler registers only the logged-in variant
  (`wp_ajax_{action}`) unless there's a specific, reviewed reason for a
  `nopriv` counterpart — none of today's modules use `nopriv`.

## Module map

Derived from actual code and `CHANGELOG.md` — not assumed. Re-derive this
table rather than trusting it blindly if it's been a while since it was
last checked against the code.

| Module | State | Evidence |
|---|---|---|
| `products` | **Implemented** | Real CPT (`rc_product`) + postmeta persistence (`ProductRepository`, ~400 lines: `wp_insert_post`, `WP_Query`, prepared raw search/sort/pagination query). Full domain layer (`ProductRecord`, `ProductSpecs`, `ProductTranslations`, `ProductFamilies`, per-family fiche schemas). ERP-integrated via RC Core contracts, publishing `ProductCatalogProviderInterface` back into Core. ~1070-line UI (`ProductsPages.php`): dashboard, per-product fiche, tabbed i18n editor, live-permuting family form, read-only Axonaut card. A WP-CLI command (`HydrateCommand`). Dominates most feature-level `CHANGELOG.md` entries since `0.3.0-alpha6`. |
| `tools` | **Implemented** (narrow, single-tool scope by design) | Real parsing logic for KUKA controller backup archives (`KukaArchiveAnalyzer`, ~1070 lines) and message-log translation (`MessageLogProcessor`/`MessageLogAjaxHandler` + a bundled ~840 KB translation dictionary). Deliberately **no persistence** — a design choice, not a gap (see Mandatory rules above). ~890-line tabbed UI (`ToolsPages.php`). Dominates `CHANGELOG.md` entries `0.3.0-alpha12` through the current release. |
| `maintenance` | **Foundation only** | `MaintenanceModule.php`, ~68 lines: descriptor + capability/UI-page registration + an empty `boot()` + a single `renderDashboard()` that echoes a generic "coming soon" placeholder block. No domain classes, no persistence, no other files. Docblock: "Placeholder pending the Maintenance conception (Phase 5)." |
| `inventory` | **Foundation only** | Same shape as `maintenance` (~65 lines, identical placeholder pattern, no domain/persistence code). |
| `leads` | **Foundation only** | Same shape again (~66 lines). Docblock: "Placeholder pending the Leads conception (Phase 3)." |

Two of five modules (`products`, `tools`) carry all real business logic
today. The other three are near-identical scaffolds — don't assume any
domain/persistence pattern exists in `maintenance`, `inventory`, or
`leads` beyond what's listed here; there is nothing to extend yet, only a
registration shell to build on.

## Change workflow

- Adding a module: create `modules/{name}/module.php` returning an
  `EmbeddedModuleInterface` implementation; register capabilities and any
  UI Registry pages in `register()`; keep real work (including anything
  that touches other services) in `boot()`, called after every module has
  registered. See any existing module's `module.php` for the shape.
- Before adding a cross-module read, check whether RC Core already has (or
  should have) a contract for it — don't import another module's
  namespace as a shortcut, even "just this once."
- Before writing a new capability name, check it doesn't collide with an
  existing one and that it follows `rc_{module}_{verb}`.

## Validation

- `php -l` every changed file.
- `php rc-portal/tools/preflight.php` from the plugin root (see
  `rc-portal/AGENTS.md` Validation for exactly what it checks) — this is
  the tool that actually enforces module isolation and the ERP/multisite/
  file-storage prohibitions for everything under `modules/`.
- `modules/tools` specifically: rebuild its JS bundle after any
  `assets/js-src/*.js` change (`npm install && npm run build` in that
  directory) before considering a change to it done.

## Versioning and documentation

- A module's own `version` (in its `ModuleDescriptor`) and its
  `schemaVersion` are independent — bump each only when that specific
  thing changes (code behavior vs. stored-data shape).
- Note: `ModuleDescriptor::$status` (default `'foundation'`) exists as a
  field but nothing in the codebase currently reads or acts on it — it's
  not a reliable signal of a module's real state. Use the module map above
  (or re-derive it from code + CHANGELOG) instead of trusting this field.

## Definition of done

- `php -l` clean; `rc-portal/tools/preflight.php` GREEN.
- No new cross-module namespace import; no direct read/write of another
  module's table or CPT.
- Every new/changed route or mutation has a capability check; every
  mutation additionally has a verified nonce and server-side validation.
- No new file persisted under the WordPress public uploads directory.
- `CHANGELOG.md` updated for the change, and this file's module map
  updated if the change moves a module from one state to another (e.g.
  foundation → implemented).

## Read before changing

| Touching… | Read first |
|---|---|
| Any module's isolation boundary | This file's Architecture boundaries + `modules/README.md` |
| A specific module's actual state before extending it | This file's Module map — verify it's still accurate first |
| ERP access from a module | `rc-core/AGENTS.md`'s ERP section, then `products`'s `ProductRepository`/`ProductsCatalogAdapter` as the only working example |
| Cross-module notification | This file's note on the (currently unimplemented) "event bus" rule in `modules/README.md` |
| File uploads / user-provided files | `modules/tools`'s existing no-persistence pattern before assuming a different one is fine |
| A module's page/UI content | `rc-core/docs/PORTAL-UI.md` — the decided, no-exception target (`PageDefinition`/`TableDefinition`); don't copy `products`/`tools`'s current hand-built-HTML `renderer` shape into new work |
