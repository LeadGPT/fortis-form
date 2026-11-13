<?php
/**
 * Fortis Wealth Insights, configuration
 *
 * Admin password is already set (see the answer where this file was
 * handed to you for the actual password). To change it later: run
 *   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
 * on any machine with PHP, and paste the result in below.
 *
 * Set NOTIFY_EMAIL to the inbox that should get a ping on each new lead,
 * or leave it as null to disable email notifications entirely.
 */

define('ADMIN_PASSWORD_HASH', '$2y$10$bL6dzrUh2DYfvwSJ.gER7OoPj.diGj84NF6iZYPjmV69NxmD9NwZy');

// Email address that receives a notification for every new submission.
// Set to null to disable notification emails.
define('NOTIFY_EMAIL', null);

// Where submissions are stored. Kept outside the web root's reach via
// data/.htaccess (see that file), direct URL access is blocked.
define('DATA_FILE', __DIR__ . '/../data/submissions.ndjson');

// Progress/drop-off tracking: one record per browser session, updated in place
// as the person advances through the form. Lets the dashboard show how many
// people started vs. how many actually finished, and where they fell off.
define('PROGRESS_FILE', __DIR__ . '/../data/progress.json');

// The exact asset-range label below this line is treated as "not qualified".
define('DISQUALIFYING_TIER', 'Under £100,000');
