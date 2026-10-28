<?php
/*
 * Settings feed for the engine (token protected, X-Assistant-Token header). The admin writes the settings,
 * the engine polls this endpoint about once a minute. Everything else answers 404.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/admin/booking-lib.php';

$tok = ais_secret();
$given = trim((string)($_SERVER['HTTP_X_ASSISTANT_TOKEN'] ?? ''));
if ($tok === '' || $given === '' || !hash_equals($tok, $given)) {
    http_response_code(404);
    echo '{}';
    exit;
}
@file_put_contents(ais_priv() . '/assistant-pulled', (string)time());
ais_purge();
$cfg = ais_settings();
// what the engine needs: free slots and switches (no e-mail addresses, no visitor data)
$cfg['bookingFeed'] = [
    'enabled' => !empty($cfg['booking']['enabled']),
    'durationMin' => (int)$cfg['booking']['durationMin'],
    'place' => (string)$cfg['booking']['place'],
    'slots' => ais_slots($cfg, null, null, 60),
];
$cfg['messagesEnabled'] = !empty($cfg['mail']['messages']);
$cfg['voiceImports'] = array_values(array_map(static fn ($v) => ['id' => $v['id'], 'name' => $v['name'], 'text' => $v['text']], array_filter(ais_voices(), static fn ($v) => ($v['status'] ?? '') === 'pending')));
$cfg['owner'] = ais_owner();
$cfg['siteUrl'] = ais_site_url();
unset($cfg['booking'], $cfg['mail']);
echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
