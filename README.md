# RC Portal

Version: `0.3.0-alpha2`

RC Portal is the single deployable **business/runtime plugin** for `my.robotiqueconcept.com`.
It deliberately contains **no frontend application presentation**. The official presentation layer is provided by the separate `RC Portal Theme`, a child theme of Twenty Twenty-Five.

## Dependency

- RC Core `>= 0.6.0-alpha7`, Network Active in the current multisite topology.
- RC Portal must be activated **only on `my`**, never Network Active.
- The plugin does not depend on a specific theme. Themes consume the public Portal UI API, never the reverse.

## Responsibilities

RC Portal owns:

- embedded business module discovery/lifecycle;
- routes and request context;
- authentication and authorization gates;
- business/application services;
- data persistence and migrations;
- REST/controllers and Core contracts/events integration;
- wp-admin access policy for normal Portal users.

RC Portal does **not** own:

- frontend HTML;
- frontend CSS/JS;
- page layouts;
- module visual templates;
- design tokens or responsive presentation.

## Embedded modules

This foundation release declares four isolated business bounded contexts:

- `maintenance`: assets, customer equipment, contracts, preventive plans and interventions;
- `products`: ERP products + RC enrichment + publication orchestration;
- `inventory`: RC-specific serialized stock and consignment;
- `leads`: inquiries received from `www` and ERP linkage.

The release intentionally creates no business tables and performs no migration from legacy plugins.

## Public UI boundary

`RC_PORTAL_UI_API_VERSION` declares the presentation API generation.
The active theme can consume:

- `rc_portal()->router()->context()`;
- `rc_portal()->router()->isPortalRequest()`;
- `rc_portal()->modules()`;
- module descriptors;
- RC Core public services/contracts.

RC Portal never locates or includes theme templates.

## Architectural invariants

- no `switch_to_blog()` in Portal runtime;
- no `$wpdb->base_prefix` in Portal runtime;
- no direct ERP HTTP calls;
- no direct module-to-module imports;
- no business file storage under the webroot;
- no frontend presentation bundled in the plugin;
- modules use RC Core contracts/events for cross-domain collaboration;
- wp-admin is reserved to super administrators;
- Le tableau de bord Portal est servi à la racine `/` et les modules directement sous `/{module}/`. Il n’existe plus de préfixe applicatif `/portal`.

## Private application site (0.3.0-alpha3)

RC Portal owns the application-site access policy. The frontend is private by default and uses native WordPress authentication cookies; PHP sessions are not used. `/login/` is the canonical Portal login route. Anonymous REST is denied unless an explicit future public/signed-route policy opts in.

The site root `/` is the Portal dashboard. Business modules are exposed directly as `/maintenance/`, `/products/`, `/inventory/`, `/leads/`, and future `/{module}/...` routes. The application site does not render ordinary CMS pages.
