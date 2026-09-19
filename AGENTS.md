# AGENTS.md — rc-portal

## Repository purpose

RC Portal is the business runtime plugin for `my`, the private application
site. It owns routing, authentication/session boundaries for that site, the
embedded-module lifecycle, and — jointly with RC Portal Theme — the
presentation layer business modules render into. It depends on RC Core for
every cross-cutting concern (capabilities, ERP, logging, multisite, the UI
Registry) and never re-implements one of those itself.

Current version: `0.3.0-alpha17` (`rc-portal.php` header and the
`RC_PORTAL_VERSION` constant — in agreement). Requires RC Core
`>= 0.6.0-alpha10` (`RC_PORTAL_MIN_CORE_VERSION`, checked with
`version_compare()` at activation and again at boot via `CoreBridge`).
`RC_PORTAL_UI_API_VERSION = 1` — RC Portal Theme hard-pins to exactly `1`
(equality, not `>=`; see `rc-portal-theme/AGENTS.md`), so bumping this is a
coordinated two-repo change, not a Portal-only one.

## Architecture boundaries

- RC Portal depends on RC Core (`RC\Portal\* → WPRC\Core\*`, one direction
  only) and never the reverse.
- RC Portal never imports a business-module namespace directly except
  through the module lifecycle contract (`EmbeddedModuleInterface`) — the
  runtime doesn't need to know a given module's internal classes to boot,
  register capabilities for, or route to it.
- Embedded modules (under `modules/`) may depend on RC Core's contracts for
  everything, including presentation: a module supplies semantic
  page/table/form data (`rc-core/docs/PORTAL-UI.md`'s
  `PageDefinition`/`TableDefinition`) into RC Core's UI Registry — it does
  not need, and (see below) should not lean on, a Portal-specific rendering
  surface to do this. A module may still call RC Portal's own runtime/
  routing surface (`RC\Portal\Module\*`) for the parts of the lifecycle
  contract that are genuinely Portal's (registration, routing, capability
  wiring) — see `modules/AGENTS.md` for the module-side rules in full. RC
  Portal itself never depends on any individual module.
- **Namespace**: `RC\Portal\*` for the runtime, `RC\Portal\Modules\{Module}\*`
  per embedded module. Note this is a *different* root prefix than RC
  Core's `WPRC\Core\*` — a known, unresolved inconsistency across the two
  repos, not itself covered by either 2026-09-19 decision. Don't "fix" it
  unilaterally inside a Portal-only change.
- **Presentation ownership (decided 2026-09-19, no exception):** a module
  never builds HTML. It supplies semantic data to RC Core's `PageDefinition`/
  `TableDefinition` contract (`rc-core/docs/PORTAL-UI.md`); RC Portal (or
  the theme, through Portal) is the only place markup gets produced. This is
  **not yet implemented**: `products` and `tools` both currently supply a
  page's content as a pre-built HTML string through a `renderer` callback
  (`RouteContext::$pageHtml`), which the theme renders verbatim. Treat that
  as migration debt to fix, not a working pattern to extend — a new module
  page should not add another hand-built HTML renderer if it can be
  avoided. Separately, a module *may* still ship and enqueue its own
  page-specific JS/CSS for its own pages only (e.g.
  `modules/tools/assets/js/message-logs.bundle.js`) — that's a normal
  implementation detail of that module's UI, not the same thing as building
  its page's HTML, and isn't affected by this decision.

## Repository map

- `rc-portal.php` — plugin header/bootstrap; activation hook hard-blocks
  network-wide activation (`Network: false`) and blocks activation if RC
  Core is missing or below `RC_PORTAL_MIN_CORE_VERSION`.
- `src/Http/PortalRouter.php` — rewrite rules + `rc_portal_route` query var;
  dispatch on `template_redirect` priority 0. Confirmed live routes:
  - `/` → dashboard.
  - `/login/` → `prepareLoginRequest()` (native `wp_signon()`, nonce
    `rc_portal_login`, Turnstile via RC Core's `LoginProtection`).
  - `/{module}/` and sub-routes → resolved first against RC Core's UI
    Registry (`resolveUiPage()`), then against `ModuleCatalog` +
    `EmbeddedModuleInterface` with a capability check (403/200/404).
  - `PortalRouter::baseSlug()` is a deprecated compatibility shim that
    returns `''` — there is **no** live `/portal` prefix anywhere. Don't
    reintroduce one; don't be confused by old CHANGELOG entries describing
    its removal.
- `src/Module/EmbeddedModuleInterface.php` + `ModuleDescriptor` — the
  module lifecycle contract (`descriptor()`, `register()`, `boot()`). See
  `modules/AGENTS.md`.
- `src/Module/ModuleCatalog.php` — discovers modules by globbing
  `modules/*/module.php`; each must `return` an `EmbeddedModuleInterface`.
- `src/Security/` — `PrivateSiteGuard` (redirects anonymous requests to
  `/login/`, forces REST to 401 unless logged in or an explicit
  `rc_portal_allow_anonymous_rest_request` filter opts in, sets
  `noindex,nofollow,noarchive` + `Cache-Control: private, no-store`
  site-wide, disables XML-RPC) and `AdminAccessGuard` (blocks non-super-admin
  `wp-admin` access, redirects to the Portal home, hides the admin bar).
- `modules/` — embedded business modules; see `modules/AGENTS.md` for the
  per-module rules and the current implementation-state map.
- `tools/preflight.php` — see Validation.
- `uninstall.php` — cleanup on plugin deletion.
- `docs/ARCHITECTURE.md` — Portal's own architecture doc; lists `tools`
  alongside the other four modules and carries the decided (2026-09-19, no
  exception) presentation rule in its "Presentation rule" section. It still
  doesn't reflect real per-module *implementation* state (foundation-only
  vs. built) — for that, use `modules/AGENTS.md`'s module map instead, which
  is derived from the actual code and CHANGELOG.
