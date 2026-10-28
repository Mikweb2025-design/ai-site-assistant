// New voices sent from the site admin (Voice → "New voice"): download the sample, convert it to mono 24 kHz wav with ffmpeg,
// store it next to its reference text and report back to the site. Then choose it in the admin.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { CONFIG } from './config.mjs';

const VOICE_URL = (process.env.ASSISTANT_VOICE_URL || '').trim() || CONFIG.configUrl.replace(/assistant-config\.php.*$/, 'assistant-voice.php');
const seen = new Set();        // ids already handled in this run (no loops if the site never receives the outcome)

const run = (cmd, args) => new Promise((ok, ko) => {
  const p = spawn(cmd, args, { stdio: ['ignore', 'ignore', 'pipe'] });
  let err = '';
  p.stderr.on('data', (d) => { err += d; });
  p.on('error', (e) => ko(new Error(`${cmd} not available (${e.code || e.message})`)));
  p.on('close', (c) => (c === 0 ? ok() : ko(new Error(`${cmd} failed: ${err.trim().split('\n').pop() || c}`))));
});

async function report(id, status, note = '') {
  try { await fetch(VOICE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Assistant-Token': CONFIG.token }, body: JSON.stringify({ id, status, note }), signal: AbortSignal.timeout(10000) }); } catch { /* retried only after a restart */ }
}

async function install(v) {
  const name = String(v.name || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
  const text = String(v.text || '').trim();
  if (!name) throw new Error('invalid name');
  if (text.length < 20) throw new Error('missing reference text');
  const target = join(CONFIG.voicesDir, `${name}.wav`);
  if (existsSync(target)) throw new Error('a voice with this name already exists');
  const r = await fetch(`${VOICE_URL}?id=${encodeURIComponent(v.id)}`, { headers: { 'X-Assistant-Token': CONFIG.token }, signal: AbortSignal.timeout(60000) });
  if (!r.ok) throw new Error(`download failed (HTTP ${r.status})`);
  const data = Buffer.from(await r.arrayBuffer());
  if (data.length < 20000) throw new Error('file too small');
  const tmp = mkdtempSync(join(tmpdir(), 'voice-'));
  try {
    writeFileSync(join(tmp, 'in'), data);
    const out = join(tmp, 'out.wav');
    // mono, 24 kHz, rumble removed, loudness around -18 LUFS with a -1 dB peak ceiling
    await run('ffmpeg', ['-nostdin', '-y', '-i', join(tmp, 'in'), '-vn', '-ac', '1', '-ar', '24000', '-t', '60', '-af', 'highpass=f=70,loudnorm=I=-18:TP=-1.0,alimiter=limit=0.891:level=disabled', '-c:a', 'pcm_s16le', out]);
    const seconds = (statSync(out).size - 44) / 48000;
    if (seconds < 3) throw new Error(`recording too short (${seconds.toFixed(1)} s)`);
    mkdirSync(CONFIG.voicesDir, { recursive: true });
    writeFileSync(target, readFileSync(out));
    writeFileSync(join(CONFIG.voicesDir, `${name}.json`), JSON.stringify({ text, tone: 1.0 }, null, 2), { mode: 0o600 });
    return Math.round(seconds * 10) / 10;
  } finally { rmSync(tmp, { recursive: true, force: true }); }
}

export async function installVoices(list) {
  if (!VOICE_URL || !CONFIG.token || !Array.isArray(list)) return;
  for (const v of list) {
    if (!v?.id || seen.has(v.id)) continue;
    seen.add(v.id);
    try { const s = await install(v); console.log(`voice "${v.name}" installed (${s} s)`); await report(v.id, 'installed'); }
    catch (e) { console.error(`voice "${v.name}" not installed: ${e.message}`); await report(v.id, 'error', e.message); }
  }
}

/** voice samples available to choose from (a .wav with its .json) */
export function listVoices() {
  try {
    return readdirSync(CONFIG.voicesDir).filter((f) => f.endsWith('.wav') && existsSync(join(CONFIG.voicesDir, f.replace(/\.wav$/, '.json')))).sort();
  } catch { return []; }
}
