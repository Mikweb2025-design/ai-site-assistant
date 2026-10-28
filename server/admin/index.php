<?php
/* Admin start page: a short health check and the way into the assistant settings. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
ais_admin_require();
require_once __DIR__ . '/nav.php';

$priv = ais_priv();
$checks = [
    'Private folder exists and is writable' => is_dir($priv) && is_writable($priv),
    'Engine token generated (Status tab)' => is_file($priv . '/assistant-token') && trim((string)@file_get_contents($priv . '/assistant-token')) !== '',
    'Settings file can be written' => is_writable(dirname((string)ais_config()['site_config_file'])) || is_writable((string)ais_config()['site_config_file']),
    'config.php present' => is_file(getenv('AIS_CONFIG') ?: dirname(__DIR__) . '/config.php'),
    'PHP extensions curl, dom, json' => extension_loaded('curl') && extension_loaded('dom') && extension_loaded('json'),
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Assistant admin</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;background:#0a0c10;color:#d7dde6;font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
a{color:#ffb454;text-decoration:none}.wrap{max-width:900px;margin:0 auto;padding:24px 18px 60px}
.panel{background:#10141b;border:1px solid #1e2633;border-radius:12px;padding:18px;margin-bottom:14px}
.panel h2{margin:0 0 12px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8b96a5}
.ok{color:#5ce8c8}.bad{color:#ff7b72}li{margin:4px 0}
</style>
</head>
<body>
<div class="wrap">
  <?php echo ais_admin_nav('home'); ?>
  <div class="panel">
    <h2>Setup check</h2>
    <ul style="margin:0;padding-left:18px">
      <?php foreach ($checks as $label => $ok): ?><li><span class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? '✓' : '✗'; ?></span> <?php echo ais_h($label); ?></li><?php endforeach; ?>
    </ul>
  </div>
  <div class="panel">
    <h2>Assistant</h2>
    <p style="margin:0">Everything is configured in <a href="chatbot.php">Assistant settings</a>: switch it on, choose where it appears, tune its behavior and voice, connect a calendar and mail.</p>
  </div>
</div>
</body>
</html>
