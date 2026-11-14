# Deploying to Railway

This site is one PHP/Apache service plus one Railway Volume. There is no separate
frontend, backend or database service: leads are stored as flat files in `data/`,
and the Volume is what keeps them across deploys.

## Files already set up

| File | Purpose |
|---|---|
| `Dockerfile` | `php:8.3-apache` image; copies the site in, enables `.htaccess` (needed to block public access to `data/`) |
| `docker-entrypoint.sh` | Makes Apache listen on Railway's `$PORT`, and makes `data/` writable by Apache on each start (Railway volumes mount as root) |
| `.dockerignore` | Keeps `.DS_Store`, `.git`, and local lead data out of the image |
| `railway.json` | Build from Dockerfile, restart on failure |

## Deploy steps

### 1. Install and log in to the CLI
```bash
npm i -g @railway/cli     # or: brew install railway
railway login
```

### 2. Create the project and deploy
From this folder:
```bash
railway init              # create a new project
railway up                # build and deploy this folder
```
(Alternative: push the folder to GitHub and use **New Project -> Deploy from GitHub repo** in the dashboard.)

### 3. Add the Volume (do not skip)
In the Railway dashboard: open the service -> **Settings -> Volumes -> Add Volume**
(or right-click the service on the canvas -> *Attach Volume*).

- **Mount path:** `/var/www/html/data`

Without this, `submissions.ndjson` and `progress.json` are wiped on every redeploy.
After attaching, redeploy once so the entrypoint fixes the folder permissions.

### 4. Generate a public domain
Service -> **Settings -> Networking -> Generate Domain**.

- Form: `https://<your-domain>/`
- Admin dashboard: `https://<your-domain>/php/dashboard.php`

### 5. (Optional) Custom domain
Same Networking section -> **Custom Domain**, then add the CNAME record Railway shows you at your DNS provider.

## Verify after deploying

1. Open the form, submit a test lead.
2. Sign in at `/php/dashboard.php` with the admin password; the lead should appear.
3. Open `https://<your-domain>/data/submissions.ndjson`. It must return **403 Forbidden**. If it shows data, `.htaccess` isn't being applied; stop and fix before going live.
4. Trigger a redeploy (`railway up` or Redeploy in the dashboard) and confirm the test lead is still in the dashboard. This proves the Volume works.

## Notes and gotchas

- **Email:** PHP `mail()` doesn't work on Railway. `NOTIFY_EMAIL` is `null`, so nothing breaks, but notifications would need SMTP or an email API (Resend, Postmark, etc.).
- **Admin password:** set the `ADMIN_PASSWORD_HASH` Railway variable. Generate a hash with
  `php -r "echo password_hash('new-password', PASSWORD_DEFAULT);"`. Keep the hash in Railway's
  variables rather than committing it to the repository.
- **Single replica only:** flat files plus a Volume means one instance. Don't scale to multiple replicas.
- **Backups:** download `data/submissions.ndjson` periodically via the dashboard's CSV export, or use Railway's Volume backups.
- **Scale:** if lead volume grows large, move to a Railway Postgres service. That needs `submit.php`, `dashboard.php`, `export.php` and `progress_lib.php` rewritten to use SQL.
- **Troubleshooting:** if submissions fail with a 500, check **Deployments -> Logs**; the usual cause is `data/` not being writable (Volume missing or not redeployed after attaching).
