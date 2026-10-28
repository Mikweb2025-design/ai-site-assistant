<?php
/*
 * Assistant settings: everything in one page, one tab per topic.
 *  - Widget (visible to visitors)            -> site_config_file (public JSON read by the widget loader, no secrets)
 *  - Behavior / voice / knowledge / booking / mail / limits -> <private_dir>/assistant-config.json, which the engine
 *    polls from /assistant-config.php with a token (the content is not public).
 * Needs the admin login (admin/login.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
ini_set('display_errors', '0');
// only the Voice tab loads a script (the microphone recorder)
ais_admin_require((($_GET['t'] ?? '') === 'voice') ? "'self'" : "'none'");
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/booking-lib.php';

$cfgAll   = ais_config();
$siteFile = (string)$cfgAll['site_config_file'];
$privDir  = ais_priv();
$botFile  = $privDir . '/assistant-config.json';
$tokFile  = $privDir . '/assistant-token';
$pullFile = $privDir . '/assistant-pulled';
$statusUrl = (string)$cfgAll['status_url'];
$siteUrl  = ais_site_url();

$tabs = ['status', 'widget', 'behavior', 'voice', 'knowledge', 'booking', 'mail', 'limits'];
$t = in_array($_GET['t'] ?? 'status', $tabs, true) ? (string)($_GET['t'] ?? 'status') : 'status';

$site = ais_jread($siteFile) + ['chat' => false, 'chatHome' => true, 'chatVoice' => true, 'chatName' => (string)$cfgAll['assistant_name'], 'chatBase' => '/assistant',
    'chatSide' => 'right', 'chatHide' => [], 'chatMobile' => true, 'chatColor' => '', 'chatBooking' => false, 'chatMessages' => false];
$bot = ais_settings();
$bot += [
    'voice' => '', 'voiceTemp' => 0,
    'greeting' => ['en' => '', 'de' => '', 'it' => ''],
    'langs' => ['en', 'de', 'it'],
    'style' => 'cordial', 'length' => 'normal', 'humor' => false, 'faq' => '', 'contact' => (string)$cfgAll['notify_email'], 'rules' => '',
    'knowledge' => ['extra' => '', 'llmsFull' => true, 'llms' => true, 'urls' => []],
    'limits' => ['perHour' => 0, 'perDay' => 200, 'durationMin' => 5, 'pauseSec' => 0, 'idleSec' => 30],
];
$bot['greeting'] += ['en' => '', 'de' => '', 'it' => ''];
$bot['knowledge'] += ['extra' => '', 'llmsFull' => true, 'llms' => true, 'urls' => []];
$bot['limits'] += ['perHour' => 0, 'perDay' => 200, 'durationMin' => 5, 'pauseSec' => 0, 'idleSec' => 30];

$msg = '';
$newToken = '';
$davFound = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = (string)($_POST['a'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'Session expired, reload the page.';
    } elseif ($a === 'quick') {
        $site['chat'] = !empty($_POST['chat']);
        $site['updated'] = date('c');
        $msg = ais_jwrite($siteFile, $site) ? ($site['chat'] ? 'Assistant on: it appears from the next page load.' : 'Assistant off: it no longer appears anywhere.') : 'Error: cannot write the site settings file.';
    } elseif ($a === 'token') {
        ais_ensure_private();
        $newToken = bin2hex(random_bytes(24));
        $okTok = @file_put_contents($tokFile, $newToken . "\n") !== false && @chmod($tokFile, 0640);
        $msg = $okTok ? 'Token generated: copy it now into the engine configuration (see below).' : 'Error: cannot save the token.';
        if (!$okTok) { $newToken = ''; }
    } elseif ($a === 'widget') {
        $site['chat'] = !empty($_POST['chat']);
        $site['chatHome'] = !empty($_POST['chatHome']);
        $site['chatVoice'] = !empty($_POST['chatVoice']);
        $site['chatMobile'] = !empty($_POST['chatMobile']);
        $name = trim(strip_tags((string)($_POST['chatName'] ?? '')));
        $site['chatName'] = $name !== '' ? mb_substr($name, 0, 40) : (string)$cfgAll['assistant_name'];
        $site['chatSide'] = ($_POST['chatSide'] ?? '') === 'left' ? 'left' : 'right';
        $col = trim((string)($_POST['chatColor'] ?? ''));
        $site['chatColor'] = preg_match('/^#[0-9a-fA-F]{6}$/', $col) ? strtolower($col) : '';
        $base = trim((string)($_POST['chatBase'] ?? ''));
        $site['chatBase'] = preg_match('#^(/[A-Za-z0-9._~/-]{1,60}|https://[A-Za-z0-9.-]+(:\d{2,5})?(/[A-Za-z0-9._~/-]{0,60})?)$#', $base) ? rtrim($base, '/') : '/assistant';
        $hide = [];
        foreach (preg_split('/\R/', (string)($_POST['chatHide'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^/[A-Za-z0-9._~/-]{0,100}$#', $line) && count($hide) < 30) { $hide[] = $line; }
        }
        $site['chatHide'] = array_values(array_unique($hide));
        $site['updated'] = date('c');
        $msg = ais_jwrite($siteFile, $site) ? 'Widget saved: it applies from the next page load.' : 'Error: cannot write the site settings file.';
    } elseif ($a === 'cancel') {
        $cid = (string)($_POST['id'] ?? '');
        $gone = null;
        ais_update_bookings(function (array $list) use ($cid, &$gone) {
            foreach ($list as &$b) { if ($b['id'] === $cid && ($b['status'] ?? 'confirmed') === 'confirmed') { $b['status'] = 'cancelled'; $gone = $b; } }
            return $gone ? $list : null;
        });
        if ($gone) {
            $msg = 'Appointment cancelled.' . (!empty($gone['dav']) && ais_dav_remove($gone) ? ' Event removed from the calendar.' : '');
            if (!empty($_POST['notify']) && filter_var($gone['email'], FILTER_VALIDATE_EMAIL)) {
                $msg .= ais_cancel_mail($gone) ? ' Visitor notified by e-mail.' : ' (Notifying the visitor failed.)';
            }
        } else { $msg = 'Appointment not found.'; }
    } elseif ($a === 'voiceup') {
        $name = ais_voice_slug((string)($_POST['vname'] ?? ''));
        $text = ais_clip((string)($_POST['vtext'] ?? ''), 1500);
        $f = $_FILES['vfile'] ?? null;
        $ext = is_array($f) ? strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) : '';
        if ($name === '' || $name === 'receptionist') { $msg = 'Enter a name for the voice (letters and numbers).'; }
        elseif (empty($_POST['consent'])) { $msg = 'Confirm that the voice is yours or that you have the speaker’s consent.'; }
        elseif (mb_strlen($text) < 40) { $msg = 'Enter exactly the text you read (at least one full sentence): without it the voice cannot be learned.'; }
        elseif (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) { $msg = 'No file arrived (12 MB at most).'; }
        elseif (!in_array($ext, ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'webm', 'mp4', 'flac', 'opus', 'aiff', 'aif', 'caf'], true)) { $msg = 'Unsupported format (wav, mp3, m4a, ogg, webm, flac, aiff).'; }
        elseif ((int)$f['size'] < 20000 || (int)$f['size'] > 12 * 1024 * 1024) { $msg = 'The file is too short or too large (a 20–30 second recording, 12 MB at most).'; }
        elseif (count(array_filter(ais_voices(), static fn ($v) => $v['name'] === $name && $v['status'] !== 'error')) > 0) { $msg = 'A voice with this name already exists: choose another name.'; }
        else {
            $dir = ais_voice_dir();
            if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
            $id = bin2hex(random_bytes(8));
            $file = $id . '.' . $ext;
            if (@move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $file)) {
                @chmod($dir . '/' . $file, 0640);
                ais_voices_update(static function (array $l) use ($id, $name, $text, $file) { $l[] = ['id' => $id, 'name' => $name, 'text' => $text, 'file' => $file, 'status' => 'pending', 'note' => '', 'created' => date('c')]; return $l; });
                $msg = 'Voice sent. The engine downloads and installs it within a minute; when it shows "installed" you can pick it in the Voice list.';
            } else { $msg = 'Error: cannot save the file on the server.'; }
        }
    } elseif ($a === 'voicedel') {
        $vid = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        ais_voices_update(static function (array $l) use ($vid) {
            foreach ($l as $k => $v) { if ($v['id'] === $vid) { @unlink(ais_voice_dir() . '/' . $v['file']); unset($l[$k]); return $l; } }
            return null;
        });
        $msg = 'Sample removed from the server (a voice already installed on the engine host stays there).';
    } elseif (in_array($a, ['davconnect', 'davuse', 'davopts', 'davtest', 'davdisconnect'], true)) {
        $dav = ais_dav_cfg() ?? ['server' => '', 'user' => '', 'pass' => '', 'url' => '', 'name' => '', 'read' => true, 'write' => true, 'allday' => false];
        if ($a === 'davconnect') {
            $srv = rtrim(trim((string)($_POST['server'] ?? '')), '/');
            $usr = trim((string)($_POST['user'] ?? ''));
            $pw = (string)($_POST['pass'] ?? '');
            if ($pw === '' && ($dav['user'] ?? '') === $usr) { $pw = (string)$dav['pass']; }
            if ($srv === '' || $usr === '' || $pw === '') { $msg = 'Fill in server address, user name and app password.'; }
            elseif (!ais_dav_url_ok($srv)) { $msg = 'Invalid address: https and a public server are required (e.g. https://cloud.example.com).'; }
            else {
                $found = ais_dav_discover($srv, $usr, $pw);
                if (is_array($found)) {
                    $dav = ['server' => $srv, 'user' => $usr, 'pass' => $pw, 'url' => ($dav['server'] ?? '') === $srv && ($dav['user'] ?? '') === $usr ? (string)$dav['url'] : '', 'name' => (string)$dav['name']] + $dav;
                    $davFound = $found;
                    ais_ensure_private();
                    $msg = ais_dav_save($dav) ? 'Connected. Choose the calendar to use.' : 'Error: cannot save the credentials.';
                } else { $msg = $found; }
            }
        } elseif ($a === 'davuse') {
            $u = (string)($_POST['url'] ?? '');
            if (ais_dav_url_ok($u) && str_starts_with($u, (string)$dav['server'])) {
                $dav['url'] = $u; $dav['name'] = ais_clip((string)($_POST['name'] ?? ''), 120);
                ais_dav_save($dav);
                $msg = ais_dav_test();
            } else { $msg = 'Invalid calendar.'; }
        } elseif ($a === 'davopts') {
            $dav['read'] = !empty($_POST['read']); $dav['write'] = !empty($_POST['write']); $dav['allday'] = !empty($_POST['allday']);
            $msg = ais_dav_save($dav) ? 'Calendar options saved.' : 'Error: cannot save.';
            @unlink(ais_priv() . '/assistant-dav-cache.json');
        } elseif ($a === 'davtest') {
            $msg = ais_dav_test();
        } else {
            @unlink(ais_priv() . '/assistant-caldav.json'); @unlink(ais_priv() . '/assistant-dav-cache.json');
            $msg = 'Calendar disconnected (the app password was deleted from the server).';
        }
    } elseif ($a === 'caltoken') {
        ais_ensure_private();
        $msg = @file_put_contents($privDir . '/assistant-cal-token', bin2hex(random_bytes(16)) . "\n") !== false && @chmod($privDir . '/assistant-cal-token', 0640) ? 'Calendar link generated (the old one no longer works).' : 'Error: cannot save the calendar token.';
    } elseif ($a === 'testmail') {
        $to = (string)($bot['mail']['notify'] ?? $cfgAll['notify_email']);
        $msg = ais_mail($to, 'Test: assistant mail works', 'text/plain; charset=UTF-8', "This is a test message from the assistant admin (Mail tab).\nTime: " . date('Y-m-d H:i:s') . "\n") ? "Test mail sent to $to." : 'Error: sending failed (check the smtp block in config.php, or the server mail setup).';
    } else {
        if ($a === 'voice') {
            $v = trim((string)($_POST['voice'] ?? ''));
            $bot['voice'] = preg_match('/^[A-Za-z0-9_.-]{1,80}\.wav$/', $v) ? $v : '';
            $t2 = (float)($_POST['voiceTemp'] ?? 0);
            $bot['voiceTemp'] = $t2 >= 0.5 && $t2 <= 1.0 ? round($t2, 2) : 0;
            $bot['faq'] = ais_clip((string)($_POST['faq'] ?? ''), 4000);
        } elseif ($a === 'booking') {
            $bk = $bot['booking'];
            $bk['enabled'] = !empty($_POST['enabled']);
            foreach (AIS_DAYS as $d) { $bk['hours'][$d] = ais_clip((string)($_POST['h_' . $d] ?? ''), 80); }
            $bk['durationMin'] = ais_int_in($_POST['durationMin'] ?? null, 5, 120, 20);
            $bk['bufferMin'] = ais_int_in($_POST['bufferMin'] ?? null, 0, 60, 10);
            $bk['noticeH'] = ais_int_in($_POST['noticeH'] ?? null, 0, 336, 24);
            $bk['horizonD'] = ais_int_in($_POST['horizonD'] ?? null, 1, 90, 21);
            $bk['blocked'] = ais_clip((string)($_POST['blocked'] ?? ''), 1000);
            $bk['place'] = ais_clip((string)($_POST['place'] ?? ''), 200);
            $bk['confirmVisitor'] = !empty($_POST['confirmVisitor']);
            $bot['booking'] = $bk;
            $site['chatBooking'] = (bool)$bk['enabled']; $site['updated'] = date('c'); ais_jwrite($siteFile, $site);
        } elseif ($a === 'mail') {
            $n = ais_clip((string)($_POST['notify'] ?? ''), 200);
            $site['chatMessages'] = !empty($_POST['messages']); $site['updated'] = date('c'); ais_jwrite($siteFile, $site);
            $bot['mail'] = ['messages' => !empty($_POST['messages']), 'notify' => filter_var($n, FILTER_VALIDATE_EMAIL) ? $n : (string)$cfgAll['notify_email']];
        } elseif ($a === 'behavior') {
            foreach (['en', 'de', 'it'] as $l) { $bot['greeting'][$l] = ais_clip((string)($_POST['g_' . $l] ?? ''), 200); }
            $langs = array_values(array_intersect(['en', 'de', 'it'], (array)($_POST['langs'] ?? [])));
            $bot['langs'] = $langs ?: ['en', 'de', 'it'];
            $bot['style'] = in_array($_POST['style'] ?? '', ['cordial', 'neutral', 'formal'], true) ? (string)$_POST['style'] : 'cordial';
            $bot['length'] = in_array($_POST['length'] ?? '', ['short', 'normal', 'long'], true) ? (string)$_POST['length'] : 'normal';
            $c = ais_clip((string)($_POST['contact'] ?? ''), 120);
            $bot['contact'] = filter_var($c, FILTER_VALIDATE_EMAIL) || preg_match('#^https://[A-Za-z0-9./?=&_~-]+$#', $c) ? $c : (string)$cfgAll['notify_email'];
            $bot['rules'] = ais_clip((string)($_POST['rules'] ?? ''), 2000);
            $bot['humor'] = !empty($_POST['humor']);
        } elseif ($a === 'knowledge') {
            $urls = [];
            $hosts = array_merge([ais_host()], array_map('strval', (array)$cfgAll['knowledge_hosts']));
            foreach (preg_split('/\R/', (string)($_POST['urls'] ?? '')) ?: [] as $line) {
                $line = trim($line);
                $h = (string)parse_url($line, PHP_URL_HOST);
                if ($h !== '' && str_starts_with($line, 'https://') && in_array($h, $hosts, true) && preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9./_~%?=&-]*$#', $line) && count($urls) < 8) { $urls[] = $line; }
            }
            $bot['knowledge'] = ['extra' => ais_clip((string)($_POST['extra'] ?? ''), 6000), 'llmsFull' => !empty($_POST['llmsFull']), 'llms' => !empty($_POST['llms']), 'urls' => array_values(array_unique($urls))];
        } elseif ($a === 'limits') {
            $bot['limits'] = [
                'perHour' => ais_int_in($_POST['perHour'] ?? null, 0, 100, 0), 'perDay' => ais_int_in($_POST['perDay'] ?? null, 0, 2000, 200),
                'durationMin' => ais_int_in($_POST['durationMin'] ?? null, 1, 15, 5), 'pauseSec' => ais_int_in($_POST['pauseSec'] ?? null, 0, 120, 0), 'idleSec' => ais_int_in($_POST['idleSec'] ?? null, 10, 300, 30),
            ];
        }
        if (in_array($a, ['behavior', 'knowledge', 'limits', 'voice', 'booking', 'mail'], true)) {
            ais_ensure_private();
            $bot['updated'] = date('c');
            ais_purge();
            $msg = ais_jwrite($botFile, $bot, 0640) ? 'Saved. The engine applies it within a minute, no restart needed.' : 'Error: cannot write assistant-config.json.';
        }
    }
}

// live status: the same road the widget uses (site -> reverse proxy -> engine)
$live = null;
if ($t === 'status' || $t === 'voice') {
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    $raw = $statusUrl !== '' ? @file_get_contents($statusUrl, false, $ctx) : false;
    $j = is_string($raw) ? json_decode($raw, true) : null;
    $live = is_array($j) && !empty($j['ok']) ? $j : null;
}
$hasToken = is_file($tokFile) && trim((string)@file_get_contents($tokFile)) !== '';
$pulled = is_file($pullFile) ? (int)@file_get_contents($pullFile) : 0;
$csrf = ais_h((string)$_SESSION['csrf']);
$on = (bool)$site['chat'];
$yn = static fn (bool $b): string => '<span class="state ' . ($b ? 'on' : 'off') . '">' . ($b ? 'YES' : 'NO') . '</span>';
$chk = static fn (bool $b): string => $b ? ' checked' : '';
$h = 'ais_h';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Assistant settings</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;background:#0a0c10;color:#d7dde6;font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
a{color:#ffb454;text-decoration:none}.wrap{max-width:900px;margin:0 auto;padding:24px 18px 60px}
.panel{background:#10141b;border:1px solid #1e2633;border-radius:12px;padding:18px;margin-bottom:14px}
.panel h2{margin:0 0 12px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8b96a5}
.state{font-weight:700}.on{color:#5ce8c8}.off{color:#ff7b72}
button{font:inherit;cursor:pointer;border-radius:8px;padding:9px 16px;border:1px solid #ffb454;background:#ffb454;color:#141414;font-weight:700}
button.ghost{background:transparent;color:#ffb454}
.note{font-size:12.5px;color:#7a8394;margin:10px 0 0}.msg{color:#5ce8c8}
label.c{display:flex;gap:10px;align-items:center;cursor:pointer}input[type=checkbox]{width:18px;height:18px;accent-color:#ffb454}
select,input[type=text],input[type=number],input[type=password],textarea{font:inherit;background:#0a0c10;color:#d7dde6;border:1px solid #1e2633;border-radius:8px;padding:7px 10px}
textarea{width:100%;min-height:110px;resize:vertical}input[type=text]{min-width:280px;max-width:100%}input[type=number]{width:90px}
.field{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 0;border-top:1px solid #1e2633}
.field:first-of-type{border-top:0}.field.col{display:block}.field.col>span{display:block;margin-bottom:6px}
.actions{margin-top:14px;text-align:right}
code,.tok{background:#0a0c10;border:1px solid #1e2633;border-radius:6px;padding:1px 6px;word-break:break-all}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:#ff7b72}.dot.ok{background:#5ce8c8}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}.k{padding:10px;border:1px solid #1e2633;border-radius:10px}.k b{display:block;font-size:18px;color:#fff}
</style>
</head>
<body>
<div class="wrap">
  <?php echo ais_admin_nav('bot', $t); ?>
  <?php if ($msg !== ''): ?><div class="panel msg"><?php echo $h($msg); ?></div><?php endif; ?>

<?php if ($t === 'status'): ?>
  <div class="panel">
    <h2>Switch</h2>
    <form method="post" class="field" style="border:0;padding:0">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="quick">
      <label class="c"><input type="checkbox" name="chat" value="1"<?php echo $chk($on); ?>> Assistant switched on for the site</label>
      <span><span class="state <?php echo $on ? 'on' : 'off'; ?>"><?php echo $on ? 'ON' : 'OFF'; ?></span> <button>Apply</button></span>
    </form>
    <p class="note">Off = the widget files are not even loaded, and the assistant sections of your privacy page (if you added them) stay hidden. To hide it only on the home page or on some pages: see the <a href="chatbot.php?t=widget">Widget</a> tab.</p>
  </div>
  <div class="panel">
    <h2>Engine (right now)</h2>
    <?php if ($live): ?>
      <p style="margin:0 0 10px"><span class="dot ok"></span><b>Reachable</b> — <?php echo !empty($live['voice']) ? 'voice ready' : 'voice engine off'; ?><?php echo !empty($live['busy']) ? ', a conversation is in progress' : ''; ?>.</p>
      <div class="grid">
        <div class="k">Languages<b><?php echo $h(strtoupper(implode(' ', (array)($live['languages'] ?? [])))); ?></b></div>
        <div class="k">Conversations today<b><?php echo (int)($live['callsToday'] ?? 0); ?> / <?php echo (int)($live['dailyLimit'] ?? 0) ?: '∞'; ?></b></div>
        <div class="k">Maximum length<b><?php echo (int)(($live['maxDurationS'] ?? 0) / 60); ?> min</b></div>
      </div>
    <?php else: ?>
      <p style="margin:0"><span class="dot"></span><b>Not reachable.</b> The engine host is off, the engine is not running, or the port forwarding / reverse proxy is not set up. Visitors then get the text-only fallback.</p>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2>Connecting the engine to these settings</h2>
    <div class="field"><span>Token</span><span><?php echo $hasToken ? '<span class="state on">present</span>' : '<span class="state off">not generated yet</span>'; ?></span></div>
    <div class="field"><span>Last read by the engine</span><span><?php echo $pulled ? $h(date('Y-m-d H:i:s', $pulled)) : 'never'; ?></span></div>
    <?php if ($newToken !== ''): ?>
      <p class="note" style="color:#d7dde6">In the engine's <code>.env</code>:</p>
      <p><span class="tok">ASSISTANT_CONFIG_URL=<?php echo $h($siteUrl); ?>/assistant-config.php</span><br><span class="tok">ASSISTANT_TOKEN=<?php echo $h($newToken); ?></span></p>
      <p class="note">The token is shown only now. Then restart the engine.</p>
    <?php endif; ?>
    <form method="post" class="actions" style="text-align:left"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="token">
      <button class="ghost"><?php echo $hasToken ? 'Regenerate token (invalidates the old one)' : 'Generate token'; ?></button></form>
    <p class="note">Behavior, knowledge and limits live in a private file on this server (not in a public page); the engine reads it every minute with the token and applies it without a restart.</p>
  </div>

<?php elseif ($t === 'widget'): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="widget">
  <div class="panel">
    <h2>Where and how it appears</h2>
    <div class="field"><label class="c"><input type="checkbox" name="chat" value="1"<?php echo $chk($on); ?>> Assistant active on the site</label><?php echo $yn($on); ?></div>
    <div class="field"><label class="c"><input type="checkbox" name="chatHome" value="1"<?php echo $chk((bool)$site['chatHome']); ?>> Show it on the home page too</label><?php echo $yn((bool)$site['chatHome']); ?></div>
    <div class="field"><label class="c"><input type="checkbox" name="chatMobile" value="1"<?php echo $chk((bool)$site['chatMobile']); ?>> Show it on phones too</label><?php echo $yn((bool)$site['chatMobile']); ?></div>
    <div class="field"><span>Side of the screen</span>
      <select name="chatSide"><option value="right"<?php echo $site['chatSide'] === 'right' ? ' selected' : ''; ?>>Bottom right</option><option value="left"<?php echo $site['chatSide'] === 'left' ? ' selected' : ''; ?>>Bottom left</option></select></div>
    <div class="field col"><span>Pages where it must NOT appear (one per line, e.g. <code>/imprint/</code>, or <code>/blog/</code> for the whole blog)</span>
      <textarea name="chatHide" style="min-height:80px" placeholder="/imprint/&#10;/privacy/"><?php echo $h(implode("\n", (array)$site['chatHide'])); ?></textarea></div>
  </div>
  <div class="panel">
    <h2>Call and look</h2>
    <div class="field"><label class="c"><input type="checkbox" name="chatVoice" value="1"<?php echo $chk((bool)$site['chatVoice']); ?>> Voice call from the browser</label><?php echo $yn((bool)$site['chatVoice']); ?></div>
    <div class="field"><span>Accent color (empty = the site's own)</span><input type="text" name="chatColor" maxlength="7" style="min-width:110px;width:110px" placeholder="#ffb454" value="<?php echo $h((string)$site['chatColor']); ?>"></div>
    <div class="field"><span>Name shown</span><input type="text" name="chatName" value="<?php echo $h((string)$site['chatName']); ?>" maxlength="40"></div>
    <div class="field"><span>Engine address</span><input type="text" name="chatBase" value="<?php echo $h((string)$site['chatBase']); ?>" maxlength="120"></div>
    <p class="note">Default <code>/assistant</code>: your reverse proxy forwards it to the engine (see <code>nginx/assistant.conf.sample</code>). The bubble is always visible; the dot shows whether the engine answers (green) or not (grey, text-only fallback). Language and interface texts follow the page (de, en, it).</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>

<?php elseif ($t === 'behavior'): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="behavior">
  <div class="panel">
    <h2>Greeting (spoken and written when the chat opens)</h2>
    <?php foreach (['en' => 'English', 'de' => 'German', 'it' => 'Italian'] as $l => $nm): ?>
    <div class="field col"><span><?php echo $nm; ?></span><input type="text" style="width:100%" name="g_<?php echo $l; ?>" maxlength="200" value="<?php echo $h((string)$bot['greeting'][$l]); ?>" placeholder="empty = default greeting"></div>
    <?php endforeach; ?>
    <p class="note">The greeting is re-synthesized with the voice on the first conversation after a change.</p>
  </div>
  <div class="panel">
    <h2>Languages and style</h2>
    <div class="field"><span>Active languages</span><span>
      <?php foreach (['en' => 'English', 'de' => 'German', 'it' => 'Italian'] as $l => $nm): ?>
      <label class="c" style="display:inline-flex;margin-left:12px"><input type="checkbox" name="langs[]" value="<?php echo $l; ?>"<?php echo $chk(in_array($l, (array)$bot['langs'], true)); ?>> <?php echo $nm; ?></label>
      <?php endforeach; ?></span></div>
    <div class="field"><span>Tone</span>
      <select name="style"><?php foreach (['cordial' => 'Friendly and direct', 'neutral' => 'Neutral and sober', 'formal' => 'Formal (Sie / Lei)'] as $k => $nm): ?><option value="<?php echo $k; ?>"<?php echo $bot['style'] === $k ? ' selected' : ''; ?>><?php echo $nm; ?></option><?php endforeach; ?></select></div>
    <div class="field"><span>Answer length</span>
      <select name="length"><?php foreach (['short' => 'Short (about 25 words)', 'normal' => 'Normal (about 40)', 'long' => 'Longer (about 70)'] as $k => $nm): ?><option value="<?php echo $k; ?>"<?php echo $bot['length'] === $k ? ' selected' : ''; ?>><?php echo $nm; ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="c"><input type="checkbox" name="humor" value="1"<?php echo $chk((bool)$bot['humor']); ?>> A touch of dry humor when the visitor jokes</label><?php echo $yn((bool)$bot['humor']); ?></div>
    <div class="field"><span>Contact to point to</span><input type="text" name="contact" maxlength="120" value="<?php echo $h((string)$bot['contact']); ?>"></div>
  </div>
  <div class="panel">
    <h2>Extra rules</h2>
    <textarea name="rules" maxlength="2000" placeholder="E.g.: If asked about interviews, say that I am open to roles in Berlin. Do not talk about my current employer beyond what is on the site."><?php echo $h((string)$bot['rules']); ?></textarea>
    <p class="note">These are added to the built-in rules (only the owner and the site, no invented facts, an honest "I am an AI"). They cannot remove them.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>

<?php elseif ($t === 'voice'): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="voice">
  <div class="panel">
    <h2>Assistant voice</h2>
    <?php $voices = is_array($live['voices'] ?? null) ? $live['voices'] : []; ?>
    <div class="field"><span>Voice</span>
      <select name="voice">
        <option value="">Engine default</option>
        <?php foreach ($voices as $vv): ?><option value="<?php echo $h((string)$vv); ?>"<?php echo $bot['voice'] === $vv ? ' selected' : ''; ?>><?php echo $h(preg_replace('/\.wav$/', '', (string)$vv)); ?></option><?php endforeach; ?>
        <?php if ($bot['voice'] !== '' && !in_array($bot['voice'], $voices, true)): ?><option value="<?php echo $h((string)$bot['voice']); ?>" selected><?php echo $h((string)$bot['voice']); ?> (engine not reachable)</option><?php endif; ?>
      </select></div>
    <div class="field"><span>Expressiveness</span>
      <select name="voiceTemp"><?php foreach (['0' => 'Default', '0.6' => 'Calmer and more regular', '0.72' => 'Natural', '0.85' => 'More expressive'] as $k => $nm): ?><option value="<?php echo $k; ?>"<?php echo (string)(float)$bot['voiceTemp'] === (string)(float)$k ? ' selected' : ''; ?>><?php echo $nm; ?></option><?php endforeach; ?></select></div>
    <?php if (!$live): ?><p class="note" style="color:#ffb454">The engine does not answer: the list of voices is not available right now, a choice already saved stays valid.</p><?php endif; ?>
    <p class="note">The voices are the samples installed on the engine host. To add a new one, for example your own: record or upload it in the panel below; the engine installs it by itself. Only clone voices you have consent for. The voice changes from the next conversation.</p>
  </div>
  <div class="panel">
    <h2>Answers when the engine is off (text-only mode)</h2>
    <textarea name="faq" maxlength="4000" placeholder="hours, when are you free => I usually reply within two working days.&#10;salary, rate => I discuss that directly: write me."><?php echo $h((string)$bot['faq']); ?></textarea>
    <p class="note">When the engine is off, busy or unreachable, the chat keeps answering from this server, without an AI model: it picks the best answer from the ones written for your site (<code>data/faq.json</code>). Add your own here, one per line: keywords separated by commas, then <code>=&gt;</code> and the answer. Yours win over the built-in ones.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>
  <div class="panel">
    <h2>New voice: record or upload</h2>
    <?php foreach (array_reverse(ais_voices()) as $iv): ?>
      <form method="post" class="field">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="voicedel"><input type="hidden" name="id" value="<?php echo $h((string)$iv['id']); ?>">
        <span><b><?php echo $h((string)$iv['name']); ?></b> · <?php echo $iv['status'] === 'installed' ? '<span class="state on">installed</span>' : ($iv['status'] === 'error' ? '<span class="state off">error: ' . $h((string)$iv['note']) . '</span>' : '<span class="state" style="color:#ffb454">waiting for the engine…</span>'); ?></span>
        <button class="ghost">Remove</button>
      </form>
    <?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" id="voiceform">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="voiceup">
      <div class="field col"><span>1. Read this text aloud (about 25 seconds, natural tone, no hurry) — or replace it with the text you will read</span>
        <textarea name="vtext" id="vtext" style="min-height:120px">Hello, this is <?php echo $h(ais_owner()); ?>. I have worked with technology for many years and I like to explain things in a simple way. If you have a question about my work, my projects or this website, just ask: I will try to answer clearly and without detours. When I do not know something, I say so, and I tell you how to write to me directly.</textarea></div>
      <div class="field"><span>2. Record with the microphone</span>
        <span><button type="button" id="vrec">● Record</button> <button type="button" id="vstop" class="ghost" disabled>■ Stop</button> <span id="vtime" class="note"></span></span></div>
      <div class="field"><span>…or upload an audio file</span><input type="file" name="vfile" id="vfile" accept="audio/*,.wav,.mp3,.m4a,.ogg,.webm,.flac"></div>
      <audio id="vplay" controls hidden style="width:100%;margin:6px 0"></audio>
      <div class="field"><span>3. Name of the voice</span><input type="text" name="vname" maxlength="40" placeholder="my-voice" style="min-width:200px"></div>
      <div class="field"><label class="c"><input type="checkbox" name="consent" value="1"> It is my own voice, or I have the speaker's consent</label></div>
      <div class="actions"><button>Send to the engine for cloning</button></div>
    </form>
    <p class="note">The sample is downloaded by the engine, converted and installed as an available voice; then pick it in the list above. The text must match what you say word for word. Record in a quiet place, without "um"s and without music. The file stays in a private folder on the server until you remove it. Always tell visitors that they are talking to an AI.</p>
  </div>
  <script src="voice-rec.js?v=1"></script>

<?php elseif ($t === 'knowledge'): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="knowledge">
  <div class="panel">
    <h2>Where it learns from</h2>
    <div class="field"><label class="c"><input type="checkbox" name="llmsFull" value="1"<?php echo $chk((bool)$bot['knowledge']['llmsFull']); ?>> The full profile of the site (<code>/llms-full.txt</code>)</label><?php echo $yn((bool)$bot['knowledge']['llmsFull']); ?></div>
    <div class="field"><label class="c"><input type="checkbox" name="llms" value="1"<?php echo $chk((bool)$bot['knowledge']['llms']); ?>> The index of topics and articles (<code>/llms.txt</code>)</label><?php echo $yn((bool)$bot['knowledge']['llms']); ?></div>
    <div class="field col"><span>Other pages of the site to read (one per line, only <?php echo $h(ais_host()); ?>, at most 8)</span>
      <textarea name="urls" style="min-height:80px" placeholder="<?php echo $h($siteUrl); ?>/about/"><?php echo $h(implode("\n", (array)$bot['knowledge']['urls'])); ?></textarea></div>
    <p class="note">The engine re-reads the sources every six hours.</p>
  </div>
  <div class="panel">
    <h2>Extra information (for the assistant only)</h2>
    <textarea name="extra" maxlength="6000" placeholder="Facts that are not on the site and that you want it to know, e.g.: available from January, prefers hybrid work, languages: German C1 …"><?php echo $h((string)$bot['knowledge']['extra']); ?></textarea>
    <p class="note">The assistant may repeat anything written here to visitors: do not put anything confidential in it.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>

<?php elseif ($t === 'booking'): ?>
  <?php $bk = $bot['booking']; $slots = ais_slots($bot); $calTok = ais_cal_token(); $upc = array_values(array_filter(ais_bookings(), static fn ($b) => ($b['status'] ?? 'confirmed') === 'confirmed' && strtotime($b['date'] . ' ' . $b['time'] . ' +' . (int)$b['durationMin'] . ' minutes') > time())); usort($upc, static fn ($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time'])); ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="booking">
  <div class="panel">
    <h2>Appointments through the assistant</h2>
    <div class="field"><label class="c"><input type="checkbox" name="enabled" value="1"<?php echo $chk((bool)$bk['enabled']); ?>> The assistant can book a conversation with you</label><?php echo $yn((bool)$bk['enabled']); ?></div>
    <div class="field"><span>Length (minutes)</span><input type="number" name="durationMin" min="5" max="120" value="<?php echo (int)$bk['durationMin']; ?>"></div>
    <div class="field"><span>Gap between appointments (minutes)</span><input type="number" name="bufferMin" min="0" max="60" value="<?php echo (int)$bk['bufferMin']; ?>"></div>
    <div class="field"><span>Minimum notice (hours)</span><input type="number" name="noticeH" min="0" max="336" value="<?php echo (int)$bk['noticeH']; ?>"></div>
    <div class="field"><span>How many days ahead</span><input type="number" name="horizonD" min="1" max="90" value="<?php echo (int)$bk['horizonD']; ?>"></div>
    <div class="field col"><span>Where / how (goes into the confirmation mail)</span><input type="text" style="width:100%" name="place" maxlength="200" value="<?php echo $h((string)$bk['place']); ?>"></div>
    <div class="field"><label class="c"><input type="checkbox" name="confirmVisitor" value="1"<?php echo $chk((bool)$bk['confirmVisitor']); ?>> Send the visitor a confirmation mail (with a calendar link)</label><?php echo $yn((bool)$bk['confirmVisitor']); ?></div>
  </div>
  <div class="panel">
    <h2>When you are available (time zone <?php echo $h((string)$cfgAll['timezone']); ?>)</h2>
    <?php foreach (['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'] as $d => $nm): ?>
    <div class="field"><span><?php echo $nm; ?></span><input type="text" name="h_<?php echo $d; ?>" maxlength="80" value="<?php echo $h((string)$bk['hours'][$d]); ?>" placeholder="closed"></div>
    <?php endforeach; ?>
    <div class="field col"><span>Days off (one per line: <code>2026-12-24</code> or a range <code>2026-12-23..2027-01-02</code>)</span>
      <textarea name="blocked" style="min-height:70px"><?php echo $h((string)$bk['blocked']); ?></textarea></div>
    <p class="note">Hours format: <code>09:00-12:00, 14:00-17:00</code>. Only free times are offered: already booked, inside the notice period or on days off never appear.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>
  <div class="panel">
    <h2>Next free times the assistant offers</h2>
    <?php if (!$bk['enabled']): ?><p class="note" style="margin:0">Feature is off.</p>
    <?php elseif (!$slots): ?><p class="note" style="margin:0;color:#ff7b72">No free time with these settings.</p>
    <?php else: $byDay = []; foreach (array_slice($slots, 0, 40) as $sl) { $byDay[$sl['date']][] = $sl['time']; } foreach (array_slice($byDay, 0, 6, true) as $dd => $tt): ?>
      <div class="field"><span><?php echo $h(date('D d M', strtotime($dd))); ?></span><span><?php echo $h(implode('  ', $tt)); ?></span></div>
    <?php endforeach; endif; ?>
  </div>
  <?php $dv = ais_dav_cfg(); ?>
  <div class="panel">
    <h2>CalDAV calendar (Nextcloud and others)</h2>
    <?php if ($dv && $dv['url'] !== ''): ?>
      <div class="field"><span>Connected to <b><?php echo $h((string)$dv['name']); ?></b><br><span class="note" style="margin:0"><?php echo $h((string)$dv['user']); ?> @ <?php echo $h((string)$dv['server']); ?></span></span><?php echo $yn(true); ?></div>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="davopts">
        <div class="field"><label class="c"><input type="checkbox" name="read" value="1"<?php echo $chk(!empty($dv['read'])); ?>> Events in this calendar count as "busy" (the assistant does not offer those times)</label></div>
        <div class="field"><label class="c"><input type="checkbox" name="write" value="1"<?php echo $chk(!empty($dv['write'])); ?>> Write booked appointments here (and remove them when cancelled)</label></div>
        <div class="field"><label class="c"><input type="checkbox" name="allday" value="1"<?php echo $chk(!empty($dv['allday'])); ?>> All-day events block too (birthdays and holidays usually should not)</label></div>
        <div class="actions" style="text-align:left"><button>Save options</button></div>
      </form>
      <div class="row" style="display:flex;gap:8px;margin-top:10px">
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="davtest"><button class="ghost">Test the connection</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="davdisconnect"><button class="ghost">Disconnect</button></form>
      </div>
    <?php else: ?>
      <?php if (!empty($davFound)): ?>
        <p style="margin:0 0 8px">Calendars found: choose the one for appointments.</p>
        <?php foreach ($davFound as $cl): ?>
          <form method="post" class="field"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="davuse"><input type="hidden" name="url" value="<?php echo $h($cl['url']); ?>"><input type="hidden" name="name" value="<?php echo $h($cl['name']); ?>">
            <span><?php echo $h($cl['name']); ?></span><button>Use this one</button></form>
        <?php endforeach; ?>
      <?php endif; ?>
      <form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="davconnect">
        <div class="field"><span>Server address</span><input type="text" name="server" placeholder="https://cloud.example.com" value="<?php echo $h((string)($dv['server'] ?? '')); ?>"></div>
        <div class="field"><span>User</span><input type="text" name="user" autocomplete="off" value="<?php echo $h((string)($dv['user'] ?? '')); ?>"></div>
        <div class="field"><span>App password</span><input type="password" name="pass" autocomplete="new-password" placeholder="<?php echo $dv ? '(already saved)' : ''; ?>" style="min-width:280px"></div>
        <div class="actions"><button>Connect and find calendars</button></div>
      </form>
    <?php endif; ?>
    <p class="note">In Nextcloud: Settings → Security → "Create new app password". Do not use your main password. The app password is kept in a private file on the server (mode 600) and is never shown again. With this connection the assistant avoids your existing events and writes each appointment into the chosen calendar. Any public CalDAV server over https works; automatic calendar discovery is built for Nextcloud.</p>
  </div>
  <div class="panel">
    <h2>Calendar feed</h2>
    <?php if ($calTok !== ''): ?>
      <p style="margin:0 0 8px">Subscribe from Apple Calendar, Google, Nextcloud or Outlook with this address:</p>
      <p><span class="tok"><?php echo $h($siteUrl); ?>/assistant-calendar.php?k=<?php echo $h($calTok); ?></span></p>
    <?php else: ?><p style="margin:0 0 8px">No calendar link yet.</p><?php endif; ?>
    <form method="post" class="actions" style="text-align:left"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="caltoken"><button class="ghost"><?php echo $calTok !== '' ? 'Regenerate the link (invalidates the old one)' : 'Generate the link'; ?></button></form>
    <p class="note">Every new appointment appears in your calendar by itself. The visitor gets an "Add to calendar" link in the mail, for their own appointment only.</p>
  </div>
  <div class="panel">
    <h2>Upcoming appointments (<?php echo count($upc); ?>)</h2>
    <?php if (!$upc): ?><p class="note" style="margin:0">None.</p><?php endif; ?>
    <?php foreach ($upc as $b): ?>
      <form method="post" class="field">
        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?php echo $h((string)$b['id']); ?>">
        <span><b><?php echo $h(date('D d M Y', strtotime($b['date']))); ?> <?php echo $h((string)$b['time']); ?></b> · <?php echo $h((string)$b['name']); ?> · <a href="mailto:<?php echo $h((string)$b['email']); ?>"><?php echo $h((string)$b['email']); ?></a><?php echo $b['note'] !== '' ? '<br><span class="note" style="margin:0">' . $h((string)$b['note']) . '</span>' : ''; ?></span>
        <span><label class="c" style="display:inline-flex"><input type="checkbox" name="notify" value="1" checked> notify</label> <button class="ghost">Cancel</button></span>
      </form>
    <?php endforeach; ?>
  </div>

<?php elseif ($t === 'mail'): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="mail">
  <div class="panel">
    <h2>Messages for <?php echo $h(ais_owner()); ?></h2>
    <div class="field"><label class="c"><input type="checkbox" name="messages" value="1"<?php echo $chk((bool)$bot['mail']['messages']); ?>> The assistant can pass on a visitor's message</label><?php echo $yn((bool)$bot['mail']['messages']); ?></div>
    <div class="field"><span>Mails arrive at</span><input type="text" name="notify" maxlength="200" value="<?php echo $h((string)$bot['mail']['notify']); ?>"></div>
    <p class="note">It works like a contact form: the visitor's message arrives as a normal mail with Reply-To set to the visitor, so you answer by replying. The assistant collects name, e-mail and message, repeats them, and only sends after the visitor's explicit "yes". Appointment notifications go to the same address.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>
  <div class="panel">
    <h2>Test</h2>
    <div class="field"><span>Sending</span><span><?php $sm = $cfgAll['smtp']; echo (is_array($sm) && !empty($sm['host'])) ? '<span class="state on">SMTP (' . $h((string)$sm['host']) . ')</span>' : '<span class="state off">no smtp block in config.php: using PHP mail()</span>'; ?></span></div>
    <form method="post" class="actions" style="text-align:left"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="testmail"><button class="ghost">Send a test mail</button></form>
  </div>

<?php else: ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="a" value="limits">
  <div class="panel">
    <h2>Limits against abuse</h2>
    <div class="field"><span>Conversations per person per hour (0 = no limit)</span><input type="number" name="perHour" min="0" max="100" value="<?php echo (int)$bot['limits']['perHour']; ?>"></div>
    <div class="field"><span>Conversations in total per day (0 = no limit)</span><input type="number" name="perDay" min="0" max="2000" value="<?php echo (int)$bot['limits']['perDay']; ?>"></div>
    <div class="field"><span>Maximum length of a conversation (minutes)</span><input type="number" name="durationMin" min="1" max="15" value="<?php echo (int)$bot['limits']['durationMin']; ?>"></div>
    <div class="field"><span>Hang up after (seconds of silence)</span><input type="number" name="idleSec" min="10" max="300" value="<?php echo (int)$bot['limits']['idleSec']; ?>"></div>
    <div class="field"><span>Pause between two conversations of the same person (seconds, 0 = none)</span><input type="number" name="pauseSec" min="0" max="120" value="<?php echo (int)$bot['limits']['pauseSec']; ?>"></div>
    <p class="note">If nobody speaks or writes for that long, the call is closed and the line is freed; the chat keeps working and reconnects by itself on the next question, with context. The engine handles one conversation at a time: whoever arrives while it is busy gets the text-only fallback. When the daily limit is reached the engine reports itself unavailable until tomorrow. With 0 the limit is removed: every conversation uses your AI model and your engine host.</p>
  </div>
  <div class="panel">
    <h2>Privacy (how this stack behaves)</h2>
    <p class="note" style="margin-top:0;color:#d7dde6">Conversation content is <b>not stored</b> (logs keep only time and length). Audio is processed on the engine host and never stored. The recognized text is sent to the AI model your engine is configured with. A visitor's IP is kept only in memory for the limits. No cookies. Bookings (name, e-mail, topic, time) are kept in a private file and deleted 90 days after the appointment. Update your privacy policy to match what you switch on.</p>
  </div>
  <div class="actions"><button>Save</button></div>
  </form>
<?php endif; ?>
</div>
</body>
</html>
