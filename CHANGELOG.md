# Changelog

## 0.3.0-alpha10
- Rework the dashboard's family recap from stat cards into an actual product table (`ProductRepository::search()`): free-text search across title/RC reference/ERP reference, family and status filters, column sort (reference, status, date) and pagination — all via GET params so the URL stays shareable/bookmarkable.
- Remove the family list page's duplicated title (family name repeated as both the generic page `<h1>` and the module's own header) and the "Typologie" eyebrow sub-label; replaced with a compact toolbar row (fiche count + a link back to the catalogue).
- Rework the fiche produit page for a more ERP-like feel: the per-language désignation/description block (`renderI18nFields`) is now a tabbed panel (one tab per locale) instead of a flat stack of fields, and the typology-specific block (`renderFamilyFields`) now renders every family's fields at once and "permutes" visibility live when the Typologie select changes, instead of only ever showing the family the fiche was saved with — the previous fixed rendering couldn't reflect a mid-edit reclassification without a page reload.
- Fix a save-order bug this dynamic panel would otherwise have exposed: `saveFiche()` normalizes against the record's *current* family read fresh from the DB, so a same-request reclassification now calls `setFamily()` before `saveFiche()` — previously, fields entered for a newly-selected typology in the same submission were silently normalized away against the old schema.
- Switch every description field (per-language projection and, going forward, any WYSIWYG-authored field) to WordPress's native editor (`wp_editor()`/TinyMCE) and `wp_kses_post()` sanitization instead of a plain textarea and `sanitize_textarea_field()`, so authored HTML (lists, bold, links) survives instead of being flattened to plain text. Only the first/visible tab's editor is initialized eagerly; the others are initialized on demand (`wp.editor.initialize()`, RC Portal Theme 0.1.0-alpha8) the first time their tab is opened, to avoid TinyMCE's known zero-size-when-hidden bug.
- Rework the read-only Axonaut ERP card: fields are now grouped (Identité / Tarification / Stock & logistique) instead of one flat grid, and the `description` field — raw HTML from Axonaut, previously escaped and shown as literal tag soup — is now rendered as actual formatted HTML through a dedicated `wp_kses()` allowlist that (unlike `wp_kses_post()`) keeps the `style` and `id` attributes Axonaut's own editor relies on.
- No RC Core or minimum-version change; this release only touches `products` module presentation and RC Portal Theme's generic tabs/family-toggle primitives (0.1.0-alpha8).

