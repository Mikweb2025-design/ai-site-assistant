<?php
/*
 * The assistant's bookings as an ICS calendar.
 *   ?k=<calendar token>     the whole feed (subscribe from your calendar app; token from admin > Booking & calendar)
 *   ?b=<id>&h=<signature>   one appointment (the "add to calendar" link in the confirmation mail)
 */
declare(strict_types=1);
require_once __DIR__ . '/admin/booking-lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$cfg = ais_settings();
$list = array_values(array_filter(ais_bookings(), static fn ($b) => ($b['status'] ?? 'confirmed') === 'confirmed'));
$k = (string)($_GET['k'] ?? '');
$b = (string)($_GET['b'] ?? '');
$h = (string)($_GET['h'] ?? '');
$events = null;
$tok = ais_cal_token();
if ($k !== '' && $tok !== '' && hash_equals($tok, $k)) {
    $events = $list;
    $file = 'appointments.ics';
} elseif ($b !== '' && ais_secret() !== '' && hash_equals(ais_sign($b), $h)) {
    $events = array_values(array_filter($list, static fn ($x) => $x['id'] === $b));
    $file = 'appointment.ics';
}
if ($events === null || ($b !== '' && !$events)) { http_response_code(404); header('Content-Type: text/plain'); echo 'not found'; exit; }
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . $file . '"');
echo ais_ics($events, $cfg);
