<?php
/*
 * Admin menu: a top bar (home, assistant, site, sign out) and, inside the assistant settings, one row of tabs.
 * Plain links only (the admin CSP allows no scripts on most pages).
 * Usage: echo ais_admin_nav('bot', 'limits');   // or ais_admin_nav('home')
 */
declare(strict_types=1);

function ais_admin_nav(string $active = 'bot', string $sub = ''): string
{
    $tabs = [
        'status'    => ['Status', 'chatbot.php'],
        'widget'    => ['Widget', 'chatbot.php?t=widget'],
        'behavior'  => ['Behavior', 'chatbot.php?t=behavior'],
        'voice'     => ['Voice', 'chatbot.php?t=voice'],
        'knowledge' => ['Knowledge', 'chatbot.php?t=knowledge'],
        'booking'   => ['Booking & calendar', 'chatbot.php?t=booking'],
        'mail'      => ['Mail', 'chatbot.php?t=mail'],
        'limits'    => ['Limits & privacy', 'chatbot.php?t=limits'],
    ];
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $o = '<style>'
        . '.aisnav{margin:0 0 18px;font:13px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
        . '.aisnav .l1,.aisnav .l2{display:flex;flex-wrap:wrap;gap:6px;align-items:center}'
        . '.aisnav .l1{padding-bottom:10px;border-bottom:1px solid #1e2633}.aisnav .l2{padding-top:10px}'
        . '.aisnav a{color:#8b96a5;text-decoration:none;padding:6px 12px;border:1px solid #1e2633;border-radius:8px;background:#10141b}'
        . '.aisnav a:hover{color:#d7dde6;border-color:#2e3746}'
        . '.aisnav .l1 a.on{color:#141414;background:#ffb454;border-color:#ffb454;font-weight:700}'
        . '.aisnav .l2 a{padding:4px 10px;border-radius:999px;background:transparent}.aisnav .l2 a.on{color:#ffb454;border-color:#ffb454}'
        . '.aisnav .sp{flex:1}.aisnav .x{border-style:dashed}'
        . '</style><nav class="aisnav" aria-label="Admin"><div class="l1">'
        . '<a' . ($active === 'home' ? ' class="on" aria-current="page"' : '') . ' href="index.php">Home</a>'
        . '<a' . ($active === 'bot' ? ' class="on" aria-current="page"' : '') . ' href="chatbot.php">Assistant</a>'
        . '<span class="sp"></span><a class="x" href="' . $e(ais_site_url()) . '/" target="_blank" rel="noopener">↗ Site</a><a class="x" href="login.php?logout=1">Sign out</a></div>';
    if ($active === 'bot') {
        $o .= '<div class="l2">';
        foreach ($tabs as $k => [$label, $href]) {
            $on = $sub !== '' ? $k === $sub : $k === 'status';
            $o .= '<a' . ($on ? ' class="on" aria-current="page"' : '') . ' href="' . $e($href) . '">' . $e($label) . '</a>';
        }
        $o .= '</div>';
    }
    return $o . '</nav>';
}
