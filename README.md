# ai-site-assistant

**A small, discreet AI assistant for your own website** — a floating bubble on every page that opens a chat with an animated portrait,
answers questions about you and your site, takes calls in the browser, books appointments into your calendar and forwards messages
like a contact form. The brain can run **entirely on your own machine**.

<p align="center">
  <img src="docs/screenshots/live-deployment.png" alt="The assistant on a real website" width="860">
</p>

* **Chat + voice call in the browser**, in the language of the page (German, English, Italian out of the box).
* **Animated avatar** from one photo: breathing, blinking, head movement, a jaw and mouth driven by the voice, different attitudes for listening / thinking / speaking.
* **Answers only about you and your site**, from your own public text. It does not invent facts, makes no promises on your behalf, and always says it is an AI.
* **Bookings**: free slots from your opening hours, minus your busy times from **Nextcloud / CalDAV**; confirmation mails; an **ICS feed** for your calendar app.
* **Messages for you** that arrive exactly like a contact-form mail (Reply-To = the visitor).
* **A full admin** (English): widget, behaviour, voice, knowledge, booking & calendar, mail, limits. Voice: record in the browser or upload a sample; the engine converts it with ffmpeg and installs it as a new voice.
* **Works when your computer is off**: the widget falls back to text answers served from your own web server — no AI model, no third party.
* **Privacy by construction**: conversations are not stored, audio stays on your machine, no cookies, nothing loaded unless you switch it on, the privacy-policy paragraphs appear only when the features are on.

## How it fits together

<p align="center"><img src="docs/architecture.svg" alt="Architecture diagram" width="900"></p>

The browser only ever talks to **your own site**. An nginx proxy forwards `/assistant/*` to an **engine** on a machine you control
(for example a Mac at home). The engine **pulls its settings from your site** every minute with a token — there is no inbound
write channel — and calls your site's PHP endpoints to book an appointment or send a message. Switch the engine off and the
widget keeps working in text-only mode.

```mermaid
sequenceDiagram
    participant V as Visitor (widget)
    participant S as Your site (nginx + PHP)
    participant E as Engine (your machine)
    participant C as Nextcloud / SMTP
    E->>S: pull settings + free slots (every minute, token)
    V->>S: GET /assistant/status
    S->>E: proxy
    E-->>V: {voice, text, busy, languages}
    V->>S: WebSocket /assistant/ws
    S->>E: proxy (origin checked)
    E-->>V: greeting (text + audio)
    V->>E: "Can we talk next week?"  (text or 8 kHz µ-law audio)
    E-->>V: three free slots
    V->>E: name, e-mail, topic
    E-->>V: recap — "Does that work?"        (the system speaks it, not the model)
    V->>E: "Yes"
    E->>S: POST assistant-booking.php
    S->>C: check busy times · write the event · send two mails
    S-->>E: ok | slot_taken
    E-->>V: the real outcome ("confirmed" or "that slot was just taken")
```

### Why the system, not the model, owns actions

While testing, the language model once told a visitor *"appointment confirmed"* without having booked anything. So a model marker
(`<<BOOK|…>>`) now only makes an action **pending**; the engine speaks the recap if the model skipped it; **only an explicit yes in the
next visitor message executes it**; and the **real outcome replaces whatever the model said**. See `engine/lib/dialog.mjs` and
`test/run.mjs`.

## Screenshots

| | |
|---|---|
| ![closed](docs/screenshots/widget-closed.png) **The bubble** — small, with a nudge once per session; a green dot means the engine answers. | ![chat](docs/screenshots/widget-chat.png) **Chat** — answers in the visitor's language, from your site's text. |
| ![fallback](docs/screenshots/widget-text-fallback.png) **Text-only fallback** when the engine is off or busy — no AI model. | ![mobile](docs/screenshots/widget-mobile.png) **Mobile** — full-screen sheet. |

Admin (English UI):

| | | |
|---|---|---|
| ![status](docs/screenshots/admin-status.png) Status, switch, token | ![widget](docs/screenshots/admin-widget.png) Widget: where it appears | ![behavior](docs/screenshots/admin-behavior.png) Behaviour: greeting, tone, rules |
| ![voice](docs/screenshots/admin-voice.png) Voice: record or upload a sample | ![knowledge](docs/screenshots/admin-knowledge.png) Knowledge + offline answers | ![booking](docs/screenshots/admin-booking.png) Booking, CalDAV, ICS feed |
| ![mail](docs/screenshots/admin-mail.png) Mail | ![limits](docs/screenshots/admin-limits.png) Limits & privacy | ![login](docs/screenshots/admin-login.png) Login |

## Repository layout

```
widget/        chat.js · chat.css · mic-worklet.js · avatar.svg      the floating widget (vanilla JS, no build step)
server/        PHP 8 backend + admin (config.sample.php, README.md)    settings, bookings, calendar, mail, offline answers
engine/lib/    guests · remote-config · persona · dialog · actions …   the engine's logic, independent of any audio stack
mock-engine/   a runnable text-only engine (local brain via Ollama)   implements docs/protocol.md
nginx/         proxy sample
examples/      loader.js (what to put on your pages) · demo site
docs/          architecture.svg · protocol.md · roadmap.md · screenshots/
test/          engine logic tests        server/tests/smoke.sh   backend smoke test
scripts/       dev.mjs (one-command demo) · screenshots.mjs · bench-brain.sh · ask.mjs
```

## Quick start (5 minutes, nothing leaves your machine)

