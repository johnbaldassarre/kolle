<?php
/**
 * Ty Kolle Hockey Development - Registration System configuration.
 *
 * The web installer (/install/) writes this file for you as config.php.
 * To configure by hand instead: copy this file to config.php, fill in the
 * database parameters, and import install/schema.sql (+ install/seed.sql).
 */
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'kolle_registration',
        'user'     => 'db_user',
        'password' => 'db_password',
        'charset'  => 'utf8mb4',
    ],

    // Leave empty to auto-detect. Set it if the app lives behind a proxy or
    // the auto-detected links are wrong, e.g. 'https://example.com/register'
    'base_url' => '',

    // Demo mode: checkout records the order as paid without charging a card.
    'demo_mode' => true,

    // Timezone used for dates and age calculations.
    'timezone' => 'America/Toronto',

    // Email delivery (password resets, registration accepted/denied, custom emails)
    'mail' => [
        'transport'   => 'mail',   // 'mail' = PHP mail(), 'smtp' = mail server below, 'log' = don't send (demo)
        'from_email'  => '',       // e.g. 'no-reply@yourdomain.com' (blank = contact email from Settings)
        'from_name'   => '',       // blank = organization name
        'smtp_host'   => '',       // e.g. 'smtp.gmail.com', 'smtp.office365.com', 'mail.yourdomain.com'
        'smtp_port'   => 587,      // 587 for tls, 465 for ssl
        'smtp_secure' => 'tls',    // 'tls', 'ssl' or ''
        'smtp_user'   => '',
        'smtp_pass'   => '',
    ],

    // Show PHP errors in the browser. Keep false in production.
    'debug' => false,
];
