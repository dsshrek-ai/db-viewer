# Setup Guide

## 1. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real `DB_NAME`, `DB_USER`,
   `DB_PASS` — same MyDataWorld credentials as your other apps.
2. Upload the whole `api/` folder via FTP/File Manager — e.g. `seniorfamily.org/db-viewer-api/`.
3. In phpMyAdmin, run `api/schema.sql` once against the MyDataWorld database. It creates
   `db_viewer_queries` (for the Save feature) and loads a few starter queries. Without it, the
   table browser and ad-hoc **Run** still work — only saving/loading named queries is disabled
   (you'll get a "run api/schema.sql once" message).

## 2. Point the app at your API

In `index.html`, update:

```js
const API_URL = "https://seniorfamily.org/db-viewer-api/api.php";
```

## 3. Publish

Push this repo and turn on GitHub Pages (Settings → Pages → Deploy from branch → `main` /
`/(root)`), or upload `index.html` directly to your host — same as the other apps.

## 4. Who can log in

There's no separate account system — it's the same `users` table every MyDataWorld app shares.
Anyone who already has a My Apps Hub login can attempt to log in here, but only accounts with
`users.is_admin = 1` are let in (`login` and `checkAccess` both check it, so a non-admin login
attempt fails outright with "Not authorized — admin accounts only," even with the correct
password). If you're not already an admin:

```sql
UPDATE users SET is_admin = 1 WHERE username = 'you@example.com';
```

## A note on scope

This is a debugging viewer, not a database admin panel — it has **no write/update/delete actions
on any table**, by design.

- **Browse a table** runs only `SELECT * FROM <table> LIMIT <n>` (capped at 2000 rows), against a
  table name it fetched from `information_schema` itself.
- **Run SQL** runs what you type, but `assertReadOnly()` rejects anything that isn't plainly a
  `SELECT` / `WITH` / `SHOW` / `EXPLAIN` / `DESCRIBE`, or that contains a write/DDL/privilege/file
  keyword, a second statement, or a `/*! … */` executable comment. Results are capped at 5000 rows
  and there's a best-effort ~20-second timeout.
- **Saved queries** are just stored text in `db_viewer_queries`; running one goes through the same
  read-only guard.

Since it's admin-only (`is_admin = 1`), the guard is there to stop an accidental `UPDATE`/`DELETE`
and to limit the damage from a stolen session — not to sandbox a hostile user. For anything that
needs to change data, use phpMyAdmin.
