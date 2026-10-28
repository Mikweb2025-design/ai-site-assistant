// Settings written in the site admin are pulled from the site (token-protected) every minute and applied without a restart.
import { CONFIG } from './config.mjs';
import { installVoices } from './voices.mjs';

let current = {};
let lastRead = 0;
const listeners = [];

export const remoteConfig = () => current;
export const remoteConfigLinked = () => Boolean(CONFIG.configUrl && CONFIG.token);
export const lastRemoteRead = () => lastRead;
export const onRemoteConfigChange = (fn) => listeners.push(fn);

async function pull() {
  try {
    const r = await fetch(CONFIG.configUrl, { headers: { 'X-Assistant-Token': CONFIG.token }, signal: AbortSignal.timeout(6000) });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    const j = await r.json();
    if (j && typeof j === 'object' && !Array.isArray(j)) {
      const before = JSON.stringify([current.voice, current.voiceTemp, current.greeting]);
      current = j; lastRead = Date.now();
      installVoices(j.voiceImports).catch(() => {});
      if (before !== JSON.stringify([j.voice, j.voiceTemp, j.greeting])) for (const f of listeners) { try { f(); } catch { /* listener errors never stop the pull */ } }
    }
  } catch (e) {
    if (Date.now() - lastRead > 3600000) console.error('settings not read from the site:', e.message);
  }
}

if (remoteConfigLinked()) { pull(); setInterval(pull, 60000).unref(); }
