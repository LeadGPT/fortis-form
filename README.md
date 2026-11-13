# Fortis Wealth Insights, Adviser Connect Form

A fully self-contained lead form. No Typeform, no third-party services,
no paid tools of any kind, just HTML, CSS, vanilla JS, and PHP, built to
run on ordinary Hostinger shared hosting.

## What's here

```
index.html              The form itself (all steps live on one page)
css/style.css            All styling, no external fonts or CDNs
js/form.js                Step navigation, validation, qualification logic
php/config.php            Your settings: admin password hash, notify email
php/submit.php             Receives + stores + (optionally) emails each lead
php/auth.php                Shared login check for the dashboard/export
php/dashboard.php            Password-protected insights page
php/export.php                CSV export of every lead (also password-protected)
php/generate-password-hash.php   One-time helper, delete after use
data/.htaccess              Blocks direct access to the raw data file
data/submissions.ndjson      Created automatically on first submission
```

## How it works

1. A visitor fills out the form. Client-side JS handles all the step
   logic and branching (same qualification rule as before: under
   £250,000 in investable assets routes to the "not qualified" ending,
   everything else routes to "qualified").
2. On submit, the browser sends the data to `php/submit.php`, which
   validates it again server-side, appends it to `data/submissions.ndjson`
   (one JSON record per line), and optionally emails you a notification.
3. The visitor is redirected, for real this time, not just a button
   label, to `https://fortiswealthinsights.co.uk` after seeing the
   thank-you message.
4. You view everything that's come in at `yourdomain.com/php/dashboard.php`:
   total leads, qualified vs. not, a 14-day trend, and a full table with
   the free-text notes each person left for their adviser. Export to
   CSV any time.

## Deploying to Hostinger

1. Log into **hPanel → Websites → Manage → File Manager** (or use FTP
   with the credentials Hostinger gave you).
2. Upload the entire contents of this folder into `public_html/`
   (or a subfolder like `public_html/connect/` if you want it at
   `yourdomain.com/connect/` instead of the root).
3. Make sure the `data/` folder is writable. Hostinger's default
   permissions (755) are usually fine; if submissions fail, try setting
   `data/` to 775 via File Manager → right-click → Permissions.
4. Your admin password is already set. Log in at
   `yourdomain.com/php/dashboard.php` with the password given to you
   alongside this file. To change it later, run
   `php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"`
   on any machine with PHP, and paste the result into `php/config.php`
   as `ADMIN_PASSWORD_HASH`.
5. (Optional) In `php/config.php`, set `NOTIFY_EMAIL` to the inbox you
   want pinged for every new lead. Hostinger's shared hosting includes
   working `mail()` out of the box, so no third-party email service is
   needed. Leave it as `null` if you'd rather just check the dashboard.
6. Visit `yourdomain.com/` to see the live form, and
   `yourdomain.com/php/dashboard.php` to sign in and see your insights.

That's it, no npm install, no build step, no external accounts.
Everything runs off Hostinger's built-in PHP.

## Customizing later

- **Copy/wording**: edit the text directly in `index.html`.
- **Colors**: all defined as CSS variables at the top of
  `css/style.css` (`--forest`, `--gold`, `--paper`, etc.).
- **Qualification threshold**: currently "under £250,000" is the
  cutoff. Change the options in `index.html` (the `assets` radio
  buttons) and the matching value in `js/form.js`
  (`qualifies()` function) and `php/config.php`
  (`DISQUALIFYING_TIER`) together, so all three stay in sync.
- **Notification email formatting**: edit the `$body` section in
  `php/submit.php`.

## A note on security

- The admin password is stored as a hash, never in plain text.
- The dashboard and CSV export both require that password via a PHP
  session, there's no public URL that exposes lead data.
- `data/.htaccess` blocks direct browser access to the raw data file
  as a second layer of protection.
- The hidden "website" field in the form is a honeypot: real visitors
  never see or fill it, so anything that does is almost certainly a
  bot, and gets silently discarded rather than stored.
