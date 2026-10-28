<?php
/*
 * Shared bootstrap: configuration, paths, small helpers and the admin session.
 * Functions only; nothing runs when this file is requested directly.
 */
declare(strict_types=1);
if (isset($_SERVER['SCRIPT_FILENAME']) && basename((string)$_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

/** the merged configuration (config.php over built-in defaults; AIS_* environment variables override paths for tests) */
function ais_config(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $defaults = [
        'site_url' => 'http://localhost',
        'owner_name' => 'the owner',
        'assistant_name' => 'Assistant',
        'notify_email' => 'owner@example.com',
        'from_email' => 'assistant@example.com',
        'from_name' => 'Website assistant',
        'smtp' => null,
        'timezone' => 'UTC',
        'private_dir' => dirname(__DIR__, 2) . '/private',
        'site_config_file' => dirname(__DIR__) . '/site-config.json',
        'status_url' => '',
        'allowed_origins' => [],
        'knowledge_hosts' => [],
        'trust_proxy' => false,
        'faq_links' => [],
        'admin' => ['username' => 'admin', 'password_hash' => ''],
    ];
    $file = getenv('AIS_CONFIG') ?: dirname(__DIR__) . '/config.php';
    $user = is_file($file) ? require $file : [];
    $c = array_replace($defaults, is_array($user) ? $user : []);
    $c['admin'] = array_replace($defaults['admin'], is_array($c['admin']) ? $c['admin'] : []);
    $c['site_url'] = rtrim((string)$c['site_url'], '/');
    if ($c['status_url'] === '') { $c['status_url'] = $c['site_url'] . '/assistant/status'; }
    if (($e = getenv('AIS_PRIVATE_DIR')) !== false && $e !== '') { $c['private_dir'] = $e; }
    if (($e = getenv('AIS_SITE_CONFIG')) !== false && $e !== '') { $c['site_config_file'] = $e; }
    if (($e = getenv('AIS_ASSISTANT_STATUS_URL')) !== false && $e !== '') { $c['status_url'] = $e; }
    if (!@date_default_timezone_set((string)$c['timezone'])) { date_default_timezone_set('UTC'); $c['timezone'] = 'UTC'; }
    return $c;
}

function ais_cfg(string $key, $default = null) { return ais_config()[$key] ?? $default; }
function ais_priv(): string { return (string)ais_config()['private_dir']; }
function ais_owner(): string { return (string)ais_config()['owner_name']; }
function ais_site_url(): string { return (string)ais_config()['site_url']; }
function ais_host(): string { return (string)(parse_url(ais_site_url(), PHP_URL_HOST) ?: 'localhost'); }
function ais_tz(): DateTimeZone { return new DateTimeZone((string)ais_config()['timezone']); }

function ais_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ais_jread(string $f): array { $j = json_decode((string)@file_get_contents($f), true); return is_array($j) ? $j : []; }
function ais_jwrite(string $f, array $d, int $mode = 0644): bool
{
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) { return false; }
    if (!@rename($tmp, $f)) { return false; }
    @chmod($f, $mode);
    return true;
}
function ais_clip(string $s, int $max): string { return mb_substr(trim(str_replace("\r", '', $s)), 0, $max); }
function ais_int_in($v, int $min, int $max, int $def): int { return is_numeric($v) ? max($min, min($max, (int)$v)) : $def; }
function ais_ensure_private(): void { if (!is_dir(ais_priv())) { @mkdir(ais_priv(), 0750, true); } }

/** the visitor's IP; X-Forwarded-For only when config 'trust_proxy' is true */
function ais_client_ip(): string
{
    if (ais_config()['trust_proxy']) {
        $x = trim((string)explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($x, FILTER_VALIDATE_IP)) { return $x; }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* ---------------------------------- admin session ---------------------------------- */

/** security headers for every admin page; $scriptSrc is "'none'" except for the pages that load admin/voice-rec.js */
function ais_admin_headers(string $scriptSrc = "'none'"): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; media-src 'self' blob:; script-src $scriptSrc; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

function ais_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_name('aisess');
    session_start();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
}

/** call at the top of every admin page: redirects to the login when needed */
function ais_admin_require(string $scriptSrc = "'none'"): void
{
    ais_admin_headers($scriptSrc);
    ais_admin_session();
    if (empty($_SESSION['ais_auth'])) {
        header('Location: login.php');
        exit;
    }
}
