# Engine protocol

The widget talks to *an engine* with two small endpoints. Anything that implements them can be plugged in: the text-only
reference engine in `mock-engine/`, your own voice pipeline, or a hosted service behind a thin adapter.
The audio part deliberately reuses the **Twilio Media Streams** message shapes, so existing telephone-style pipelines drop in.

## HTTP

`GET /guest/status` (reached from the site as `/assistant/status`)

```json
{ "ok": true, "voice": true, "text": true, "busy": false,
  "languages": ["en", "de", "it"], "maxDurationS": 300, "callsToday": 3, "dailyLimit": 200,
  "voices": ["jane.wav"] }
```

* `voice` — the engine can speak and listen (the widget shows the **Call** button).
* `text` — the engine can chat in text (a text-only engine sets `voice:false, text:true`).
* If the request fails, or both are false, the widget uses the text fallback `assistant-faq.php`.
* The status is not a secret. Same-origin calls through the proxy have no `Origin` header; cross-origin calls must come from an allowed origin.

## WebSocket

`/guest/ws?lang=de[&resume=1]` (from the site: `/assistant/ws`). The upgrade is refused with
`403` (origin not allowed), `429` (limit reached) or `503` (the line is busy).
`resume=1` means "the widget reconnected after a silence": do not greet again.

### widget → engine

```jsonc
{ "event": "connected" }
{ "event": "start", "streamSid": "MZ…", "start": { "streamSid": "MZ…", "callSid": "CA…",
    "customParameters": { "from": "browser" },
    "mediaFormat": { "encoding": "audio/x-mulaw", "sampleRate": 8000, "channels": 1 } } }
{ "event": "media", "streamSid": "MZ…", "media": { "track": "inbound", "chunk": "12", "timestamp": "240", "payload": "<base64, 160 bytes µ-law = 20 ms>" } }
{ "event": "mark",  "streamSid": "MZ…", "mark": { "name": "m7" } }     // "I have played everything up to m7"
{ "event": "text",  "text": "typed message, ≤ 2500 characters" }
{ "event": "stop",  "streamSid": "MZ…" }
```

### engine → widget

```jsonc
{ "event": "media", "streamSid": "MZ…", "media": { "payload": "<base64 µ-law 8 kHz>" } }  // the assistant's voice
{ "event": "mark",  "streamSid": "MZ…", "mark": { "name": "m7" } }   // ask the widget to confirm when it has played up to here
{ "event": "clear", "streamSid": "MZ…" }                              // barge-in: drop everything not yet played
{ "event": "text",  "from": "assistant" | "visitor", "text": "…" }    // transcript / typed text (the widget skips echoes of its own typing)
```

## Rules the engine must follow

1. **One line at a time.** Speech engines work on one utterance at a time; refuse a second call with `503` instead of queueing.
2. **Limits.** Per-visitor calls per hour, calls per day, maximum duration, pause between calls. All configurable from the admin (`limits`).
3. **Hang up after silence** (default 30 s of no speech and no typing, not counting the assistant speaking). The widget reconnects by itself on the next question with `resume=1` and the earlier turns as context, so the chat keeps working.
4. **Actions are owned by the system, not the model.** A marker (`<<BOOK|…>>`, `<<MESSAGE|…>>`) only makes the action *pending*; the engine speaks the recap if the model skipped it; only an explicit yes in the next visitor message executes it; the real outcome replaces whatever the model said. See `engine/lib/dialog.mjs` and `test/run.mjs`.
5. **Do not store conversations.** Log at most time and length.

## Settings feed (engine ← site)

`GET <site>/assistant-config.php` with header `X-Assistant-Token`. JSON with the admin's settings (`greeting`, `langs`, `style`,
`length`, `humor`, `contact`, `rules`, `knowledge`, `limits`, `voice`, `voiceTemp`), plus `bookingFeed` (`enabled`, `durationMin`,
`place`, `slots[]`), `messagesEnabled` and `voiceImports[]`. Pull it every minute; there is no inbound channel to the engine.

## Actions (engine → site)

`POST <site>/assistant-booking.php` with the token. Bodies: `{type:"booking", name, email, date, time, note, lang, ip}` and
`{type:"message", name, email, message, lang, ip}`. Responses: `{ok:true}`, or `409 {error:"slot_taken"}`, `403 {error:"disabled"}`, `422`, `429`.
