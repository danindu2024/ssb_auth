<?php
// Copy to config/config.php (gitignored, never deployed from git) and fill in.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'ssbmanag_central_auth',
        'user' => 'ssbmanag_central_auth',
        'pass' => 'CHANGE_ME',
    ],
    // Public URL of this service, no trailing slash. Must be https in production.
    'base_url' => 'https://auth.ssbmanagement.lk',
    // true only if a trusted reverse proxy sets X-Forwarded-For / X-Forwarded-Proto (normally false on cPanel).
    'trust_proxy' => false,
    'session' => [
        'name'          => 'ssb_idp',
        'idle_minutes'  => 30,
        'absolute_hours' => 12,
        'cookie_secure' => 'auto',          // 'auto' | true | false
        'save_path'     => dirname(__DIR__) . '/storage/sessions',   // ideally outside public_html
    ],
    // Optional. After signing out people land on this service's front door ("/"), which is also accepted as a
    // post-logout target for every app. Only set portal_url if a different landing page should be accepted too.
    'portal_url' => '',
    'code_ttl_seconds' => 60,
];
