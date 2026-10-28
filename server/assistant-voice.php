<?php
/*
 * Voice samples for the engine (token protected, X-Assistant-Token header).
 *   GET  ?id=<id>                                                        the uploaded audio file
 *   POST {"id": "...", "status": "installed"|"error", "note": "..."}     result of the installation on the engine host
 */
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/admin/booking-lib.php';

$tok = ais_secret();
$given = trim((string)($_SERVER['HTTP_X_ASSISTANT_TOKEN'] ?? ''));
if ($tok === '' || $given === '' || !hash_equals($tok, $given)) { http_response_code(404); echo '{}'; exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    foreach (ais_voices() as $v) {
        if ($v['id'] === $id && is_file(ais_voice_dir() . '/' . $v['file'])) {
            header('Content-Type: application/octet-stream');
            readfile(ais_voice_dir() . '/' . $v['file']);
            exit;
        }
    }
    http_response_code(404); echo 'not found'; exit;
}

header('Content-Type: application/json; charset=utf-8');
$d = json_decode((string)file_get_contents('php://input'), true);
$id = is_array($d) ? preg_replace('/[^a-f0-9]/', '', (string)($d['id'] ?? '')) : '';
$status = is_array($d) && in_array($d['status'] ?? '', ['installed', 'error'], true) ? (string)$d['status'] : '';
if ($id === '' || $status === '') { http_response_code(400); echo '{"ok":false}'; exit; }
$note = mb_substr(trim((string)($d['note'] ?? '')), 0, 200);
$found = false;
ais_voices_update(static function (array $l) use ($id, $status, $note, &$found) {
    foreach ($l as &$v) { if ($v['id'] === $id) { $v['status'] = $status; $v['note'] = $note; $v['done'] = date('c'); $found = true; } }
    return $found ? $l : null;
});
echo json_encode(['ok' => $found]);
