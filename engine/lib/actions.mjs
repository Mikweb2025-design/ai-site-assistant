// Actions that reach the site owner (a message, an appointment). The site does the real work (mail, calendar);
// the engine only calls its endpoint with the token and then says the real outcome.
import { CONFIG } from './config.mjs';
import { remoteConfig } from './remote-config.mjs';

const ACTIONS_URL = (process.env.ASSISTANT_BOOKING_URL || '').trim() || CONFIG.configUrl.replace(/assistant-config\.php.*$/, 'assistant-booking.php');

export const actionsAvailable = () => Boolean(ACTIONS_URL && CONFIG.token);
export const messagesEnabled = () => actionsAvailable() && remoteConfig().messagesEnabled === true;
export const bookingEnabled = () => actionsAvailable() && remoteConfig().bookingFeed?.enabled === true && (remoteConfig().bookingFeed?.slots || []).length > 0;

async function call(type, data) {
  try {
    const r = await fetch(ACTIONS_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Assistant-Token': CONFIG.token },
      body: JSON.stringify({ type, ...data }),
      signal: AbortSignal.timeout(20000),
    });
    const j = await r.json().catch(() => ({}));
    return { ok: r.ok && j.ok === true, error: j.error || (r.ok ? '' : `http_${r.status}`) };
  } catch { return { ok: false, error: 'network' }; }
}

export const sendMessage = ({ name, email, message, lang, ip }) => call('message', { name, email, message, lang, ip });
export const bookAppointment = ({ name, email, date, time, note, lang, ip }) => call('booking', { name, email, date, time, note, lang, ip });

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
/** free slots grouped by day, for the prompt */
export function freeSlots(max = 36) {
  const per = new Map();
  for (const s of (remoteConfig().bookingFeed?.slots || []).slice(0, max)) {
    if (!per.has(s.date)) per.set(s.date, []);
    per.get(s.date).push(s.time);
  }
  return [...per].map(([d, times]) => {
    const dt = new Date(`${d}T12:00:00`);
    return `- ${DAYS[dt.getDay()]} ${dt.getDate()} ${MONTHS[dt.getMonth()]} (${d}): ${times.join(', ')}`;
  }).join('\n');
}
export const appointmentMinutes = () => Number(remoteConfig().bookingFeed?.durationMin) || 20;
export const appointmentPlace = () => String(remoteConfig().bookingFeed?.place || '');
