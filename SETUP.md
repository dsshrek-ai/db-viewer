# Setup Guide

## 1. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real `DB_NAME`, `DB_USER`,
   `DB_PASS` — same MyDataWorld credentials as your other apps.
2. Upload the whole `api/` folder via FTP/File Manager — e.g. `seniorfamily.org/db-viewer-api/`.

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

This tool only ever runs `SELECT * FROM <table> LIMIT <n>` (capped at 2000 rows) against a table
name it fetched from `information_schema` itself — never anything you type freely, and never
anything besides a read. It has no write/delete/edit actions on any table, by design: this is a
debugging viewer, not a database admin panel.
