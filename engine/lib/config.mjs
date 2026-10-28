// Environment configuration of the engine adapter. Everything has a safe default; nothing is required to try the mock engine.
import { join } from 'node:path';

const list = (v) => String(v || '').split(',').map((s) => s.trim()).filter(Boolean);
const num = (v, d) => (Number.isFinite(Number(v)) && v !== '' && v != null ? Number(v) : d);

export const CONFIG = {
  owner: process.env.ASSISTANT_OWNER || 'the site owner',
  // origins allowed to open a call (the site that embeds the widget). Empty = guest access is OFF.
  origins: list(process.env.ASSISTANT_ORIGINS).map((o) => o.replace(/\/+$/, '').toLowerCase()),
  languages: list(process.env.ASSISTANT_LANGUAGES || 'en,de,it'),
  dataDir: process.env.ASSISTANT_DATA_DIR || join(process.cwd(), 'data'),
  voicesDir: process.env.ASSISTANT_VOICES_DIR || join(process.cwd(), 'voices'),
  // settings feed served by the site's server/assistant-config.php (pulled every minute)
  configUrl: (process.env.ASSISTANT_CONFIG_URL || '').trim(),
  token: (process.env.ASSISTANT_TOKEN || '').trim(),
  // public pages the assistant learns from (plain text or markdown, e.g. llms-full.txt)
  knowledge: list(process.env.ASSISTANT_KNOWLEDGE),
  // fallback limits (the site admin can override them)
  limits: {
    perHour: num(process.env.ASSISTANT_PER_HOUR, 0),      // calls per visitor per hour, 0 = unlimited
    perDay: num(process.env.ASSISTANT_PER_DAY, 200),      // calls per day in total, 0 = unlimited
    durationS: num(process.env.ASSISTANT_DURATION_S, 300),
    pauseS: num(process.env.ASSISTANT_PAUSE_S, 0),
    idleS: num(process.env.ASSISTANT_IDLE_S, 30),         // hang up after this much silence; the widget reconnects by itself
  },
};
