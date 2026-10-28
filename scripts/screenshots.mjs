// Regenerates docs/screenshots/*.png from the running demo (npm run demo -- --ollama <model>).
//   node scripts/screenshots.mjs [--live https://your-site.example/en/]
import puppeteer from 'puppeteer-core';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const out = resolve(fileURLToPath(new URL('../docs/screenshots', import.meta.url)));
mkdirSync(out, { recursive: true });
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://localhost:8080';
const live = process.argv.includes('--live') ? process.argv[process.argv.indexOf('--live') + 1] : '';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
const shot = async (page, name, opts = {}) => { await page.screenshot({ path: join(out, name), ...opts }); console.log('  ✓', name); };

async function ask(page, text, waitMs = 14000) {
  await page.click('.dmc-in'); await page.type('.dmc-in', text); await page.keyboard.press('Enter'); await wait(waitMs);
}

// ---- the widget on the demo site
if (!process.argv.includes('--skip-widget')) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1.5 });
  await page.goto(BASE + '/', { waitUntil: 'networkidle2' });
  await wait(4500);                                  // the "Ask me anything" nudge appears after ~3.5 s
  await shot(page, 'widget-closed.png');
  await page.click('.dmc-btn'); await wait(800);
  await ask(page, 'Is Jane open to new roles?');
  await ask(page, 'Welche Sprachen spricht sie?', 14000);
  await shot(page, 'widget-chat.png');

  // text-only fallback: make the engine look unreachable
  const p2 = await browser.newPage();
  await p2.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1.5 });
  await p2.setRequestInterception(true);
  p2.on('request', (r) => (r.url().includes('/assistant/status') ? r.abort() : r.continue()));
  await p2.goto(BASE + '/', { waitUntil: 'networkidle2' }); await wait(1500);
  await p2.click('.dmc-btn'); await wait(800);
  await ask(p2, 'How can I contact Jane?', 2500);
  await shot(p2, 'widget-text-fallback.png');

  const m = await browser.newPage();
  await m.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await m.goto(BASE + '/', { waitUntil: 'networkidle2' }); await wait(1500);
  await m.click('.dmc-btn'); await wait(800);
  await ask(m, 'What does Jane specialise in?', 12000);
  await shot(m, 'widget-mobile.png');
}

// ---- the admin
{
  const page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 900, deviceScaleFactor: 1.5 });
  await page.goto(BASE + '/admin/', { waitUntil: 'networkidle2' });
  await shot(page, 'admin-login.png');
  await page.type('input[name=user]', 'admin'); await page.type('input[name=pass]', 'dev-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.keyboard.press('Enter')]);
  const tabs = { status: 'admin-status.png', widget: 'admin-widget.png', behavior: 'admin-behavior.png', voice: 'admin-voice.png', knowledge: 'admin-knowledge.png', booking: 'admin-booking.png', mail: 'admin-mail.png', limits: 'admin-limits.png' };
  for (const [t, file] of Object.entries(tabs)) {
    await page.goto(`${BASE}/admin/chatbot.php${t === 'status' ? '' : `?t=${t}`}`, { waitUntil: 'networkidle2' });
    if (t === 'booking') {                      // switch booking on so the free slots show up
      await page.evaluate(() => { const c = document.querySelector('input[name=enabled]'); if (c && !c.checked) c.click(); });
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate(() => document.querySelector('input[name=enabled]').closest('form').requestSubmit())]);
    }
    await shot(page, file, { fullPage: true });
  }
}

// ---- optional: a real deployment
if (live) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1.5 });
  await page.goto(live, { waitUntil: 'networkidle2' }); await wait(4500);
  await page.click('.dmc-btn'); await wait(800);
  await ask(page, 'Where does he work?', 9000);
  await shot(page, 'live-deployment.png');
}
await browser.close();
