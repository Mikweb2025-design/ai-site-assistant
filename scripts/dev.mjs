// One command demo:  npm run demo   [-- --ollama llama3.1:8b]
// Starts: the PHP backend (php -S), the reference engine, and a small dev web server on http://localhost:8080 that serves the
// demo page, the widget files, and proxies /assistant/* to the engine and the PHP endpoints — the same shape as nginx in production.
import http from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { WebSocketServer, WebSocket } from 'ws';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const dev = join(root, '.dev');
const args = process.argv.slice(2);
const model = args.includes('--ollama') ? args[args.indexOf('--ollama') + 1] : '';
const WEB = 8080, PHP = 8081, ENGINE = Number(process.env.ENGINE_PORT || 5090);

mkdirSync(join(dev, 'private'), { recursive: true });
writeFileSync(join(dev, 'private', 'assistant-token'), 'dev-token\n');
const hash = execFileSync('php', ['-r', 'echo password_hash("dev-password", PASSWORD_DEFAULT);']).toString();
writeFileSync(join(dev, 'config.php'), `<?php return [
  'site_url' => 'http://localhost:${WEB}', 'owner_name' => 'Jane Doe', 'assistant_name' => 'Jane',
  'notify_email' => 'owner@example.com', 'from_email' => 'assistant@example.com', 'from_name' => 'Demo assistant',
  'smtp' => null, 'timezone' => 'Europe/Berlin', 'private_dir' => '${join(dev, 'private')}',
  'site_config_file' => '${join(dev, 'site-config.json')}', 'status_url' => 'http://127.0.0.1:${WEB}/assistant/status',
  'allowed_origins' => ['http://localhost:${WEB}'], 'knowledge_hosts' => [], 'trust_proxy' => false, 'faq_links' => [],
  'admin' => ['username' => 'admin', 'password_hash' => '${hash.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'],
];\n`);
if (!existsSync(join(dev, 'site-config.json'))) {
  writeFileSync(join(dev, 'site-config.json'), JSON.stringify({ chat: true, chatHome: true, chatVoice: false, chatOwner: 'Jane Doe', chatName: 'Jane', chatBase: '/assistant', chatSide: 'right', chatV: 1 }, null, 2));
}

const env = { ...process.env, AIS_CONFIG: join(dev, 'config.php'), AIS_PRIVATE_DIR: join(dev, 'private'), AIS_SITE_CONFIG: join(dev, 'site-config.json'), AIS_ASSISTANT_STATUS_URL: `http://127.0.0.1:${WEB}/assistant/status` };
const children = [
  spawn('php', ['-d', 'upload_max_filesize=16M', '-d', 'post_max_size=16M', '-S', `127.0.0.1:${PHP}`, '-t', join(root, 'server')], { env, stdio: 'inherit' }),
  spawn('node', [join(root, 'mock-engine', 'server.mjs')], { stdio: 'inherit', env: {
    ...process.env, PORT: String(ENGINE), ASSISTANT_OWNER: 'Jane Doe', ASSISTANT_ORIGINS: `http://localhost:${WEB}`,
    ASSISTANT_CONFIG_URL: `http://127.0.0.1:${PHP}/assistant-config.php`, ASSISTANT_TOKEN: 'dev-token',
    ASSISTANT_KNOWLEDGE: `http://127.0.0.1:${WEB}/llms.txt`, ASSISTANT_DATA_DIR: join(dev, 'data'),
    ASSISTANT_BRAIN: model ? 'openai' : 'demo', ...(model ? { ASSISTANT_BRAIN_MODEL: model } : {}),
  } }),
];
const stop = () => { for (const c of children) c.kill(); process.exit(0); };
process.on('SIGINT', stop); process.on('SIGTERM', stop);

const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json', '.txt': 'text/plain; charset=utf-8', '.png': 'image/png', '.webp': 'image/webp' };
const staticRoots = [['/assets/ais/', join(root, 'widget')], ['/', join(root, 'examples', 'demo')]];

function proxy(req, res, port) {
  const up = http.request({ host: '127.0.0.1', port, path: req.url, method: req.method, headers: { ...req.headers, host: `127.0.0.1:${port}` } }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
  up.on('error', () => { res.writeHead(502); res.end('upstream down'); });
  req.pipe(up);
}

const server = http.createServer((req, res) => {
  const path = new URL(req.url, 'http://x').pathname;
  if (path === '/site-config.json') { res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); return res.end(readFileSync(join(dev, 'site-config.json'))); }
  if (path === '/assistant/status') { req.url = '/guest/status'; return proxy(req, res, ENGINE); }
  if (path.startsWith('/assistant-') || path.startsWith('/admin')) return proxy(req, res, PHP);
  for (const [prefix, dir] of staticRoots) {
    if (!path.startsWith(prefix)) continue;
    let rel = normalize(path.slice(prefix.length) || 'index.html'); if (rel.endsWith('/')) rel += 'index.html';
    const file = join(dir, rel);
    if (file.startsWith(dir) && existsSync(file)) { res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' }); return res.end(readFileSync(file)); }
  }
  res.writeHead(404); res.end('not found');
});

const wss = new WebSocketServer({ noServer: true });
server.on('upgrade', (req, sock, head) => {
  const u = new URL(req.url, 'http://x');
  if (u.pathname !== '/assistant/ws') return sock.destroy();
  const up = new WebSocket(`ws://127.0.0.1:${ENGINE}/guest/ws${u.search}`, { headers: { Origin: req.headers.origin || '' } });
  up.on('unexpected-response', (_q, r) => { sock.write(`HTTP/1.1 ${r.statusCode} X\r\n\r\n`); sock.destroy(); });
  up.on('open', () => wss.handleUpgrade(req, sock, head, (c) => {
    c.on('message', (d, bin) => up.send(d, { binary: bin })); up.on('message', (d, bin) => c.send(d, { binary: bin }));
    c.on('close', () => up.close()); up.on('close', () => c.close());
  }));
});
server.listen(WEB, '127.0.0.1', () => {
  console.log(`\n  demo site     http://localhost:${WEB}/`);
  console.log(`  admin         http://localhost:${WEB}/admin/   (admin / dev-password)`);
  console.log(`  brain         ${model ? `Ollama model ${model}` : 'demo (no model) — add: -- --ollama <model>'}\n`);
});
