# RC Portal architecture

## Runtime boundary

```text
WordPress Multisite (current infrastructure)
        │
        ├── RC Core            shared transverse SDK/contracts
        │
        └── my
             ├── RC Portal     application + business runtime
             │    ├── Maintenance
             │    ├── Products
             │    ├── Inventory
             │    ├── Leads
             │    └── Tools
             │
             └── RC Portal Theme
                  └── all frontend presentation/UI
```

`RC Portal` is the only deployable business plugin on `my`.
`RC Portal Theme` is the presentation layer and is intentionally versioned separately.

## Dependency direction

```text
Twenty Twenty-Five
        ↑
RC Portal Theme ─────→ RC Portal public UI API
                              ↓
                           RC Core
```

The plugin **never depends on the theme**. The theme may depend on the Portal public API.
This keeps the business runtime usable if the frontend is replaced in the future.

## Embedded modules

Embedded modules are source-code boundaries, not independent WordPress plugins. They may depend on Portal runtime primitives and RC Core public contracts, but **never on another embedded business module**.

## Presentation rule

> **Decided 2026-09-19, no exception:** a module never builds HTML. It
> supplies semantic page/table/form data (see RC Core's
> `docs/PORTAL-UI.md` — `PageDefinition`/`TableDefinition`); RC Portal (or
> the theme, through Portal) is the only place markup is produced. This is
> not yet implemented: `products` and `tools` currently build HTML
> directly in their own `Ui/*Pages.php` classes. Treat that as migration
> debt to fix, not a pattern to extend in new module work — a new module
> page should not add another hand-built HTML renderer if it can be
> avoided.

Business/domain/application logic must not be implemented in the theme.
The theme may:

- render immutable/query view data;
- invoke explicit application commands/controllers exposed by Portal;
- register presentation assets and templates;
- compose navigation and responsive layouts.

The theme must not:

- query business tables directly;
- call ERP APIs directly;
- own database migrations;
- create business entities by manipulating raw persistence;
- import module-internal repositories.

## Extraction invariant

RC Portal must behave as if `my` were its only WordPress site.
Business code must not depend on numeric blog IDs, the existence of `www`, `switch_to_blog()`, network-global business tables or production domain names.

A future standalone extraction must therefore be an infrastructure/data migration, not a business-code rewrite.

## Files

Business documents and machine backups must not be stored below the WordPress webroot. Modules must consume the canonical Core storage contract once wired; they must not resolve absolute paths themselves.
