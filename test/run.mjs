// Engine logic tests (no network beyond a local mock of the site's booking endpoint). Run: npm test
import http from 'node:http';
import assert from 'node:assert/strict';

const calls = [];
const real = () => calls.filter((c) => c.type);   // the settings pull also hits the mock: ignore it
const mock = http.createServer((req, res) => {
  let b = ''; req.on('data', (c) => { b += c; });
  req.on('end', () => {
    const d = JSON.parse(b || '{}'); calls.push(d);
    const taken = d.type === 'booking' && d.time === '09:30';
    res.writeHead(taken ? 409 : 200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(taken ? { ok: false, error: 'slot_taken' } : { ok: true }));
  });
}).listen(0);
await new Promise((r) => mock.on('listening', r));
process.env.ASSISTANT_CONFIG_URL = `http://127.0.0.1:${mock.address().port}/assistant-config.php`;
process.env.ASSISTANT_TOKEN = 'test-token';
process.env.ASSISTANT_OWNER = 'Jane Doe';
process.env.ASSISTANT_ORIGINS = 'https://example.org';

const { newSession, finishTurn } = await import('../engine/lib/dialog.mjs');
const { refusal, reserve, originAllowed, visitorLanguage } = await import('../engine/lib/guests.mjs');
const { buildPrompt, greeting } = await import('../engine/lib/persona.mjs');

let n = 0;
const ok = (name) => console.log(`  ✓ ${name}`) || n++;

// 1. A marker without consent never books: the system says the recap and keeps the action pending.
{
  const s = newSession('1.2.3.4', 'en');
  const r1 = await finishTurn(s, 'My name is Anna, anna@example.com, about a cloud role', 'Great. <<BOOK|Anna|anna@example.com|2026-11-03|09:00|cloud role>>');
  assert.match(r1.text, /To sum up: Anna/); assert.ok(s.pending); assert.equal(real().length, 0);
  ok('marker without consent only produces a recap');
  // 2. The yes books — even if the model forgot the marker and just says "confirmed" — and the REAL outcome replaces its words.
  const r2 = await finishTurn(s, 'Yes, that is correct', 'Appointment confirmed!');
  assert.match(r2.text, /Done, the appointment is confirmed/); assert.equal(real().length, 1); assert.equal(real()[0].type, 'booking'); assert.equal(real()[0].date, '2026-11-03');
  ok('the visitor\'s yes books it and the real outcome replaces the model\'s claim');
}
// 3. Fields are recognised by content, not position.
{
  const s = newSession('1.2.3.4', 'de');
  await finishTurn(s, 'ok', '<<BOOK|2026-11-04|10:00|anna@example.com|Anna Berg|Thema X>>');
  assert.equal(s.pending.name, 'Anna Berg'); assert.equal(s.pending.time, '10:00'); assert.equal(s.pending.note, 'Thema X');
  ok('action fields are order independent');
}
// 4. A slot that was taken in the meantime is reported honestly.
{
  const s = newSession('1.2.3.4', 'en');
  await finishTurn(s, 'x', '<<BOOK|Bob|bob@example.com|2026-11-03|09:30|hello>>');
  const r = await finishTurn(s, 'yes please', 'ok');
  assert.match(r.text, /just been taken/);
  ok('a taken slot is reported, not hidden');
}
// 5. If the visitor moves on, the pending action lapses.
{
  const s = newSession('1.2.3.4', 'en');
  await finishTurn(s, 'x', '<<MESSAGE|Cy|cy@example.com|Call me>>');
  assert.ok(s.pending);
  await finishTurn(s, 'actually, what is your favourite language?', 'Python, I hear.');
  assert.equal(s.pending, null);
  ok('a pending action lapses when the visitor changes topic');
}
// 6. Limits, origins, languages.
{
  assert.equal(originAllowed('https://example.org'), true); assert.equal(originAllowed('https://evil.test'), false);
  const rel = reserve('9.9.9.9'); assert.equal(refusal('8.8.8.8'), 'busy'); rel(); assert.equal(refusal('8.8.8.8'), null);
  assert.equal(visitorLanguage('de-DE'), 'de'); assert.equal(visitorLanguage('fr'), 'en');
  ok('origin check, single line, language selection');
}
// 7. The prompt carries the owner and the rules that matter.
{
  const p = buildPrompt({ lang: 'it', facts: 'FACT-123' });
  assert.match(p, /Jane Doe/); assert.match(p, /FACT-123/); assert.match(p, /Never invent/); assert.match(p, /conversation starts in Italian/);
  assert.match(greeting('de'), /Jane Doe/);
  ok('prompt and greetings');
}
mock.close();
console.log(`\n${n} checks passed`);