## 0.3.0-alpha9
- Rework the `products` module's flow end to end (feedback on the first `0.3.0-alpha8` cut): browse the raw Axonaut catalog at `/products/catalogue/`, open a dedicated fiche per ERP product at `/products/catalogue/{externalId}/`, and — at "réconciliation" — create the local `rc_product` CPT and enrich it right there, inline. `family` is now optional at creation: a fiche can stay unclassified ("à classifier") and gets its own canonical URL, `/products/{family}/{uid}/`, only once a typology is chosen (one `UiRegistry` page per family slug, registered out of the sidebar — reached from the dashboard cards and from the catalogue).
- Add a "Catalogues" sidebar child entry (`parentPath: ''`) for the catalogue browse/reconcile page — the only new navigation entry; family pages are deliberately not listed in the sidebar.
- Add `modules/products/src/Cli/HydrateCommand.php` (`wp rc products hydrate`, registered only when `WP_CLI` is active): one-shot bulk creation of an unclassified carrier fiche for every Axonaut product not yet reconciled (`ProductRepository::adopt()` with `family = null`), idempotent and safe to re-run.
- Stop reading/relying on any Axonaut product field outside a fixed whitelist (`id`, `name`, `type`, `category`, `description`, `disabled`, `eco_participation`, `image`, `internal_id`, `job_costing`, `location`, `price`, `price_with_tax`, `product_code`, `stock`, `stock_threshold`, `supplier_product_code`, `tax_deee`, `tax_rate`, `unit`, `weighted_average_cost`) — `custom_fields`-derived data (désignation, marque, code douanier, pays d'origine, poids…) is being phased out on the Axonaut side and is no longer read anywhere in this module, including `ProductsCatalogAdapter`'s designation fallback.
- Add common local specs (`longueurMm`/`largeurMm`/`profondeurMm`/`tariffCode`/`countryOfOrigin`, shared by every family — `ProductSpecs`, replacing the family-specific `PieceFicheSchema`) and per-language désignation/description (`ProductTranslations`: `fr` native + `en`/`de`/`es`/`it`, `_rc_product_i18n`), editable inline on every fiche.
- On reconciliation, write the local fiche's `uid`'s post ID back onto the Axonaut product's `internal_id` (RC Core `ProductProviderInterface::updateInternalId()`, RC Core 0.6.0-alpha10) — a strong two-way link between the ERP and RC (Axonaut → RC via `internal_id`, RC → Axonaut via the existing `_rc_product_erp_*` reference). A write-back failure is non-fatal (`AdoptionResult::$warning`): it never rolls back the local fiche just created.
- Rework the dashboard to reuse the Portal theme's `.rc-module-grid`/`.rc-module-card` cards (a stat card per family plus a "Rechercher un produit" catalogue-search helper card), and present every list as a `.rc-table` and every fiche as a set of `.rc-card` sections, using new generic primitives added to RC Portal Theme 0.1.0-alpha7 (`.rc-toolbar`, `.rc-table-wrap`/`.rc-table`, `.rc-badge`, `.rc-card-grid`/`.rc-card`, `.rc-field-grid`/`.rc-field`).
- Raise the minimum required RC Core version to `0.6.0-alpha10` (adds `ProductProviderInterface::updateInternalId()` and the `ecoParticipation`/`taxDeee` `ProductData` fields this release depends on).

## 0.3.0-alpha8
- Implement the `products` module (Phase 2 design document): a headless `rc_product` CPT + non-editable `rc_product_family` taxonomy (7 canonical typologies, idempotently seeded), storing only a mandatory Axonaut link, an optional manufacturer reference, a status, and a per-family JSON fiche validated by a dedicated PHP schema (`PieceFicheSchema`: dimensions only, since Axonaut/`ProductData` already carries désignation/code douanier/pays d'origine; `AssetPointerFicheSchema` for `robot`/`cellule`: a single unvalidated `assetUid` pointer, left empty until Maintenance exists; `OpenFicheSchema` fallback for the 4 families whose fields are still "à cadrer").
- Publish RC Core's `ProductCatalogProviderInterface` via `ServiceRegistry` from the module's own `boot()` (`ProductsCatalogAdapter`, combining the local fiche with `ProductProviderInterface` ERP data) — the single entry point Leads, Catalog and Maintenance will use to resolve an RC-enriched product; no other module may query `rc_product` posts directly.
- Add three RC Portal pages through `UiRegistry`: `/products/` (dashboard, per-family counts), `/products/search/` (Axonaut search + "adopter" gesture, `rc_products_edit`), `/products/list/` and `/products/list/{uid}/` (browse/read fiches, `rc_products_read`; the save form additionally requires `rc_products_edit`).
- `rc_product` capabilities are fully custom (`rc_products_read`/`rc_products_edit`), not WordPress's generic `read`/`edit_posts` — same flat-capability pattern as the legacy Interventions CPT. The post type is fully headless (no wp-admin screen): all editing happens through the RC Portal pages above.
- No RC Core or RC Portal Theme change: this release only consumes contracts RC Core 0.6.0-alpha9 already exposes.

## 0.3.0-alpha7
- Add `CoreBridge::personaKey()`, exposing RC Core's `PersonaResolver` (`admin`/`internal`/`partner`/`customer`/`external`/`none`) to the Portal theme so it can label the interface (e.g. "Espace interne" vs "Espace client") without reasoning about capabilities or role names itself.
- Replace `PortalRouter::navigationChildren()`'s theme usage with the richer `visibleModuleNavigation()`: module visibility is now recursive rather than all-or-nothing — a module whose own root page capability is off but that has at least one accessible child page still appears, listing only the child pages actually granted, instead of either showing everything or hiding the whole module. A module with no `UiRegistry` page at all keeps the previous all-or-nothing behavior for backward compatibility.
- No schema or capability-matrix change; this only changes which already-declared pages are surfaced in navigation.

## 0.3.0-alpha6
- Each embedded module (`products`, `leads`, `maintenance`, `inventory`) now registers its own dashboard on its home route through RC Core's `UiRegistry` (via `rc_register_ui_page()`), instead of the router only ever resolving a static placeholder. `PortalRouter::resolve()` consults `UiRegistry::match('internal', ...)` first and falls back to the previous `ModuleCatalog` resolution when Core has no matching page, so nothing breaks for a module that has not opted in yet.
- Each embedded module's declared capabilities (e.g. `rc_products_read`) are now actually pushed to Core's `CapabilityRegistry` through `rc_register_capabilities()` — they were previously only listed in `ModuleDescriptor` and never registered, so the Network Admin permissions matrix had nothing to control for these modules. Module route access now checks the module's own declared capability instead of the generic `read` capability every logged-in user already has.
- `RouteContext` gains `pageLabel`/`pageHtml` (both optional, default `null`) so the theme can render a page resolved through Core's UI Registry with the same layout as a legacy module placeholder.
- Add `PortalRouter::navigationChildren()`, exposing child pages declared by modules (via `navigationParent`) so the theme can build a per-module navigation hierarchy without any module reimplementing menu logic itself.
- Raise the minimum required RC Core version to `0.6.0-alpha9` (adds the `UiRegistry::match()` capability-bypass parameter this release depends on).

## 0.3.0-alpha5
- Restore Cloudflare Turnstile on the dedicated Portal `/login/` form through RC Core's canonical `turnstileRenderer()` service.
- Validate the `portal_login` Turnstile context server-side before `wp_signon()` through RC Core's canonical `turnstileVerifier()` service.
- Keep RC Core as the sole owner of Turnstile credentials, configuration and Cloudflare verification logic.
- Fail closed if the expected RC Core Turnstile verifier unexpectedly becomes unavailable; a deliberately disabled Turnstile context still follows Core's normal success path.

## 0.3.0-alpha4
- Supprime le préfixe `/portal` des URLs applicatives : `/`, `/maintenance/`, `/products/`, `/inventory/`, `/leads/`.
- Le routeur possède désormais tout le frontend MY et accepte les futurs sous-chemins de modules.
- Conserve `/login/` comme unique entrée anonyme métier, hors endpoints techniques PWA et futures URLs signées.
- Passe les libellés et messages visibles du runtime en français natif.
- Aucun changement de schéma de données.

## 0.3.0-alpha3

- Make the application site private by default through a Portal-owned authentication guard.
- Add `/login/` as the canonical Portal login route using native WordPress authentication cookies and `wp_signon()`.
- Require authentication for REST requests by default; future signed/public routes must opt in explicitly.
- Redirect stray authenticated frontend CMS URLs back to the Portal application shell.
- Add site-wide `noindex`, `nofollow`, `noarchive` and private/no-store response headers.
- Disable XML-RPC while RC Portal is active on the application site.
- Make `/` the Portal dashboard while retaining `/portal/<module>/` module routes.
- Refresh site-local rewrite rules once per Portal runtime version.
- Raise the minimum RC Core version to `0.6.0-alpha8`.

## 0.3.0-alpha2

- Separate all frontend presentation from the plugin runtime.
- Add the versioned Portal UI API consumed by the dedicated Portal theme.
