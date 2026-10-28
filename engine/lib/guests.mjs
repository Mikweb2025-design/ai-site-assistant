// Public entrance for visitors of a site: allowed origins only, limits per visitor / per day, one call at a time
// (a voice engine works on one utterance at a time), hang-up after silence.
import { CONFIG } from './config.mjs';
import { remoteConfig } from './remote-config.mjs';

export const guestsEnabled = () => CONFIG.origins.length > 0;
export const originAllowed = (origin) => CONFIG.origins.includes(String(origin || '').trim().replace(/\/+$/, '').toLowerCase());

export function visitorIp(req) {
  const h = req.headers;
  return String(h['cf-connecting-ip'] || String(h['x-forwarded-for'] || '').split(',')[0] || req.socket.remoteAddress || '?').trim();
}

const num = (v, d, min, max) => (Number.isFinite(Number(v)) && v !== '' && v != null ? Math.min(max, Math.max(min, Number(v))) : d);
export function limits() {
  const l = remoteConfig().limits || {};
  const d = CONFIG.limits;
  return {
    perHour: num(l.perHour, d.perHour, 0, 100),
    perDay: num(l.perDay, d.perDay, 0, 2000),
    durationMs: num(l.durationMin, d.durationS / 60, 1, 15) * 60000,
    pauseMs: num(l.pauseSec, d.pauseS, 0, 120) * 1000,
    idleMs: num(l.idleSec, d.idleS, 10, 300) * 1000,
  };
}

let busy = false;
const perIp = new Map();            // ip -> start times in the last hour
let day = { date: '', n: 0 };
const today = () => new Date().toISOString().slice(0, 10);

function recent(ip) {
  const since = Date.now() - 3600000;
  const list = (perIp.get(ip) || []).filter((t) => t > since);
  perIp.set(ip, list);
  if (day.date !== today()) day = { date: today(), n: 0 };
  return list;
}

/** why this visitor cannot start a call right now, or null */
export function refusal(ip) {
  const list = recent(ip);
  const lim = limits();
  if (busy) return 'busy';
  if (lim.perDay > 0 && day.n >= lim.perDay) return 'daily-limit';
  if (lim.perHour > 0 && list.length >= lim.perHour) return 'too-many-calls';
  if (list.length && Date.now() - list[list.length - 1] < lim.pauseMs) return 'too-soon';
  return null;
}

/** reserve the line; returns the function that releases it */
export function reserve(ip) {
  const list = recent(ip);
  list.push(Date.now());
  day.n += 1;
  busy = true;
  let released = false;
  return () => { if (!released) { released = true; busy = false; } };
}

export function visitorLanguage(requested) {
  const l = String(requested || '').slice(0, 2).toLowerCase();
  const allowed = remoteConfig().langs;
  return CONFIG.languages.includes(l) && (!Array.isArray(allowed) || allowed.includes(l)) ? l : CONFIG.languages[0];
}

export function status(engineReady, voices = []) {
  recent('status');
  const lim = limits();
  const allowed = remoteConfig().langs;
  return {
    ok: true,
    voice: engineReady && (lim.perDay === 0 || day.n < lim.perDay),
    busy,
    languages: CONFIG.languages.filter((l) => !Array.isArray(allowed) || allowed.includes(l)),
    maxDurationS: Math.round(lim.durationMs / 1000),
    callsToday: day.n,
    dailyLimit: lim.perDay,
    voices,
  };
}

export function corsHeaders(origin) {
  return originAllowed(origin)
    ? { 'Access-Control-Allow-Origin': origin, Vary: 'Origin', 'Access-Control-Allow-Methods': 'GET, OPTIONS', 'Access-Control-Allow-Headers': 'Content-Type' }
    : {};
}
