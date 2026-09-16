# Embedded modules

Each directory is an isolated business bounded context embedded in the RC Portal distribution.

Rules:

1. no direct import from another module namespace;
2. no direct access to another module's tables;
3. no direct ERP HTTP;
4. cross-domain reads use RC Core contracts;
5. cross-domain notifications use the RC Core event bus;
6. semantic module version and schema version are independent;
7. old code versions live in Git/releases, not parallel runtime folders.
