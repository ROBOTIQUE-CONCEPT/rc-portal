# Deployment — RC Portal 0.3.0-alpha2 + RC Portal Theme 0.1.0-alpha1

This release separates the Portal business/runtime layer from its frontend presentation. It performs no business schema migration.

## Prerequisites

- RC Core `>= 0.6.0-alpha7` is Network Active.
- Twenty Twenty-Five (`twentytwentyfive`) is installed on the network.
- RC Portal is activated only on `my`, never Network Active.
- RC Portal Theme is activated only for `my`.

## Recommended deployment order

1. Back up WordPress files and database.
2. Install/verify Twenty Twenty-Five on the network.
3. Upload/replace `rc-portal` with version `0.3.0-alpha2`.
4. Activate RC Portal on `my` only.
5. Upload `rc-portal-theme` into `wp-content/themes/`.
6. Activate **RC Portal Theme** for `my`.
7. Open `/` while authenticated, then verify `/maintenance/`, `/products/`, `/inventory/` and `/leads/`.
8. Verify `/maintenance/`, `/products/`, `/inventory/` and `/leads/`.
9. Confirm normal Portal users are redirected away from `/wp-admin/`.
10. Confirm the super administrator can still access WordPress administration.
11. Run each `tools/preflight.php` from CLI when available.

Activation of RC Portal normally flushes rewrite rules. Visit **Settings > Permalinks** only if a direct module URL such as `/maintenance/` unexpectedly returns 404.

## Rollback

This release does not mutate business data. Rollback is code/theme-only:

1. reactivate the previous theme if necessary;
2. restore the previous `rc-portal` directory;
3. flush rewrite rules.
