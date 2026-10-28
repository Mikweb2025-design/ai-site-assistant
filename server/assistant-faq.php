<?php
/*
 * Text-only fallback of the assistant: when the voice engine is off or busy, the widget asks this endpoint
 * instead. No AI model and no third party: the question is matched against the answers in data/faq.json
 * (or data/faq.default.json) plus the custom Q&A lines from admin > Knowledge. Nothing is stored.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/admin/booking-lib.php';

function ais_faq_out(int $code, array $p): void { http_response_code($code); echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { ais_faq_out(405, ['ok' => false, 'error' => 'method']); }

/* browsers from the site only (non-browser clients send no Origin) */
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $allowed = array_merge([ais_site_url()], array_map('strval', (array)ais_config()['allowed_origins']));
    $okOrigin = in_array(rtrim($origin, '/'), array_map(static fn ($o) => rtrim((string)$o, '/'), $allowed), true)
        || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
    if (!$okOrigin) { ais_faq_out(403, ['ok' => false, 'error' => 'origin']); }
}

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 6000), true);
$text = is_array($in) ? trim((string)($in['text'] ?? '')) : '';
$lang = is_array($in) && in_array(($in['lang'] ?? ''), ['de', 'en', 'it'], true) ? (string)$in['lang'] : 'en';
$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
if ($text === '') { ais_faq_out(422, ['ok' => false, 'error' => 'empty']); }
$text = mb_substr($text, 0, 400);

/* light limits: 30 questions per visitor per 10 minutes, 3000 per day */
$ip = ais_client_ip();
$f = sys_get_temp_dir() . '/ais-faq-' . md5($ip);
$hits = array_filter(array_map('intval', (array)@file($f, FILE_IGNORE_NEW_LINES)), static fn ($t) => $t > time() - 600);
if (count($hits) >= 30) { ais_faq_out(429, ['ok' => false, 'error' => 'rate']); }
$hits[] = time();
@file_put_contents($f, implode("\n", $hits));
$day = sys_get_temp_dir() . '/ais-faq-day-' . date('Ymd');
$n = (int)@file_get_contents($day);
if ($n >= 3000) { ais_faq_out(429, ['ok' => false, 'error' => 'cap']); }
@file_put_contents($day, (string)($n + 1));

$file = is_file(__DIR__ . '/data/faq.json') ? __DIR__ . '/data/faq.json' : __DIR__ . '/data/faq.default.json';
$faq = ais_jread($file);
$fill = static fn (string $s): string => str_replace(['{owner}', '{email}'], [ais_owner(), (string)ais_config()['notify_email']], $s);

$norm = static fn (string $s): string => ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}@. -]+/u', ' ', $s) ?? $s) . ' ';
$q = $norm($text);

/* custom Q&A from the admin: "keyword1, keyword2 => answer" per line (these win) */
foreach (preg_split('/\R/', (string)(ais_settings()['faq'] ?? '')) ?: [] as $line) {
    if (!str_contains($line, '=>')) { continue; }
    [$kw, $ans] = array_map('trim', explode('=>', $line, 2));
    foreach (explode(',', $kw) as $k) {
        $k = mb_strtolower(trim($k));
        if ($k !== '' && str_contains($q, $k) && $ans !== '') { ais_faq_out(200, ['ok' => true, 'reply' => mb_substr($ans, 0, 800), 'links' => [], 'mode' => 'faq']); }
    }
}

$best = null; $bestScore = 0;
foreach ((array)($faq['intents'] ?? []) as $intent) {
    $score = 0;
    foreach ((array)($intent['keywords'] ?? []) as $k) {
        $k = (string)$k;
        if ($k === '') { continue; }
        if (str_contains($q, ' ' . trim($k) . ' ') || (mb_strlen(trim($k)) >= 4 && str_contains($q, trim($k)))) { $score += mb_strlen(trim($k)) >= 6 ? 2 : 1; }
    }
    if (!empty($intent['short_only']) && mb_strlen(trim($text)) > 25) { $score = 0; }   // a greeting only counts as a short message
    if ($score > $bestScore) { $bestScore = $score; $best = $intent; }
}
if ($best === null) {
    $miss = (array)($faq['fallback'] ?? []);
    ais_faq_out(200, ['ok' => true, 'reply' => $fill((string)($miss[$lang] ?? $miss['en'] ?? 'Please write to ' . ais_config()['notify_email'] . '.')), 'links' => [], 'mode' => 'faq']);
}
$answers = (array)($best['answers'] ?? []);
$linkMap = (array)((ais_config()['faq_links'][$lang] ?? []));
$labels = (array)($faq['link_labels'] ?? []);
$links = [];
foreach ((array)($best['links'] ?? []) as $id) {
    $href = (string)($linkMap[$id] ?? '');
    if ($href !== '' && $href[0] === '/') { $links[] = ['label' => (string)($labels[$id][$lang] ?? $labels[$id]['en'] ?? $id), 'href' => $href]; }
}
ais_faq_out(200, ['ok' => true, 'reply' => $fill((string)($answers[$lang] ?? $answers['en'] ?? '')), 'links' => $links, 'mode' => 'faq']);
