// One conversation turn, after the model has answered. The SYSTEM, not the model, owns actions:
// a marker from the model only puts the action "pending"; the recap is spoken by the system if the model skipped it;
// only an explicit yes in the NEXT visitor message executes it; the real outcome (done / slot taken / error)
// replaces whatever the model said. This fixes a real failure seen in testing: the model claimed "confirmed" without booking.
import { sendMessage, bookAppointment } from './actions.mjs';

const OUTCOMES = {
  en: { booked: 'Done, the appointment is confirmed and a confirmation is on its way by e-mail.', taken: 'That slot has just been taken. Shall I suggest another one?', sent: 'Done, I have passed the message on.', failed: 'I could not do that just now. Please use the contact form on the website.' },
  de: { booked: 'Erledigt, der Termin ist bestätigt und die Bestätigung ist per E-Mail unterwegs.', taken: 'Dieser Termin ist gerade vergeben worden. Soll ich dir einen anderen vorschlagen?', sent: 'Erledigt, ich habe die Nachricht weitergeleitet.', failed: 'Das hat gerade nicht geklappt. Bitte nutze das Kontaktformular der Website.' },
  it: { booked: "Fatto, l'appuntamento è confermato e ti ho mandato la conferma per email.", taken: "Quell'orario è appena stato preso. Vuoi che ti proponga un altro orario?", sent: 'Fatto, ho inoltrato il messaggio.', failed: 'Non sono riuscito a farlo adesso. Usa il modulo di contatto del sito.' },
};
const DAYS = { en: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], de: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'], it: ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'] };
const MONTHS = { en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'], de: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'], it: ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'] };

export const YES = /\b(yes|yeah|yep|sure|correct|right|confirm\w*|go ahead|please do|ok|okay|ja|jawohl|genau|stimmt|passt|bitte|gerne|s[ìi]|certo|va bene|esatto|giusto|perfetto|conferm\w*)\b/i;
const EMAIL = /^[^\s@<>|]+@[^\s@<>|]+\.[^\s@<>|]+$/;
const clean = (v, max) => { const t = String(v || '').trim(); return !t || t.length > max || /[\u0000-\u001f\u007f]|<<|>>/.test(t) ? '' : t; };

/** fields are recognised by their content, not their position: models sometimes reorder them */
function parseAction(kind, args) {
  const fields = args.map((x) => clean(x, 2000)).filter(Boolean);
  const email = fields.find((f) => EMAIL.test(f)) || '';
  const rest = fields.filter((f) => f !== email);
  const isDate = (c) => /^\d{4}-\d{2}-\d{2}$/.test(c), isTime = (c) => /^\d{1,2}:\d{2}$/.test(c);
  const name = (rest.find((c) => !isDate(c) && !isTime(c)) || '').slice(0, 120);
  if (!name || !email) return null;
  if (kind === 'BOOK') {
    const date = rest.find(isDate), time = rest.find(isTime);
    if (!date || !time) return null;
    return { kind: 'BOOK', name, email, date, time: time.padStart(5, '0'), note: rest.filter((c) => c !== name && c !== date && c !== time).join(' ').slice(0, 500) };
  }
  const message = rest.filter((c) => c !== name).join(' ').slice(0, 2000);
  return message ? { kind: 'MESSAGE', name, email, message } : null;
}

function recap(a, lang) {
  const l = ['en', 'de', 'it'].includes(lang) ? lang : 'en';
  if (a.kind === 'BOOK') {
    const d = new Date(`${a.date}T12:00:00`);
    const day = l === 'de' ? `${DAYS.de[d.getDay()]}, ${d.getDate()}. ${MONTHS.de[d.getMonth()]}` : `${DAYS[l][d.getDay()]} ${d.getDate()} ${MONTHS[l][d.getMonth()]}`;
    const note = a.note ? `, ${a.note}` : '';
    return { en: `To sum up: ${a.name}, ${day} at ${a.time}${note}. Does that work?`, de: `Zusammengefasst: ${a.name}, ${day} um ${a.time} Uhr${note}. Passt das so?`, it: `Riepilogo: ${a.name}, ${day} alle ${a.time}${note}. Va bene così?` }[l];
  }
  return { en: `Your message: ${a.message} Shall I send it like this?`, de: `Deine Nachricht: ${a.message} Soll ich sie so senden?`, it: `Il messaggio: ${a.message} Lo invio così?` }[l];
}

export function newSession(ip, lang) { return { ip, lang, pending: null, booked: false, messaged: false }; }

/** @returns {{ text: string, end: boolean }} what to say and whether the visitor said goodbye */
export async function finishTurn(session, visitorText, modelText) {
  const L = OUTCOMES[session.lang] || OUTCOMES.en;
  const markers = [...String(modelText).matchAll(/<<\s*(BOOK|MESSAGE|END)\s*(?:\|([^>]*))?>>/g)].map(([, kind, a]) => ({ kind, args: (a || '').split('|') }));
  let text = String(modelText).replace(/<<[^>]*(?:>>|$)/g, '').replace(/\s+\n/g, '\n').trim();
  const end = markers.some((m) => m.kind === 'END') && /\b(bye|goodbye|that'?s all|tschüss|auf wiedersehen|ciao|arrivederci|a presto)\b/i.test(visitorText);
  const fresh = markers.filter((m) => m.kind !== 'END').map((m) => parseAction(m.kind, m.args)).filter(Boolean).at(-1) || null;
  const chosen = fresh || session.pending;

  if (session.pending && chosen && YES.test(visitorText)) {         // the yes to a recap that was already spoken
    session.pending = null;
    if (chosen.kind === 'BOOK' && !session.booked) {
      const r = await bookAppointment({ ...chosen, lang: session.lang, ip: session.ip });
      if (r.ok) session.booked = true;
      text = r.ok ? L.booked : r.error === 'slot_taken' ? L.taken : L.failed;
    } else if (chosen.kind === 'MESSAGE' && !session.messaged) {
      const r = await sendMessage({ ...chosen, lang: session.lang, ip: session.ip });
      if (r.ok) session.messaged = true;
      text = r.ok ? L.sent : L.failed;
    }
  } else if (fresh) {
    session.pending = fresh;
    if (!/[?]/.test(text)) text = `${text} ${recap(fresh, session.lang)}`.trim();
  } else {
    session.pending = null;                                         // the visitor moved on: the action lapses
  }
  return { text, end };
}