- `docs/DEPLOYMENT.md` — deployment/WP Pusher steps. Also version-stale
  (still headed `0.3.0-alpha2`) and has a minor internal inconsistency
  (omits `/leads/` from one step's list while including it in another) —
  read it for the deploy mechanics, not for current version numbers.
- `README.md` — version header (`0.3.0-alpha2`) and module-count/"no
  business tables yet" description are both stale relative to actual code
  (`products` has real CPT persistence; `tools` is fully implemented). Left
  as-is in this pass because the staleness goes beyond the version number
  (see the accompanying report) — a real content refresh is recommended as
  separate follow-up work, not folded into this documentation pass.

## Mandatory rules

- Every ERP read/write goes through `rc_core()->erp()->get(ContractInterface::class)`.
  Never call `wp_remote_get()`/`wp_remote_post()` from Portal or a module —
  `tools/preflight.php` checks for this and will fail the build.
- Never call `switch_to_blog()` directly. RC Portal is a single-site
  (non-network) plugin; if a future need for cross-site reads ever arises,
  it goes through RC Core's `SiteContext`, not a direct call here —
  checked by `tools/preflight.php`.
- Never reference `$wpdb->base_prefix` here — Portal and its modules own
  only site-local data.
- Never write a business/user file under the WordPress public uploads
  directory (`wp_upload_dir()`, `move_uploaded_file()` to a public path).
  The existing pattern (see `modules/tools`) is: read from PHP's own
  upload temp path, process in memory, `unlink()` when done, write
  nothing to disk. `tools/preflight.php` checks for `wp_upload_dir(`.
- Every mutation (anything that writes state) requires a capability check
  (`current_user_can()`) **and** a scoped nonce verified server-side —
  never one without the other. Follow the existing per-action nonce
  pattern (e.g. `'rc_products_save_' . $record->uid`) rather than one
  nonce for a whole page.
- There are currently zero `register_rest_route()` calls anywhere in this
  repo. If you add a REST endpoint, it must have a `permission_callback`
  that checks a capability — never `__return_true`. If you add a
  `wp_ajax_*` handler, register only the logged-in variant
  (`wp_ajax_{action}`) unless the endpoint is genuinely meant to be public
  — RC Portal's private-by-default posture (`PrivateSiteGuard`) assumes
  everything here requires a session.
- A module never imports another module's namespace
  (`RC\Portal\Modules\{OtherModule}\*`). Cross-module reads go through RC
  Core contracts (published via `rc_core()->services()`, as `products`
  does with `ProductCatalogProviderInterface`) — see `modules/AGENTS.md`.
- The "Projection WooCommerce" label in `modules/products`'s UI is a local
  field name only — there is no live WooCommerce integration anywhere in
  this repo. Don't infer WooCommerce is running on `my` from that string,
  and don't add a real WooCommerce dependency without checking with a
  human first — it isn't part of the current architecture.

## Security rules

- `PrivateSiteGuard` makes `my` private-by-default: any unauthenticated
  request is redirected to `/login/`, and REST requests 401 unless logged
  in (or the anonymous-REST filter is explicitly enabled for a specific,
  reviewed reason). Don't add a route or endpoint that bypasses this guard
  without a documented, deliberate reason.
- `AdminAccessGuard` keeps non-super-admins out of `/wp-admin/` entirely,
  redirecting them to the Portal home instead. Don't build a feature that
  assumes a regular Portal user will ever see wp-admin.
- Authentication is native WordPress (`wp_signon()`, auth cookies) through
  the custom `/login/` route — no PHP sessions, no parallel auth mechanism.
- Capability checks happen in two places for UI Registry pages
  (registration-time declaration *and* `PortalRouter::resolve()`'s own
  `current_user_can()` re-check before rendering) — keep both when adding a
  new page; don't remove the router-level check on the assumption
  registration-time declaration is enough.

## Change workflow

- Read `modules/AGENTS.md` before touching anything under `modules/` — it
  has the per-module isolation rules and the current implementation-state
  map (which modules are real vs. foundation-only placeholders).
- A routing change (`src/Http/PortalRouter.php`) must preserve the three
  confirmed live route shapes (`/`, `/login/`, `/{module}/...`) unless a
  human has explicitly signed off on a routing redesign — this is exactly
  the kind of "new architectural decision" this documentation pass was
  told not to make unilaterally.
- A change to `RC_PORTAL_MIN_CORE_VERSION` or `RC_PORTAL_UI_API_VERSION`
  needs a matching check against `rc-core`'s actual version and
  `rc-portal-theme`'s `RC_PORTAL_THEME_UI_API_VERSION` respectively —
  these are runtime `version_compare()`/equality gates, not just labels.

## Validation

- `php -l` every changed file.
- `php tools/preflight.php` — checks, in order: PHP lint repo-wide; string
  scans for `switch_to_blog(`, `$wpdb->base_prefix`, `wp_remote_get(`,
  `wp_remote_post(`, `wp_upload_dir(`; a cross-module-import regex over
  `modules/<owner>/**` (flags a `use RC\Portal\Modules\{X}\` where `X` ≠ the
  owning module's own directory name); and a forbidden-directory check
  (`assets/`, `templates/`, `src/Presentation` are forbidden only at the
  **plugin root** — a module's own `modules/{name}/assets/` subdirectory,
  e.g. `modules/tools/assets/`, is not flagged and is a normal place for a
  module's own page-specific JS/CSS build output).
- This is a plain substring/regex scanner, not an AST parser — same
  caveats as `rc-core`'s preflight tool (can't see through indirection or a
  dynamically-built string).
- No PHPUnit/test suite exists in this repo. `modules/tools` has an
  ad hoc, non-committed jsdom+PHP end-to-end test harness that gets
  rebuilt by hand each time it's needed (see recent CHANGELOG entries for
  that module) — there is no standing `tests/` directory to run.
- For `modules/tools`'s JS bundle specifically: `cd
  modules/tools/assets/js-src && npm install && npm run build` regenerates
  `../js/message-logs.bundle.js` — run this after any change to
  `assets/js-src/*.js` before considering the change done; the repo does
  not auto-rebuild it.

## Versioning and documentation

- Bump `rc-portal.php`'s `Version:` header and `RC_PORTAL_VERSION` together
  (they agree today). Update `RC_PORTAL_MIN_CORE_VERSION` only when a
  change genuinely requires a newer Core, and check it against Core's
  actual current version before releasing.
- Add a `CHANGELOG.md` entry for every release — it is the most reliable
  current-state record in this repo (more reliable than `README.md` or
  `docs/ARCHITECTURE.md`, both of which have drifted from actual code; see
  their entries in Repository map above).

## Definition of done

- `php -l` clean; `php tools/preflight.php` GREEN.
- Every new/changed mutation path has both a capability check and a
  verified nonce.
- No new `wp_remote_*`, `switch_to_blog(`, `$wpdb->base_prefix`, or
  `wp_upload_dir(` call anywhere in the diff.
- No new cross-module `use RC\Portal\Modules\{OtherModule}\*` import.
- If `modules/tools`'s JS source changed, the bundle has been rebuilt.
- `CHANGELOG.md` updated; version constants bumped together if
  release-worthy.

## Read before changing

| Touching… | Read first |
|---|---|
| Routing (`src/Http`) | This file's Repository map (confirmed live routes) — `docs/ARCHITECTURE.md` is stale here |
| Module lifecycle, a specific module | `modules/AGENTS.md` (isolation rules + module state map) |
| ERP-backed data (`products`) | `rc-core/AGENTS.md`'s ERP section, then `modules/products` itself |
| Anything Core-contract-shaped | `rc-core/AGENTS.md` and `rc-core/docs/MODULE-DEVELOPMENT.md` |
| Deployment / WP Pusher | `docs/DEPLOYMENT.md` (mechanics still accurate; version numbers in it are not) |
| Presentation ownership | Decided (2026-09-19, no exception) — `rc-core/docs/PORTAL-UI.md`'s implementation-status note and this file's Architecture boundaries section above, not the open-questions doc |
| Module lifecycle / registration mechanism itself | Decided (2026-09-19) — `EmbeddedModuleInterface` is canonical; `rc-core`'s own `ModuleInterface`/`ModuleRegistry` mechanism was removed as dead code (zero callers). See `rc-core/docs/MODULE-DEVELOPMENT.md` |
