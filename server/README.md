# Server (PHP backend + admin)

The part of ai-site-assistant that lives on your **website's web server**. It has no framework and no database: plain PHP files and a private folder of JSON files.

It does five things:

| Job | Where |
|---|---|
| Admin UI for every setting (widget, behavior, voice, knowledge, booking & calendar, mail, limits) | `admin/` |
| Settings feed that the voice engine polls (about once a minute, token protected) | `assistant-config.php` |
| Bookings: free slots from your opening hours + CalDAV busy times, storage, ICS feed, confirmation mails | `assistant-booking.php`, `assistant-calendar.php`, `admin/booking-lib.php` |
| Visitor messages, sent like a contact-form mail | `assistant-booking.php` (`type: message`) |
| Text-only fallback chat when the engine is off or busy (no AI model, no third party) | `assistant-faq.php`, `data/faq*.json` |
| Voice samples recorded/uploaded in the admin and installed by the engine | `assistant-voice.php`, `admin/voice-rec.js` |

## Requirements

- PHP 8.1 or newer with the `curl`, `dom`, `json` and `mbstring` extensions (all standard).
- Any web server that runs PHP (Apache, nginx + php-fpm, Caddy, `php -S` for a quick try).
- HTTPS in production. A **private folder outside the web root**, writable by the PHP user.
- Outgoing mail: either PHP's `mail()` works on the host, or an SMTP account you put in `config.php`.

## Install

1. Copy the contents of `server/` into your web root (or a sub-folder: the files only use relative paths).
2. `cp config.sample.php config.php` and edit it: at least `site_url`, `owner_name`, `notify_email`, `private_dir`, `site_config_file`, and `admin.password_hash`:

   ```sh
   php -r 'echo password_hash("a-long-password", PASSWORD_DEFAULT), PHP_EOL;'
   ```
3. Create the private folder and give the PHP user write access (the example is for Debian/Ubuntu):

   ```sh
   mkdir -p /var/www/private && chown www-data: /var/www/private && chmod 750 /var/www/private
   ```
4. Open `https://your-site/admin/login.php`, sign in, go to **Status** and press **Generate token**. Put the shown values into the engine's `.env` (`ASSISTANT_CONFIG_URL` and `ASSISTANT_TOKEN`).
5. Add the reverse-proxy block from `../nginx/assistant.conf.sample` so that `/assistant/...` reaches the engine, and add the widget loader to your pages (see `../widget/` and `../examples/`).
6. Copy `data/faq.default.json` to `data/faq.json` and replace every `[edit me]` with your own facts (this is what the text-only fallback answers with).

Everything is switched **off** until you switch it on in the admin (Status → Switch).

## Configuration (`config.php`)

| Key | Meaning |
|---|---|
| `site_url` | Public address, no trailing slash. Used in mails, calendar links and the allowed browser origin. |
| `owner_name`, `assistant_name` | Who the assistant speaks about; the short name shown in the widget header. |
| `notify_email`, `from_email`, `from_name` | Where bookings/messages go, and the sender of the mails. |
| `smtp` | `null` (use `mail()`) or `['host','port','secure' => 'ssl'\|'tls','user','pass']`. |
| `timezone` | Time zone of the opening hours you enter (PHP identifier). |
| `private_dir` | Tokens, settings, bookings, CalDAV credentials, voice samples. **Outside the web root.** |
| `site_config_file` | The public JSON the widget loader reads (`/site-config.json`). Written by admin → Widget. |
| `status_url` | Engine status through your proxy, e.g. `https://example.com/assistant/status`. |
| `allowed_origins`, `knowledge_hosts` | Extra browser origins for the fallback endpoint; extra hosts accepted in admin → Knowledge. |
| `trust_proxy` | `true` only behind a proxy that sets `X-Forwarded-For`. |
| `faq_links` | Per language: link id → path for the "chips" under fallback answers. |
| `admin` | `username` and `password_hash` of the single admin user. |

For tests, these environment variables override paths: `AIS_CONFIG`, `AIS_PRIVATE_DIR`, `AIS_SITE_CONFIG`, `AIS_ASSISTANT_STATUS_URL`, and `AIS_DAV_ALLOW_LOCAL=1` (lets the CalDAV client talk to `127.0.0.1`; never set it in production).

## Files

```
config.sample.php         documented configuration
admin/
  bootstrap.php           config loader, helpers, admin session
  login.php, index.php    sign in / sign out, start page with a setup check
  chatbot.php             all settings, one tab per topic
  nav.php                 admin menu
  booking-lib.php         settings, slots, bookings, ICS, mail, CalDAV, voice imports
  mail-template.php       small responsive HTML mail template
  smtp.php                minimal SMTP client (only used with an `smtp` block)
  voice-rec.js            in-browser voice recorder (Voice tab only)
assistant-config.php      settings feed for the engine
assistant-booking.php     booking + visitor message (called by the engine)
assistant-calendar.php    ICS feed (whole calendar, or one appointment)
assistant-faq.php         text-only fallback chat
assistant-voice.php       voice samples to/from the engine
data/faq.default.json     generic fallback answers (en/de/it) with [edit me] markers
data/faq.example-daniele.json   a worked example with real answers
tests/smoke.sh            end-to-end smoke test (see below)
```

