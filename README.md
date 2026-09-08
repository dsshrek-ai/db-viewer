# DB Viewer

A tiny admin-only tool for the shared MyDataWorld database. Two ways to look around:

- **Browse a table** — pick any real table from a dropdown and see its rows (with client-side
  quick filters), e.g. "what's actually in `choir_songs`?"
- **Run SQL** — paste a read-only query and run it. Name a query and **Save** it to reuse later
  from a dropdown; the starters (app usage, table sizes, users, app access) come pre-loaded.

Built for quick debugging/data checks without opening phpMyAdmin.

Not registered as an app in My Apps Hub — it reuses the same `users`/`sessions` login, but access
is gated directly on `users.is_admin = 1`, since this reaches every table in the shared database,
not just one app's own data.

## Read-only, by design

`dump` only ever runs `SELECT * FROM <table> LIMIT <n>`. `runQuery` runs what you type, but
`assertReadOnly()` in `api/api.php` rejects it unless it plainly starts with
`SELECT` / `WITH` / `SHOW` / `EXPLAIN` / `DESCRIBE` and contains no write, DDL, privilege, or
file keyword, no second statement, and no `/*! … */` executable comment. Result payloads are
capped (2000 rows for `dump`, 5000 for `runQuery`) and there is a best-effort ~20s query timeout.
There are still no write/update/delete actions anywhere in this tool.

## Files

- `index.html` — the whole front end: login screen, table browser, SQL runner, results tables
- `api/api.php` — backend: `login`/`logout`/`checkAccess` (same pattern as every other MyDataWorld
  app), `tables` (lists real tables — also the injection guard for `dump`, since a table name
  can't be a bound query parameter), `dump` (read-only `SELECT *`), `runQuery` (read-only typed
  SQL), `savedQueries`/`saveQuery`/`deleteQuery` (the named-query library)
- `api/schema.sql` — one table, `db_viewer_queries`, for saved queries. Run once in phpMyAdmin.
- `api/config.example.php` — copy to `api/config.php` with real DB credentials (gitignored)

See [SETUP.md](SETUP.md) for deployment.
