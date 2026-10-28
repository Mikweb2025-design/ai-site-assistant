// Ask the running engine one question over the guest WebSocket and print the answer and the time it took.
//   node scripts/ask.mjs "Where does Jane work?" [lang] [ws-url] [origin]
import WebSocket from 'ws';
const [question = 'Who are you?', lang = 'en', url = 'ws://127.0.0.1:5070/guest/ws', origin = process.env.ASSISTANT_ORIGINS?.split(',')[0] || 'http://localhost:8080'] = process.argv.slice(2);
const ws = new WebSocket(`${url}?lang=${lang}`, { headers: { Origin: origin } });
const answers = [];
ws.on('message', (d) => { const m = JSON.parse(d); if (m.event === 'text' && m.from === 'assistant') answers.push(m.text); });
ws.on('unexpected-response', (_q, r) => { console.error(`refused: HTTP ${r.statusCode}`); process.exit(1); });
await new Promise((r) => ws.on('open', r));
ws.send(JSON.stringify({ event: 'start' }));
await new Promise((r) => setTimeout(r, 300));
const t0 = Date.now();
ws.send(JSON.stringify({ event: 'text', text: question }));
while (answers.length < 2 && Date.now() - t0 < 90000) await new Promise((r) => setTimeout(r, 100));
console.log(answers[1] ?? '(no answer within 90 s)');
console.log(`(${((Date.now() - t0) / 1000).toFixed(1)} s)`);
ws.close(); process.exit(0);
