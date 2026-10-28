<?php
/*
 * Assistant backend library: settings, bookings (with free-slot computation), ICS, mail, CalDAV and voice imports.
 * Used by assistant-*.php and admin/chatbot.php. Functions only; nothing runs when requested directly.
 */
declare(strict_types=1);
if (isset($_SERVER['SCRIPT_FILENAME']) && basename((string)$_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-template.php';

const AIS_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function ais_settings_defaults(): array {
    return [
        'booking' => [
            'enabled' => false,
            'hours' => ['mon' => '09:00-12:00, 14:00-17:00', 'tue' => '09:00-12:00, 14:00-17:00', 'wed' => '09:00-12:00, 14:00-17:00',
                        'thu' => '09:00-12:00, 14:00-17:00', 'fri' => '09:00-12:00', 'sat' => '', 'sun' => ''],
            'durationMin' => 20, 'bufferMin' => 10, 'noticeH' => 24, 'horizonD' => 21, 'blocked' => '',
            'place' => 'Video call (the link follows by e-mail)', 'confirmVisitor' => true,
        ],
        'mail' => ['messages' => true, 'notify' => (string)ais_config()['notify_email']],
    ];
}

/** assistant-config.json with the booking/mail defaults merged in */
function ais_settings(): array {
    $j = json_decode((string)@file_get_contents(ais_priv() . '/assistant-config.json'), true);
    $j = is_array($j) ? $j : [];
    $d = ais_settings_defaults();
    $j['booking'] = (is_array($j['booking'] ?? null) ? $j['booking'] : []) + $d['booking'];
    $j['booking']['hours'] = (is_array($j['booking']['hours']) ? $j['booking']['hours'] : []) + $d['booking']['hours'];
    $j['mail'] = (is_array($j['mail'] ?? null) ? $j['mail'] : []) + $d['mail'];
    return $j;
}

function ais_bookings(): array {
    $j = json_decode((string)@file_get_contents(ais_priv() . '/assistant-bookings.json'), true);
    return is_array($j) ? $j : [];
}

/** read-modify-write under a lock; $fn gets the list and returns the new list (or null = no change) */
function ais_update_bookings(callable $fn): bool {
    $f = ais_priv() . '/assistant-bookings.json';
    $fp = @fopen($f . '.lock', 'c');
    if (!$fp) { return false; }
    flock($fp, LOCK_EX);
    $cur = ais_bookings();
    $new = $fn($cur);
    $ok = true;
    if (is_array($new)) {
        $tmp = $f . '.tmp';
        $ok = @file_put_contents($tmp, json_encode(array_values($new), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") !== false && @rename($tmp, $f);
        if ($ok) { @chmod($f, 0640); }
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** deletes bookings whose date is more than 90 days past (the privacy page promises this) */
function ais_purge(): void {
    $f = ais_priv() . '/assistant-bookings.json';
    if (!is_file($f)) { return; }
    $limit = date('Y-m-d', strtotime('-90 days'));
    $old = false;
    foreach (ais_bookings() as $b) { if (($b['date'] ?? '9999') < $limit) { $old = true; break; } }
    if ($old) {
        ais_update_bookings(static fn (array $l) => array_values(array_filter($l, static fn ($b) => ($b['date'] ?? '9999') >= $limit)));
    }
}

function ais_ranges(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $part) {
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $part, $m)) {
            $a = (int)$m[1] * 60 + (int)$m[2];
            $b = (int)$m[3] * 60 + (int)$m[4];
            if ($a < $b && $b <= 24 * 60) { $out[] = [$a, $b]; }
        }
    }
    return $out;
}

function ais_blocked(string $s): array {
    $days = [];
    foreach (preg_split('/\R/', $s) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*(?:\.\.|-|bis|to)\s*(\d{4}-\d{2}-\d{2}))?$/', $line, $m)) {
            $a = new DateTimeImmutable($m[1]);
            $b = new DateTimeImmutable($m[2] ?? $m[1]);
            for ($i = 0; $i < 120 && $a <= $b; $i++, $a = $a->modify('+1 day')) { $days[$a->format('Y-m-d')] = true; }
        }
    }
    return $days;
}

/** free slots from now on: [['date' => 'YYYY-MM-DD', 'time' => 'HH:MM'], ...] */
function ais_slots(?array $cfg = null, ?array $bookings = null, ?DateTimeImmutable $now = null, int $max = 80, ?array $ext = null): array {
    $cfg = ($cfg ?? ais_settings())['booking'];
    if (empty($cfg['enabled'])) { return []; }
    $bookings = $bookings ?? ais_bookings();
    $now = $now ?? new DateTimeImmutable('now');
    $dur = max(5, (int)$cfg['durationMin']);
    $buf = max(0, (int)$cfg['bufferMin']);
    $earliest = $now->modify('+' . max(0, (int)$cfg['noticeH']) . ' hours');
    $blocked = ais_blocked((string)$cfg['blocked']);
    $busy = [];
    foreach ($bookings as $b) {
        if (($b['status'] ?? 'confirmed') !== 'confirmed') { continue; }
        $s = strtotime($b['date'] . ' ' . $b['time']);
        if ($s) { $busy[] = [$s - $buf * 60, $s + ((int)($b['durationMin'] ?? $dur) + $buf) * 60]; }
    }
    foreach (($ext ?? ais_dav_busy((int)$cfg['horizonD'])) as [$x, $y]) { $busy[] = [$x - $buf * 60, $y + $buf * 60]; }
    $slots = [];
    for ($i = 0; $i <= (int)$cfg['horizonD'] && count($slots) < $max; $i++) {
        $day = $now->modify("+$i day");
        $date = $day->format('Y-m-d');
        if (isset($blocked[$date])) { continue; }
        foreach (ais_ranges((string)($cfg['hours'][AIS_DAYS[(int)$day->format('N') - 1]] ?? '')) as [$a, $b]) {
            for ($t = $a; $t + $dur <= $b && count($slots) < $max; $t += $dur + $buf) {
                $time = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                $start = new DateTimeImmutable("$date $time");
                if ($start < $earliest) { continue; }
                $s = $start->getTimestamp();
                $clash = false;
                foreach ($busy as [$x, $y]) { if ($s < $y && $s + $dur * 60 > $x) { $clash = true; break; } }
                if (!$clash) { $slots[] = ['date' => $date, 'time' => $time]; }
            }
        }
    }
    return $slots;
}

function ais_secret(): string {
    $f = ais_priv() . '/assistant-token';
    return trim((string)@file_get_contents($f));
}
function ais_cal_token(): string { return trim((string)@file_get_contents(ais_priv() . '/assistant-cal-token')); }
function ais_sign(string $id): string { return substr(hash_hmac('sha256', $id, ais_secret() . '|cal'), 0, 24); }

/* ---------- ICS ---------- */
function ais_ics_escape(string $s): string { return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\;", "\\,", "\\n", "\\n"], $s); }

function ais_ics_event(array $b, array $cfg): string {
    $start = (new DateTimeImmutable($b['date'] . ' ' . $b['time'], ais_tz()))->setTimezone(new DateTimeZone('UTC'));
    $end = $start->modify('+' . (int)($b['durationMin'] ?? 20) . ' minutes');
    $desc = 'Appointment with ' . ais_owner();
    if (!empty($b['note'])) { $desc .= "\n" . $b['note']; }
    if (!empty($cfg['booking']['place'])) { $desc .= "\n" . $cfg['booking']['place']; }
    return "BEGIN:VEVENT\r\nUID:" . $b['id'] . '@' . ais_host() . "\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
        . 'DTSTART:' . $start->format('Ymd\THis\Z') . "\r\nDTEND:" . $end->format('Ymd\THis\Z') . "\r\n"
        . 'SUMMARY:' . ais_ics_escape('Appointment: ' . $b['name']) . "\r\n"
        . 'DESCRIPTION:' . ais_ics_escape($desc) . "\r\n"
        . (!empty($cfg['booking']['place']) ? 'LOCATION:' . ais_ics_escape((string)$cfg['booking']['place']) . "\r\n" : '')
        . "END:VEVENT\r\n";
}

function ais_ics(array $events, array $cfg, string $name = ''): string {
    $name = $name !== '' ? $name : ais_owner() . ' – appointments';
    $o = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//" . ais_host() . "//ai-site-assistant//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:" . ais_ics_escape($name) . "\r\n";
    foreach ($events as $e) { $o .= ais_ics_event($e, $cfg); }
    return $o . "END:VCALENDAR\r\n";
}

/* ---------- mail: config 'smtp' block if present, else PHP mail() ---------- */
function ais_mail(string $to, string $subject, string $ctype, string $body, string $replyTo = ''): bool {
    $c = ais_config();
    $from = (string)$c['from_email'];
    $fromName = (string)$c['from_name'];
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $smtp = is_array($c['smtp']) ? $c['smtp'] : null;
    if ($smtp && !empty($smtp['host'])) {
        require_once __DIR__ . '/smtp.php';
        $headers = ['From: ' . $fromName . ' <' . $from . '>', 'MIME-Version: 1.0', 'Content-Type: ' . $ctype, 'X-Mailer: ai-site-assistant'];
        if ($replyTo !== '') { $headers[] = 'Reply-To: ' . $replyTo; }
        if (ais_smtp_send($smtp + ['from' => $from], $to, $subjectEnc, $body, $headers)) { return true; }
        error_log('ai-site-assistant: SMTP send failed, falling back to mail()');
    }
    $h = "From: $fromName <$from>\r\n" . ($replyTo !== '' ? "Reply-To: $replyTo\r\n" : '') . "MIME-Version: 1.0\r\nContent-Type: $ctype\r\nX-Mailer: ai-site-assistant";
    return @mail($to, $subjectEnc, $body, $h, '-f' . $from);
}

/** visitor-facing texts, by the visitor's language; {owner} is replaced with owner_name */
const AIS_TXT = [
    'de' => ['subject' => 'Dein Termin mit {owner} am %s um %s Uhr', 'h1' => 'Termin bestätigt', 'hi' => 'Hallo %s,', 'body' => 'dein Gespräch mit {owner} ist eingetragen.', 'when' => 'Wann', 'where' => 'Wo', 'cal' => 'Zum Kalender hinzufügen →', 'foot' => 'Du musst nichts weiter tun. Falls etwas dazwischenkommt, antworte einfach auf diese E-Mail.',
             'cancel_subject' => 'Dein Termin mit {owner} wurde abgesagt', 'cancel_body' => "Hallo %s,\n\nleider muss {owner} den Termin am %s um %s Uhr absagen. Schreib einfach zurück, dann finden wir einen neuen.\n"],
    'en' => ['subject' => 'Your appointment with {owner} on %s at %s', 'h1' => 'Appointment confirmed', 'hi' => 'Hi %s,', 'body' => 'your conversation with {owner} is booked.', 'when' => 'When', 'where' => 'Where', 'cal' => 'Add to calendar →', 'foot' => 'Nothing more to do. If something comes up, just reply to this e-mail.',
             'cancel_subject' => 'Your appointment with {owner} was cancelled', 'cancel_body' => "Hi %s,\n\nunfortunately {owner} has to cancel your appointment on %s at %s. Just reply and we will find a new one.\n"],
    'it' => ['subject' => 'Il tuo appuntamento con {owner} il %s alle %s', 'h1' => 'Appuntamento confermato', 'hi' => 'Ciao %s,', 'body' => 'la tua conversazione con {owner} è fissata.', 'when' => 'Quando', 'where' => 'Dove', 'cal' => 'Aggiungi al calendario →', 'foot' => 'Non devi fare altro. Se hai un imprevisto, rispondi semplicemente a questa e-mail.',
             'cancel_subject' => 'Il tuo appuntamento con {owner} è stato annullato', 'cancel_body' => "Ciao %s,\n\npurtroppo {owner} deve annullare l'appuntamento del %s alle %s. Rispondi a questa e-mail e ne troviamo un altro.\n"],
];

function ais_txt(string $lang): array {
    $t = AIS_TXT[in_array($lang, ['de', 'en', 'it'], true) ? $lang : 'en'];
    return array_map(static fn ($v) => str_replace('{owner}', ais_owner(), $v), $t);
}

/** mails to the visitor in the visitor's language (cancellation of a booking) */
function ais_cancel_mail(array $b): bool {
    if (!filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL)) { return false; }
    $t = ais_txt((string)($b['lang'] ?? 'en'));
    $d = date('d.m.Y', strtotime((string)$b['date']));
    return ais_mail((string)$b['email'], $t['cancel_subject'], 'text/plain; charset=UTF-8', sprintf($t['cancel_body'], $b['name'], $d, $b['time']), (string)ais_config()['notify_email']);
}

function ais_send_booking_mails(array $b, array $cfg): array {
    $lang = in_array($b['lang'] ?? 'en', ['de', 'en', 'it'], true) ? $b['lang'] : 'en';
    $t = ais_txt($lang);
    $place = (string)($cfg['booking']['place'] ?? '');
    $tzName = (string)ais_config()['timezone'];
    $calUrl = ais_site_url() . '/assistant-calendar.php?b=' . rawurlencode($b['id']) . '&h=' . ais_sign($b['id']);
    $whenTxt = date('d.m.Y', strtotime($b['date'])) . ', ' . $b['time'] . ' (' . $tzName . ')';
    $res = ['owner' => false, 'visitor' => null];

    // to the owner
    $subjO = 'New appointment via the assistant: ' . $b['name'] . ', ' . date('d.m.Y', strtotime($b['date'])) . ' ' . $b['time'];
    $textO = "New appointment via the AI assistant\n------------------------------------\nName:     {$b['name']}\nE-mail:   {$b['email']}\nWhen:     $whenTxt\nWhere:    $place\nTopic:    " . ($b['note'] ?? '') . "\nLanguage: $lang\n\nCalendar: $calUrl\nManage:   " . ais_site_url() . "/admin/chatbot.php?t=booking\n";
    $html = ais_mail_html('New appointment', [['Name', $b['name']], ['E-mail', '<a href="mailto:' . ais_h($b['email']) . '">' . ais_h($b['email']) . '</a>', true], ['When', $whenTxt], ['Where', $place], ['Language', $lang]],
        (string)($b['note'] ?? ''), $calUrl, 'Add to calendar →', 'Booked through the AI assistant. Cancel or review it in the admin (Booking & calendar).');
    [$ct, $body] = ais_mail_multipart($textO, $html);
    $res['owner'] = ais_mail((string)($cfg['mail']['notify'] ?? ais_config()['notify_email']), $subjO, $ct, $body, (string)$b['email']);

    // to the visitor, in the visitor's language
    if (!empty($cfg['booking']['confirmVisitor']) && filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $subjV = sprintf($t['subject'], date('d.m.Y', strtotime($b['date'])), $b['time']);
        $textV = sprintf($t['hi'], $b['name']) . "\n\n" . $t['body'] . "\n\n" . $t['when'] . ': ' . $whenTxt . "\n" . ($place !== '' ? $t['where'] . ': ' . $place . "\n" : '') . "\n" . $t['cal'] . ' ' . $calUrl . "\n\n" . $t['foot'] . "\n\n" . ais_owner() . ' · ' . ais_site_url() . "/\n";
        $rows = [[$t['when'], $whenTxt]];
        if ($place !== '') { $rows[] = [$t['where'], $place]; }
        $html = ais_mail_html($t['h1'], $rows, sprintf($t['hi'], $b['name']) . ' ' . $t['body'], $calUrl, $t['cal'], $t['foot']);
        [$ct, $body] = ais_mail_multipart($textV, $html);
        $res['visitor'] = ais_mail((string)$b['email'], $subjV, $ct, $body, (string)ais_config()['notify_email']);
    }
    return $res;
}

/* ================= CalDAV (Nextcloud): busy times in, bookings out ================= */

function ais_dav_cfg(): ?array {
    $j = json_decode((string)@file_get_contents(ais_priv() . '/assistant-caldav.json'), true);
    return is_array($j) && !empty($j['user']) && !empty($j['pass']) ? $j + ['server' => '', 'url' => '', 'name' => '', 'read' => true, 'write' => true, 'allday' => false] : null;
}

function ais_dav_save(array $d): bool {
    $f = ais_priv() . '/assistant-caldav.json';
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false || !@rename($tmp, $f)) { return false; }
    @chmod($f, 0600);
    return true;
}

