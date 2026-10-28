<?php
/* Admin login (one user from config.php) and logout (?logout=1). */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
ais_admin_headers();
ais_admin_session();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['ais_auth'])) { header('Location: index.php'); exit; }

$admin = ais_config()['admin'];
$msg = '';
$throttle = ais_priv() . '/login-attempts.json';
$ip = ais_client_ip();
$recent = array_values(array_filter((array)ais_jread($throttle)[$ip] ?? [], static fn ($t) => $t > time() - 600));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'Session expired, please try again.';
    } elseif (count($recent) >= 5) {
        http_response_code(429);
        $msg = 'Too many attempts. Wait ten minutes.';
    } elseif ($admin['password_hash'] === '') {
        $msg = 'No admin password is configured yet (see config.sample.php).';
    } elseif (hash_equals((string)$admin['username'], (string)($_POST['user'] ?? '')) && password_verify((string)($_POST['pass'] ?? ''), (string)$admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ais_auth'] = 1;
        header('Location: index.php');
        exit;
    } else {
        usleep(400000);
        $recent[] = time();
        ais_ensure_private();
        $all = ais_jread($throttle);
        $all[$ip] = $recent;
        foreach ($all as $k => $v) { if (!array_filter((array)$v, static fn ($t) => $t > time() - 600)) { unset($all[$k]); } }
        ais_jwrite($throttle, $all, 0600);
        $msg = 'Wrong user name or password.';
    }
}
$csrf = ais_h((string)$_SESSION['csrf']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Assistant admin — sign in</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0c10;color:#d7dde6;font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
form{width:min(360px,92vw);background:#10141b;border:1px solid #1e2633;border-radius:12px;padding:22px}
h1{margin:0 0 14px;font-size:15px;color:#8b96a5;letter-spacing:.08em;text-transform:uppercase}
label{display:block;margin:10px 0 4px;color:#8b96a5;font-size:12px}
input{width:100%;font:inherit;background:#0a0c10;color:#d7dde6;border:1px solid #1e2633;border-radius:8px;padding:9px 10px}
button{margin-top:16px;width:100%;font:inherit;cursor:pointer;border-radius:8px;padding:10px;border:1px solid #ffb454;background:#ffb454;color:#141414;font-weight:700}
.err{margin:0 0 10px;color:#ff7b72}
</style>
</head>
<body>
<form method="post" autocomplete="off">
  <h1>Assistant admin</h1>
  <?php if ($msg !== ''): ?><p class="err"><?php echo ais_h($msg); ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
  <label for="user">User</label><input id="user" name="user" autofocus>
  <label for="pass">Password</label><input id="pass" name="pass" type="password" autocomplete="current-password">
  <button>Sign in</button>
</form>
</body>
</html>
