// Text-only reference engine: implements the guest protocol (docs/protocol.md) without any audio.
// It is also a complete, runnable "local brain" assistant: chat through the widget, answers from your site's text,
// booking and messages through the PHP backend. Add your own STT/TTS to turn it into a voice engine.
//
//   ASSISTANT_OWNER="Jane Doe" ASSISTANT_ORIGINS=http://localhost:8080 node mock-engine/server.mjs
import http from 'node:http';
import { WebSocketServer } from 'ws';
import { CONFIG } from '../engine/lib/config.mjs';
import { remoteConfigLinked } from '../engine/lib/remote-config.mjs';
import { guestsEnabled, originAllowed, visitorIp, refusal, reserve, limits, visitorLanguage, status, corsHeaders } from '../engine/lib/guests.mjs';
import { buildPrompt, knowledge, greeting } from '../engine/lib/persona.mjs';
import { think, brainName } from '../engine/lib/brain.mjs';
import { newSession, finishTurn } from '../engine/lib/dialog.mjs';
import { listVoices } from '../engine/lib/voices.mjs';

const PORT = Number(process.env.PORT || 5070);
const HOST = process.env.HOST || '127.0.0.1';

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname === '/guest/status' && guestsEnabled()) {
    const cors = corsHeaders(req.headers.origin);
    if (req.method === 'OPTIONS') { res.writeHead(204, cors); return res.end(); }
    // same-origin calls through the proxy carry no Origin header; the status itself is not a secret
    if (req.method === 'GET' && (cors['Access-Control-Allow-Origin'] || !req.headers.origin)) {
      res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', ...cors });
      return res.end(JSON.stringify({ ...status(true, listVoices()), voice: false, text: true }));   // text-only engine: no audio
    }
    res.writeHead(403); return res.end();
  }
  res.writeHead(404); res.end();
});

const wss = new WebSocketServer({ noServer: true });
server.on('upgrade', (req, socket, head) => {
  const url = new URL(req.url, 'http://x');
  const refuse = (line) => { socket.write(`HTTP/1.1 ${line}\r\n\r\n`); socket.destroy(); };
  if (url.pathname !== '/guest/ws' || !guestsEnabled() || !originAllowed(req.headers.origin)) return refuse('403 Forbidden');
  const ip = visitorIp(req);
  const why = refusal(ip);
  if (why) { console.log(`guest refused (${why})`); return refuse(why === 'busy' ? '503 Service Unavailable' : '429 Too Many Requests'); }
  const release = reserve(ip);
  wss.handleUpgrade(req, socket, head, (ws) => run(ws, ip, url, release));
});

function run(ws, ip, url, release) {
  const lang = visitorLanguage(url.searchParams.get('lang'));
  const resumed = url.searchParams.get('resume') === '1';
  const session = newSession(ip, lang);
  const history = [];
  const send = (o) => ws.readyState === 1 && ws.send(JSON.stringify(o));
  const lim = limits();
  let last = Date.now();
  let working = false;
  const maxTimer = setTimeout(() => ws.close(), lim.durationMs);
  const idleTimer = setInterval(() => { if (!working && Date.now() - last > limits().idleMs) { console.log('hung up after silence'); ws.close(); } }, 2000);
  ws.on('close', () => { clearTimeout(maxTimer); clearInterval(idleTimer); release(); });

  ws.on('message', async (raw) => {
    let m; try { m = JSON.parse(raw); } catch { return; }
    if (m.event === 'start' && !resumed) send({ event: 'text', from: 'assistant', text: greeting(lang) });
    if (m.event !== 'text') return;
    const text = String(m.text || '').trim().slice(0, 2500);
    if (!text || working) return;
    working = true; last = Date.now();
    send({ event: 'text', from: 'visitor', text });
    try {
      const facts = await knowledge();
      history.push({ role: 'user', content: text });
      const reply = await think(buildPrompt({ lang, facts }), history.slice(-12));
      const { text: out, end } = await finishTurn(session, text, reply);
      history.push({ role: 'assistant', content: out });
      send({ event: 'text', from: 'assistant', text: out });
      if (end) setTimeout(() => ws.close(), 800);
    } catch (e) {
      console.error('turn failed:', e.message);
      send({ event: 'text', from: 'assistant', text: 'Sorry, I cannot answer right now. Please use the contact form on the website.' });
    } finally { working = false; last = Date.now(); }
  });
}

server.listen(PORT, HOST, () => {
  console.log(`mock engine on http://${HOST}:${PORT}  (owner: ${CONFIG.owner})`);
  console.log(`brain: ${brainName()}  ·  guest origins: ${CONFIG.origins.join(', ') || 'NONE — set ASSISTANT_ORIGINS'}`);
  console.log(`settings feed: ${remoteConfigLinked() ? CONFIG.configUrl : 'not linked (defaults)'}`);
});