/** https only, and never a private/loopback address (this server must not be used to probe its own network) */
function ais_dav_url_ok(string $url): bool {
    $p = parse_url($url);
    if (getenv('AIS_DAV_ALLOW_LOCAL') && is_array($p) && in_array($p['scheme'] ?? '', ['http', 'https'], true) && in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true)) { return true; }   // tests only
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) { return false; }
    $ip = gethostbyname($p['host']);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** @return array{0:int,1:string} [http code, body] */
function ais_dav_http(string $method, string $url, array $cfg, string $body = '', array $headers = []): array {
    if (!ais_dav_url_ok($url)) { return [0, 'url not allowed']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERPWD => $cfg['user'] . ':' . $cfg['pass'], CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    return [$code, is_string($res) ? $res : $err];
}

function ais_ics_unfold(string $s): array {
    return preg_split('/\r\n|\n|\r/', preg_replace('/\r?\n[ \t]/', '', $s) ?? $s) ?: [];
}

/** DTSTART/DTEND line -> [timestamp, isAllDay] */
function ais_ics_time(string $line): ?array {
    if (!preg_match('/^(?:DTSTART|DTEND)((?:;[^:]*)?):(.+)$/i', $line, $m)) { return null; }
    $params = $m[1]; $v = trim($m[2]);
    try {
        if (stripos($params, 'VALUE=DATE') !== false && !str_contains($v, 'T')) { return [(new DateTimeImmutable($v, ais_tz()))->getTimestamp(), true]; }
        $tz = ais_tz();
        if (preg_match('/TZID=([^;:]+)/i', $params, $z)) { try { $tz = new DateTimeZone(trim($z[1], '"')); } catch (Throwable $e) {} }
        if (str_ends_with($v, 'Z')) { return [(new DateTimeImmutable(substr($v, 0, -1), new DateTimeZone('UTC')))->getTimestamp(), false]; }
        return [(new DateTimeImmutable($v, $tz))->getTimestamp(), false];
    } catch (Throwable $e) { return null; }
}

/** busy intervals from one calendar-data blob (an expanded VCALENDAR) */
function ais_dav_parse(string $ics, bool $allday): array {
    $out = [];
    $cur = null;
    foreach (ais_ics_unfold($ics) as $l) {
        $u = strtoupper($l);
        if ($u === 'BEGIN:VEVENT') { $cur = ['s' => null, 'e' => null, 'ad' => false, 'skip' => false, 'dur' => null]; continue; }
        if ($u === 'END:VEVENT' && $cur) {
            if (!$cur['skip'] && $cur['s'] !== null && ($allday || !$cur['ad'])) {
                $e = $cur['e'] ?? ($cur['dur'] !== null ? $cur['s'] + $cur['dur'] : $cur['s'] + ($cur['ad'] ? 86400 : 3600));
                if ($e > $cur['s']) { $out[] = [$cur['s'], $e]; }
            }
            $cur = null; continue;
        }
        if (!$cur) { continue; }
        if (str_starts_with($u, 'DTSTART')) { if ($t = ais_ics_time($l)) { $cur['s'] = $t[0]; $cur['ad'] = $t[1]; } }
        elseif (str_starts_with($u, 'DTEND')) { if ($t = ais_ics_time($l)) { $cur['e'] = $t[0]; } }
        elseif (str_starts_with($u, 'DURATION:') && preg_match('/^DURATION:P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/i', $l, $m)) { $cur['dur'] = ((int)($m[1] ?? 0)) * 86400 + ((int)($m[2] ?? 0)) * 3600 + ((int)($m[3] ?? 0)) * 60; }
        elseif (str_starts_with($u, 'TRANSP:') && trim(substr($u, 7)) === 'TRANSPARENT') { $cur['skip'] = true; }
        elseif (str_starts_with($u, 'STATUS:') && trim(substr($u, 7)) === 'CANCELLED') { $cur['skip'] = true; }
    }
    return $out;
}

/** events of the connected calendar in the next $days days; recurrences are expanded by the server. Cached for 60 s. */
function ais_dav_busy(int $days = 21): array {
    $cfg = ais_dav_cfg();
    if (!$cfg || empty($cfg['read']) || $cfg['url'] === '') { return []; }
    $cache = ais_priv() . '/assistant-dav-cache.json';
    $c = json_decode((string)@file_get_contents($cache), true);
    if (is_array($c) && ($c['days'] ?? 0) >= $days && (time() - (int)($c['t'] ?? 0)) < 60 && ($c['url'] ?? '') === $cfg['url']) { return $c['busy']; }
    $from = gmdate('Ymd\T000000\Z');
    $to = gmdate('Ymd\T000000\Z', time() + ($days + 2) * 86400);
    $xml = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data><c:expand start="' . $from . '" end="' . $to . '"/></c:calendar-data></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . $from . '" end="' . $to . '"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
    [$code, $body] = ais_dav_http('REPORT', $cfg['url'], $cfg, $xml, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8']);
    if ($code !== 207) {   // unreachable: keep using the last known answer for up to 30 minutes, never block bookings
        if (is_array($c) && (time() - (int)($c['t'] ?? 0)) < 1800 && ($c['url'] ?? '') === $cfg['url']) { return $c['busy']; }
        return [];
    }
    $busy = [];
    if (preg_match_all('#<[a-z0-9]*:?calendar-data[^>]*>(.*?)</[a-z0-9]*:?calendar-data>#is', $body, $m)) {
        foreach ($m[1] as $blob) { array_push($busy, ...ais_dav_parse(html_entity_decode($blob, ENT_QUOTES | ENT_XML1, 'UTF-8'), !empty($cfg['allday']))); }
    }
    @file_put_contents($cache, json_encode(['t' => time(), 'days' => $days, 'url' => $cfg['url'], 'busy' => $busy]));
    @chmod($cache, 0600);
    return $busy;
}

/** calendars of the account: [['name' => ..., 'url' => ...], ...] or an error string */
function ais_dav_discover(string $server, string $user, string $pass) {
    $server = rtrim($server, '/');
    $cfg = ['user' => $user, 'pass' => $pass];
    $url = $server . '/remote.php/dav/calendars/' . rawurlencode($user) . '/';
    $xml = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:displayname/><d:resourcetype/><c:supported-calendar-component-set/></d:prop></d:propfind>';
    [$code, $body] = ais_dav_http('PROPFIND', $url, $cfg, $xml, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8']);
    if ($code === 401) { return 'Login refused (wrong user name or app password).'; }
    if ($code !== 207) { return $code === 0 ? 'Address invalid or unreachable (https and a public server are required).' : "The server answered HTTP $code."; }
    $cals = [];
    $dom = new DOMDocument();
    if (!@$dom->loadXML($body)) { return 'Invalid response from the server.'; }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('d', 'DAV:');
    $xp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    foreach ($xp->query('//d:response') as $r) {
        if (!$xp->query('.//d:resourcetype/c:calendar', $r)->length) { continue; }
        $vevent = false;
        foreach ($xp->query('.//c:supported-calendar-component-set/c:comp/@name', $r) as $a) { if (strtoupper($a->nodeValue) === 'VEVENT') { $vevent = true; } }
        if (!$vevent) { continue; }
        $href = $xp->query('d:href', $r)->item(0)?->nodeValue ?? '';
        $name = trim($xp->query('.//d:displayname', $r)->item(0)?->nodeValue ?? '') ?: basename(rtrim($href, '/'));
        $p = parse_url($server);
        $cals[] = ['name' => $name, 'url' => $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $href];
    }
    return $cals ?: 'No calendar that accepts events was found in this account.';
}

function ais_dav_test(): string {
    $cfg = ais_dav_cfg();
    if (!$cfg || $cfg['url'] === '') { return 'No calendar connected.'; }
    @unlink(ais_priv() . '/assistant-dav-cache.json');
    $from = gmdate('Ymd\T000000\Z');
    $xml = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . $from . '" end="' . gmdate('Ymd\T000000\Z', time() + 7 * 86400) . '"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
    [$code, $body] = ais_dav_http('REPORT', $cfg['url'], $cfg, $xml, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8']);
    if ($code === 401) { return 'Login refused.'; }
    if ($code === 404) { return 'Calendar not found (did its address change?).'; }
    if ($code !== 207) { return "The server answered HTTP $code."; }
    return 'Connected: ' . preg_match_all('#<[a-z0-9]*:?response>#i', $body) . ' events in the next 7 days in "' . $cfg['name'] . '".';
}

/** writes the booking as an event; returns the event URL or '' */
function ais_dav_push(array $b, array $cfg): string {
    $d = ais_dav_cfg();
    if (!$d || empty($d['write']) || $d['url'] === '') { return ''; }
    $href = rtrim($d['url'], '/') . '/dm-' . $b['id'] . '.ics';
    $ics = str_replace("METHOD:PUBLISH\r\n", '', ais_ics([$b], $cfg));
    $ics = str_replace("END:VEVENT\r\n", "STATUS:CONFIRMED\r\nEND:VEVENT\r\n", $ics);
    [$code] = ais_dav_http('PUT', $href, $d, $ics, ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *']);
    @unlink(ais_priv() . '/assistant-dav-cache.json');
    return in_array($code, [201, 204], true) ? $href : '';
}

function ais_dav_remove(array $b): bool {
    $d = ais_dav_cfg();
    if (!$d || empty($b['dav'])) { return false; }
    [$code] = ais_dav_http('DELETE', (string)$b['dav'], $d);
    @unlink(ais_priv() . '/assistant-dav-cache.json');
    return in_array($code, [200, 204, 404], true);
}


/* ================= Voice imports: a sample recorded/uploaded here, installed by the engine ================= */

function ais_voice_dir(): string { return ais_priv() . '/assistant-voices'; }
function ais_voices(): array {
    $j = json_decode((string)@file_get_contents(ais_priv() . '/assistant-voices.json'), true);
    return is_array($j) ? $j : [];
}
function ais_voices_update(callable $fn): bool {
    $f = ais_priv() . '/assistant-voices.json';
    $fp = @fopen($f . '.lock', 'c');
    if (!$fp) { return false; }
    flock($fp, LOCK_EX);
    $new = $fn(ais_voices());
    $ok = true;
    if (is_array($new)) {
        $tmp = $f . '.tmp';
        $ok = @file_put_contents($tmp, json_encode(array_values($new), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") !== false && @rename($tmp, $f);
        if ($ok) { @chmod($f, 0640); }
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
function ais_voice_slug(string $s): string {
    return substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'), 0, 40);
}