## Endpoints and tokens

Calls from the **engine** carry the header `X-Assistant-Token: <token>` (generated in admin → Status; stored in `private_dir/assistant-token`). Without a valid token these endpoints answer **404** and nothing else, so they cannot be discovered.

| Endpoint | Method | Purpose |
|---|---|---|
| `/assistant-config.php` | GET | Settings JSON: `greeting`, `langs`, `style`, `length`, `humor`, `contact`, `rules`, `knowledge`, `limits{perHour,perDay,durationMin,pauseSec,idleSec}`, `voice`, `voiceTemp`, `faq`, `bookingFeed{enabled,durationMin,place,slots[]}`, `messagesEnabled`, `voiceImports[]`. No e-mail addresses, no visitor data. |
| `/assistant-booking.php` | POST JSON | `{type:"booking", name, email, date, time, note, lang, ip}` or `{type:"message", name, email, message, lang, ip}` → `{ok, …}` or `{ok:false, error}` (`slot_taken`, `disabled`, `daily`, …). Hard cap: 40 actions per day. |
| `/assistant-voice.php` | GET / POST | `GET ?id=` downloads an uploaded sample; `POST {id, status:"installed"\|"error", note}` reports the installation. |
| `/assistant-calendar.php` | GET | `?k=<calendar token>` the whole ICS feed (subscribe from a calendar app); `?b=<id>&h=<signature>` one appointment (the link in the confirmation mail). Different token and a per-appointment HMAC, so a mail link never exposes other appointments. |
| `/assistant-faq.php` | POST JSON | `{lang, text}` → `{ok, reply, links[]}`. Public (called by the widget), same-origin only, 30 questions per visitor per 10 minutes. |

The **engine** itself exposes `GET <status_url>` returning `{ok, voice, busy, languages[], maxDurationS, callsToday, dailyLimit, voices[]}` (shown in admin → Status/Voice) and the WebSocket the widget uses; see `../docs/`.

### `site-config.json` (written by admin → Widget, read by the widget loader)

```json
{
  "chat": true,          "chatHome": true,     "chatVoice": true,   "chatMobile": true,
  "chatName": "Jane",    "chatBase": "/assistant",
  "chatSide": "right",   "chatHide": ["/imprint/"],  "chatColor": "",
  "chatBooking": false,  "chatMessages": false,
  "chatV": 1
}
```

`chat` is the master switch; `chatBooking` / `chatMessages` mirror the Booking and Mail switches (so a privacy page can show matching sections); `chatV` is a cache-buster number for the widget files: bump it by hand in the file after you change the widget (the admin keeps keys it does not know).

## Security notes

- **Tokens**: engine endpoints need `X-Assistant-Token`; compare is constant-time (`hash_equals`); wrong or missing token = 404.
- **Private folder** outside the web root holds every secret and every visitor record. Files are written with mode 640 (CalDAV credentials: **600**, never shown again in the UI).
- **Admin**: one user, `password_hash`, CSRF token on every form, session cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` over HTTPS), failed logins throttled (5 per 10 minutes per IP), strict CSP (`script-src 'none'` everywhere except the Voice tab, which loads one same-origin script).
- **CalDAV**: https only, and the server must resolve to a **public IP** (no loopback/private ranges), so the admin cannot be used to probe your internal network. Use an app password, not your account password.
- **Mail**: header injection is blocked (names with line breaks are rejected, addresses are validated); the visitor's address is only ever used as `Reply-To`/recipient of their own confirmation.
- **Rate limits**: 40 booking/message actions per day in total (independent of the engine), 30 fallback questions per visitor per 10 minutes, 3000 per day.
- **Retention**: bookings are deleted automatically **90 days after the appointment** (done every time the engine polls the feed and whenever you save settings).
- **Not stored here**: conversation content. This backend never sees it, except the one message a visitor explicitly asks the assistant to send you.

## What is stored (in `private_dir`)

| File | Content |
|---|---|
| `assistant-token`, `assistant-cal-token` | Engine token; ICS feed token |
| `assistant-config.json` | All admin settings except the widget ones |
| `assistant-bookings.json` | Name, e-mail, topic, time, language of each booking (purged after 90 days) |
| `assistant-caldav.json`, `assistant-dav-cache.json` | CalDAV credentials (mode 600) and a 60-second cache of busy times |
| `assistant-voices.json`, `assistant-voices/` | Voice samples sent from the admin and their install status |
| `assistant-pulled`, `assistant-actions-*.count`, `login-attempts.json` | Last engine poll time; daily action counters; login throttle |

## Smoke test

```sh
server/tests/smoke.sh
```

Starts `php -S` on port 8099 with a throwaway config and a fake `sendmail`, then checks: login (wrong/right password), all eight admin tabs, token generation, saving every settings tab, the token-protected feed (and that it hides addresses), a booking (and the double-booking refusal), a visitor message, the three mails, the ICS feed and single-appointment link, the FAQ fallback (answers, links, foreign origin refused) and the voice upload → feed → download → status round trip. Prints `ALL OK` and exits 0 when everything passes.
