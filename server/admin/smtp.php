<?php
/* Minimal SMTP client (implicit TLS / STARTTLS-less "tls://" streams, AUTH LOGIN). Used when config.php has an 'smtp' block. */
declare(strict_types=1);

function ais_smtp_send(array $cfg, string $to, string $subject, string $body, array $headers): bool
{
    $uri = (($cfg['secure'] ?? 'ssl') === 'tls' ? 'tls://' : 'ssl://') . $cfg['host'] . ':' . (int)($cfg['port'] ?? 465);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp  = @stream_socket_client($uri, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("ai-site-assistant: smtp connect failed: $errstr");
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        return $out;
    };
    $cmd = static function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };
    $r = $read();
    if (strpos($r, '220') !== 0) { fclose($fp); return false; }
    $r = $cmd('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (strpos($r, '250') !== 0) { fclose($fp); return false; }
    $r = $cmd('AUTH LOGIN');
    if (strpos($r, '334') !== 0) { fclose($fp); return false; }
    $r = $cmd(base64_encode((string)$cfg['user']));
    if (strpos($r, '334') !== 0) { fclose($fp); return false; }
    $r = $cmd(base64_encode((string)$cfg['pass']));
    if (strpos($r, '235') !== 0) { error_log('ai-site-assistant: smtp auth failed'); fclose($fp); return false; }
    $from = (string)($cfg['from'] ?? $cfg['user']);
    $r = $cmd('MAIL FROM:<' . $from . '>');
    if (strpos($r, '250') !== 0) { fclose($fp); return false; }
    $r = $cmd('RCPT TO:<' . $to . '>');
    if (strpos($r, '250') !== 0 && strpos($r, '251') !== 0) { fclose($fp); return false; }
    $r = $cmd('DATA');
    if (strpos($r, '354') !== 0) { fclose($fp); return false; }
    $data = "Subject: $subject\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
    $data = str_replace("\n", "\r\n", $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $r  = $read();
    $ok = strpos($r, '250') === 0;
    $cmd('QUIT');
    fclose($fp);
    return $ok;
}
