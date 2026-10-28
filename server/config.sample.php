<?php
/*
 * ai-site-assistant: server configuration.
 *
 * Copy this file to config.php (same folder) and edit it. config.php must never be committed or served as
 * text: keep it out of the web root if you can, or deny it in your web server (the .htaccess/nginx example in
 * README.md does). Every value below is optional except `site_url` and `admin`.
 *
 * Generate the admin password hash with:
 *     php -r 'echo password_hash("your-long-password", PASSWORD_DEFAULT), PHP_EOL;'
 */
return [
    // Public address of your website, no trailing slash. Used in mails, calendar links and the allowed origin.
    'site_url' => 'https://example.com',

    // Name of the person the assistant speaks about (mails, calendar entries, knowledge).
    'owner_name' => 'Jane Doe',
    // Short name shown in the widget header (admin > Widget can override it).
    'assistant_name' => 'Jane',

    // Mail: where bookings and visitor messages go, and what the assistant sends as.
    'notify_email' => 'owner@example.com',
    'from_email'   => 'assistant@example.com',
    'from_name'    => 'Website assistant',
    // Optional SMTP. Leave null to use PHP's mail().
    'smtp' => null,
    // 'smtp' => ['host' => 'smtp.example.com', 'port' => 465, 'secure' => 'ssl' /* ssl | tls */, 'user' => '', 'pass' => ''],

    // Time zone of the opening hours you enter in the admin (PHP time zone identifier).
    'timezone' => 'Europe/Berlin',

    // Folder for tokens, settings, bookings, CalDAV credentials and voice samples. MUST be outside the web root
    // and writable by the PHP user. Default: a "private" folder next to the folder that contains this one.
    'private_dir' => dirname(__DIR__) . '/private',

    // The JSON file read by the widget loader on every page (written by admin > Widget). It has to be publicly
    // readable at /site-config.json (or wherever your loader fetches it). It never contains secrets.
    'site_config_file' => dirname(__DIR__) . '/site-config.json',

    // Where the admin reaches the engine through your reverse proxy (see nginx/assistant.conf.sample).
    'status_url' => 'https://example.com/assistant/status',

    // Origins allowed to call the text-only fallback endpoint (assistant-faq.php) from a browser.
    // The origin of site_url is always allowed.
    'allowed_origins' => [],

    // Hosts the admin accepts for the extra knowledge pages (admin > Knowledge). The host of site_url is always allowed.
    'knowledge_hosts' => [],

    // Behind a reverse proxy that sets X-Forwarded-For? Only then set this to true, otherwise visitors could fake their IP.
    'trust_proxy' => false,

    // Where the "link" chips of the text-only fallback point (per language). Keys are the link ids used in
    // data/faq.json; unknown ids are ignored. Paths must start with "/".
    'faq_links' => [
        'en' => ['about' => '/about/', 'projects' => '/projects/', 'blog' => '/blog/', 'tools' => '/tools/', 'skills' => '/skills/'],
        'de' => ['about' => '/de/about/', 'projects' => '/de/projects/', 'blog' => '/de/blog/', 'tools' => '/de/tools/', 'skills' => '/de/skills/'],
        'it' => ['about' => '/it/about/', 'projects' => '/it/projects/', 'blog' => '/it/blog/', 'tools' => '/it/tools/', 'skills' => '/it/skills/'],
    ],

    // Admin login. One user; the password is stored as a hash.
    'admin' => [
        'username' => 'admin',
        'password_hash' => '',   // paste the output of the command above
    ],
];
