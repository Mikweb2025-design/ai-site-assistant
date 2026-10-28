<?php
/*
 * Actions of the AI assistant that reach the owner, called by the engine with the token (POST JSON):
 *   type "message"  a visitor message, sent like a contact-form mail (Reply-To = visitor)
 *   type "booking"  an appointment: slot check, storage, optional CalDAV event, mails to owner and visitor
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/admin/booking-lib.php';

function ais_reply(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { ais_reply(405, ['ok' => false, 'error' => 'method']); }
$tok = ais_secret();
$given = trim((string)($_SERVER['HTTP_X_ASSISTANT_TOKEN'] ?? ''));
if ($tok === '' || $given === '' || !hash_equals($tok, $given)) { http_response_code(404); echo '{}'; exit; }

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) { ais_reply(400, ['ok' => false, 'error' => 'json']); }
$type  = (string)($data['type'] ?? '');
$name  = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$lang  = in_array($data['lang'] ?? 'en', ['de', 'en', 'it'], true) ? (string)$data['lang'] : 'en';
$ip    = filter_var($data['ip'] ?? '', FILTER_VALIDATE_IP) ?: 'assistant';

if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\r\n]/', $name)) { ais_reply(422, ['ok' => false, 'error' => 'name']); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) { ais_reply(422, ['ok' => false, 'error' => 'email']); }

$cfg = ais_settings();

/* a mail flood stopper that does not depend on the engine: at most 40 actions per day in total */
$cnt = ais_priv() . '/assistant-actions-' . date('Ymd') . '.count';
$n = (int)@file_get_contents($cnt);
if ($n >= 40) { ais_reply(429, ['ok' => false, 'error' => 'daily']); }
@file_put_contents($cnt, (string)($n + 1));
foreach (glob(ais_priv() . '/assistant-actions-*.count') ?: [] as $old) { if (basename($old) < 'assistant-actions-' . date('Ymd', strtotime('-3 days'))) { @unlink($old); } }

if ($type === 'message') {
    if (empty($cfg['mail']['messages'])) { ais_reply(403, ['ok' => false, 'error' => 'disabled']); }
    $message = trim((string)($data['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 5000) { ais_reply(422, ['ok' => false, 'error' => 'message']); }
    $message .= "\n\n— sent through the AI assistant on the website";
    $subject = 'New message via ' . ais_host();
    $when = date('Y-m-d H:i:s');
    $text = "New message through the AI assistant\n------------------------------------\nName:     $name\nE-mail:   $email\nLanguage: $lang\nIP:       $ip\nTime:     $when\n\n$message\n";
    $html = ais_mail_html('New message', [['Name', $name], ['E-mail', '<a href="mailto:' . ais_h($email) . '">' . ais_h($email) . '</a>', true], ['Language', $lang], ['Time', $when]], $message, 'mailto:' . $email, 'Reply →', 'Sent through the AI assistant on ' . ais_host() . '. Reply to this e-mail to answer the visitor.');
    [$ct, $body] = ais_mail_multipart($text, $html);
    $ok = ais_mail((string)($cfg['mail']['notify'] ?? ais_config()['notify_email']), $subject, $ct, $body, $email);
    ais_reply($ok ? 200 : 500, ['ok' => (bool)$ok, 'error' => $ok ? null : 'mail']);
}

if ($type === 'booking') {
    if (empty($cfg['booking']['enabled'])) { ais_reply(403, ['ok' => false, 'error' => 'disabled']); }
    $date = (string)($data['date'] ?? '');
    $time = (string)($data['time'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) { ais_reply(422, ['ok' => false, 'error' => 'slot']); }
    $note = trim(mb_substr(str_replace("\r", '', (string)($data['note'] ?? '')), 0, 500));
    $booking = null;
    $ok = ais_update_bookings(function (array $list) use ($cfg, $date, $time, $name, $email, $note, $lang, &$booking) {
        $free = ais_slots($cfg, $list);
        foreach ($free as $s) {
            if ($s['date'] === $date && $s['time'] === $time) {
                $booking = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'email' => $email, 'date' => $date, 'time' => $time,
                    'durationMin' => (int)$cfg['booking']['durationMin'], 'note' => $note, 'lang' => $lang, 'created' => date('c'), 'status' => 'confirmed'];
                $list[] = $booking;
                return $list;
            }
        }
        return null;
    });
    if (!$booking) { ais_reply($ok ? 409 : 500, ['ok' => false, 'error' => $ok ? 'slot_taken' : 'storage']); }
    $davHref = ais_dav_push($booking, $cfg);   // into the connected CalDAV calendar (if any)
    if ($davHref !== '') {
        $bid = $booking['id'];
        ais_update_bookings(static function (array $l) use ($bid, $davHref) { foreach ($l as &$x) { if ($x['id'] === $bid) { $x['dav'] = $davHref; } } return $l; });
        $booking['dav'] = $davHref;
    }
    $m = ais_send_booking_mails($booking, $cfg);
    ais_reply(200, ['ok' => true, 'id' => $booking['id'], 'calendar' => $davHref !== '', 'mailOwner' => $m['owner'], 'mailVisitor' => $m['visitor']]);
}

ais_reply(400, ['ok' => false, 'error' => 'type']);
