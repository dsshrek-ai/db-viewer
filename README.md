# DB Viewer

A tiny admin-only tool for the shared MyDataWorld database: pick any real table from a dropdown
and see its rows. Built for quick debugging/data checks (e.g. "what's actually in
`choir_songs`?") without opening phpMyAdmin.

Not registered as an app in My Apps Hub — it reuses the same `users`/`sessions` login, but access
is gated directly on `users.is_admin = 1`, since this reaches every table in the shared database,
not just one app's own data.

## Files

- `index.html` — the whole front end: login screen, table dropdown, results table
- `api/api.php` — backend: `login`/`logout`/`checkAccess` (same pattern as every other MyDataWorld
  app), `tables` (lists real tables in the database — also the injection guard for `dump`, since a
  table name can't be a bound query parameter), `dump` (read-only `SELECT *`, capped at 2000 rows)
- `api/config.example.php` — copy to `api/config.php` with real DB credentials (gitignored)

See [SETUP.md](SETUP.md) for deployment.