Requirements: Node 20+, PHP 8.1+ (`curl`, `dom`), optionally [Ollama](https://ollama.com) with any small chat model.

```bash
git clone https://github.com/Mikweb2025-design/ai-site-assistant.git
cd ai-site-assistant
npm install
npm run demo -- --ollama llama3.2:3b      # or just: npm run demo   (a fixed-sentence "demo brain", no model needed)
```

Open <http://localhost:8080> — the bubble is bottom right. The admin is at <http://localhost:8080/admin/> (`admin` / `dev-password`).
The demo starts three things: the PHP backend, the reference engine and a small web server that proxies like nginx would.

## Put it on your own site

1. **Backend.** Copy `server/` next to your site (any PHP 8.1+ host), copy `config.sample.php` to `config.php`, set `site_url`, `owner_name`, the mail addresses and an admin password hash. Details in [`server/README.md`](server/README.md).
2. **Widget.** Copy `widget/` to `/assets/ais/` and put `examples/loader.js` in a script that runs on every page. It reads `/site-config.json` (written by the admin) and loads nothing when the assistant is off.
3. **Proxy.** Add [`nginx/assistant.conf.sample`](nginx/assistant.conf.sample) so `/assistant/status` and `/assistant/ws` reach the engine, and allow the microphone for your own origin: `Permissions-Policy: microphone=(self)`.
4. **Engine.** On the machine that will think and talk:
   ```bash
   ASSISTANT_OWNER="Your Name" \
   ASSISTANT_ORIGINS=https://your-site.example \
   ASSISTANT_CONFIG_URL=https://your-site.example/assistant-config.php \
   ASSISTANT_TOKEN=<token from admin → Status> \
   ASSISTANT_KNOWLEDGE=https://your-site.example/llms-full.txt \
   node mock-engine/server.mjs
   ```
5. In the admin, switch the assistant on. Everything else (greeting, tone, opening hours, CalDAV, voice, limits) is set there and applied by the engine within a minute.

### The engine

`mock-engine/` is a complete **text** engine: guest gate (allowed origins, limits, one line at a time, hang up after silence, resume),
persona prompt, a pluggable OpenAI-compatible brain (**local Ollama by default**), the system-owned booking/message flow and the
voice-sample installer. It does **not** include speech recognition or synthesis.

The author's live deployment adds a local voice pipeline (Parakeet speech-to-text, Qwen3-TTS with a cloned voice, MLX on Apple silicon)
behind the same protocol. That pipeline was built on a fork of a third-party project that has no licence, so it is **not** part of this
repository. The protocol it speaks is documented in [`docs/protocol.md`](docs/protocol.md) (Twilio-Media-Streams-shaped messages:
8 kHz µ-law frames, `mark`, `clear`, plus `text`), so any STT/TTS stack can be attached; adapters are very welcome.

## Privacy and security

* **No conversation content is stored** — at most time and length in technical logs. Audio never leaves the engine machine. IP addresses are used only in memory for the limits.
* **Token-protected endpoints** (`X-Assistant-Token`); the engine pulls settings, the site never writes to the engine. `private/` lives outside the web root; the CalDAV app password is stored mode `0600` and never shown again; CalDAV discovery only accepts `https` to public addresses.
* **Booking data** (name, e-mail, topic, time) is kept for 90 days after the appointment, then deleted automatically.
* **Visitor-side**: no cookies, no third-party requests, nothing loaded while the assistant is switched off. Sample privacy-policy paragraphs hide/show with the features — see `examples/loader.js`.
* **Voice cloning**: only clone voices you have consent for (your own, ideally), and always say that the speaker is an AI. The widget does.
* The text of a conversation goes to whichever model you configure. With the default (local Ollama) it stays on your machine; with a hosted model it goes to that provider — say so in your privacy policy.

## Status

Extracted from a deployment that has been running on a real personal site since October 2026.

| Part | State |
|---|---|
| Widget (chat, avatar, mobile, text fallback, reconnect after silence) | in production, tested in Chrome/Safari engines |
| PHP backend + admin | smoke-tested end to end (`server/tests/smoke.sh`) |
| Booking, double-booking refusal, mails, ICS | tested, also in production |
| CalDAV (Nextcloud) | tested against a mock CalDAV server (discovery, busy times, write, delete) — please try it on yours and open an issue |
| Engine logic (system-owned actions, limits) | unit-tested (`npm test`) |
| Reference engine with a local Ollama brain | works; small models only (see roadmap) |
| Voice pipeline (STT/TTS/clone) | **not included** — protocol documented in `docs/protocol.md` |

Known limits: one conversation at a time (a speech engine works on one utterance at a time), the text fallback is keyword-based and
only as good as the answers you write, and the animated avatar is a 2D warp of one photo (set the face landmarks for your photo).

## Roadmap

The goal is a **fully local brain at voice speed**. Small local models currently struggle to be both fast and faithful on a
conversation with a long knowledge prompt; measured numbers and the plan are in [`docs/roadmap.md`](docs/roadmap.md).
Re-run them on your hardware with `scripts/bench-brain.sh <model> …`.

## Tests

```bash
npm test                      # engine: action safety, order-independent parsing, limits, prompt
server/tests/smoke.sh         # backend: login, tabs, feed, booking, double-booking, mail, ICS, offline answers, voice upload
```

## Story

This started as a local AI receptionist that answers the phone for a small business. The same technique, pointed at a personal
website, became this assistant. The write-up — how it began, what broke (browser autoplay rules, SIP-reserved ports, an unlicensed
upstream), and where it is going — is on the author's blog: <https://daniele-michelin.com/en/blog/ai-assistant-for-your-own-website/>.
A live deployment is at <https://daniele-michelin.com>.

## Licence

MIT — see [`LICENSE`](LICENSE). Not affiliated with any of the projects it can talk to (Ollama, Nextcloud, Anthropic, Twilio …);
names are used to describe compatibility only.
