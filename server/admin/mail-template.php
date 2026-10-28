<?php
/* Small responsive HTML mail template used for bookings and visitor messages (no external assets, inline styles only). */
declare(strict_types=1);

/**
 * @param array<int,array{0:string,1:string,2?:bool}> $rows label/value rows; a true third item means the value is already safe HTML
 */
function ais_mail_html(string $title, array $rows, string $bodyText = '', string $buttonUrl = '', string $buttonLabel = '', string $footer = ''): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $tr = '';
    foreach ($rows as $r) {
        $tr .= '<tr><td style="padding:6px 14px 6px 0;color:#6b7280;vertical-align:top;white-space:nowrap">' . $e($r[0]) . '</td>'
            . '<td style="padding:6px 0;color:#111827">' . (!empty($r[2]) ? $r[1] : $e($r[1])) . '</td></tr>';
    }
    $body = $bodyText !== '' ? '<div style="margin:16px 0;padding:12px 14px;background:#f3f4f6;border-radius:8px;color:#111827;white-space:pre-wrap">' . $e($bodyText) . '</div>' : '';
    $btn = $buttonUrl !== '' ? '<p style="margin:20px 0 4px"><a href="' . $e($buttonUrl) . '" style="display:inline-block;padding:10px 16px;background:#b45309;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600">' . $e($buttonLabel) . '</a></p>' : '';
    $foot = $footer !== '' ? '<p style="margin:22px 0 0;font-size:12px;color:#6b7280">' . $e($footer) . '</p>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#e5e7eb;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px 24px 20px">'
        . '<h1 style="margin:0 0 12px;font-size:20px;color:#111827">' . $e($title) . '</h1>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;font-size:15px">' . $tr . '</table>'
        . $body . $btn . $foot . '</div></body></html>';
}

/** [content type, body] for a text + HTML alternative */
function ais_mail_multipart(string $text, string $html): array
{
    $b = 'ais_' . bin2hex(random_bytes(12));
    $enc = static fn (string $s): string => chunk_split(base64_encode($s), 76, "\r\n");
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $enc($text)
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $enc($html)
          . "--$b--\r\n";
    return ["multipart/alternative; boundary=\"$b\"", $body];
}
