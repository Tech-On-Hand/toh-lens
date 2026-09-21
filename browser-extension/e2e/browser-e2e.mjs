// Drives the real extension in a real (headless) Chrome with the real native host
// binary. A small stand-in plays the Student Agent. Everything is isolated:
// a throwaway browser profile, a temp bridge directory, and one per-user registry
// key that is removed in `finally`.
//
//   cargo build --release   (in ../browser-host)
//   node e2e/browser-e2e.mjs [chrome|edge]
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = join(import.meta.dirname, '..');
const targetName = process.argv[2] === 'edge' ? 'edge' : 'chrome';
const targets = {
  chrome: { exe: 'C:/Program Files/Google/Chrome/Application/chrome.exe', registry: 'HKCU\\Software\\Google\\Chrome\\NativeMessagingHosts', other: 'edge' },
  edge: { exe: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', registry: 'HKCU\\Software\\Microsoft\\Edge\\NativeMessagingHosts', other: 'chrome' },
};
const chromePath = targets[targetName].exe;
const hostExe = join(root, '..', 'browser-host', 'target', 'release', 'toh-klas-native-host.exe');
const manifest = JSON.parse(readFileSync(join(root, 'manifest.json'), 'utf8'));
const extensionId = 'ifialcnolohnhdlojcngcdgiglgffeih';
const registryKey = `${targets[targetName].registry}\\com.techonhand.klas`;

const work = mkdtempSync(join(tmpdir(), 'toh-klas-browser-e2e-'));
const profile = join(work, 'profile');
const bridgeDir = join(work, 'bridge');
const hostManifestPath = join(work, 'com.techonhand.klas.json');
let chrome;
const servers = [];

// DevTools pipe messages are NUL-terminated.
const NUL = String.fromCharCode(0);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const step = (text) => console.log(`- ${text}`);

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  await new Promise((resolve) => server.close(resolve));
  return port;
}

// --- a tiny web server so no internet is needed -----------------------------
const webPort = await freePort();
servers.push(await new Promise((resolve) => {
  const server = http.createServer((request, response) => {
    response.setHeader('content-type', 'text/html');
    response.end(`<title>Page ${request.url.slice(1).toUpperCase()}</title><h1>${request.url}</h1>`);
  });
  server.listen(webPort, '127.0.0.1', () => resolve(server));
}));
const page = (name) => `http://127.0.0.1:${webPort}/${name}`;

// --- the stand-in Student Agent ---------------------------------------------
const received = [];
let hostSocket = null;
let agentServer = null;
const agentSockets = new Set();

async function startAgent() {
  const token = `e2e-${Date.now()}`;
  agentServer = net.createServer((socket) => {
    agentSockets.add(socket);
    socket.on('close', () => agentSockets.delete(socket));
    socket.on('error', () => {});
    let buffer = '';
    let authenticated = false;
    socket.on('data', (chunk) => {
      buffer += chunk;
      let index;
      while ((index = buffer.indexOf('\n')) !== -1) {
        const message = JSON.parse(buffer.slice(0, index));
        buffer = buffer.slice(index + 1);
        if (!authenticated) {
          if (message.type !== 'bridge.auth' || message.token !== token) return socket.destroy();
          authenticated = true;
          hostSocket = socket;
        } else {
          received.push(message);
        }
      }
    });
  });
  await new Promise((resolve) => agentServer.listen(0, '127.0.0.1', resolve));
  writeFileSync(join(bridgeDir, 'bridge.json'), JSON.stringify({ port: agentServer.address().port, token }));
}

async function stopAgent() {
  hostSocket = null;
  agentSockets.forEach((socket) => socket.destroy());
  await new Promise((resolve) => agentServer.close(resolve));
}

async function waitFor(predicate, what, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const found = received.find(predicate);
    if (found) return found;
    await sleep(100);
  }
  throw new Error(`timed out waiting for ${what}. Received: ${JSON.stringify(received.map((m) => m.type))}`);
}

const seen = new Set();
const next = (predicate, what, timeoutMs) => waitFor((m) => !seen.has(m) && predicate(m), what, timeoutMs).then((m) => (seen.add(m), m));

async function command(type, payload) {
  const id = `cmd-${Math.random().toString(36).slice(2)}`;
  hostSocket.write(JSON.stringify({ version: 1, id, type, occurred_at: new Date().toISOString(), payload }) + '\n');
  const response = await next((m) => m.type === 'response' && m.id === id, `a response to ${type}`);
  return response.payload;
}

// --- Chrome over the DevTools protocol's HTTP endpoints ---------------------
let debugPort;
const devtools = async (path, method = 'GET') => (await fetch(`http://127.0.0.1:${debugPort}${path}`, { method })).json();
const pageTargets = async () => (await devtools('/json/list')).filter((t) => t.type === 'page');
const urls = async () => (await pageTargets()).map((t) => t.url);

try {
  for (const path of [profile, bridgeDir]) mkdirSyncSafe(path);
  writeFileSync(hostManifestPath, JSON.stringify({
    name: 'com.techonhand.klas', description: 'e2e', path: hostExe, type: 'stdio',
    allowed_origins: [`chrome-extension://${extensionId}/`],
  }));
  execFileSync('reg', ['add', registryKey, '/ve', '/t', 'REG_SZ', '/d', hostManifestPath, '/f'], { stdio: 'ignore' });

  await startAgent();
  debugPort = await freePort();
  // Branded Chrome ignores --load-extension, so load through the DevTools pipe.
  chrome = spawn(chromePath, [
    '--headless=new', `--user-data-dir=${profile}`, `--remote-debugging-port=${debugPort}`,
    '--remote-debugging-pipe', '--enable-unsafe-extension-debugging',
    '--no-first-run', '--no-default-browser-check', 'about:blank',
  ], { env: { ...process.env, TOH_KLAS_BRIDGE_DIR: bridgeDir }, stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });

  const loaded = new Promise((resolve, reject) => {
    let buffer = '';
    chrome.stdio[4].on('data', (chunk) => {
      buffer += chunk;
      for (const part of buffer.split(NUL).slice(0, -1)) {
        const message = JSON.parse(part);
        if (message.id === 1) message.result ? resolve(message.result.id) : reject(new Error(JSON.stringify(message)));
      }
      buffer = buffer.slice(buffer.lastIndexOf(NUL) + 1);
    });
  });
  await sleep(1500);
  chrome.stdio[3].write(JSON.stringify({ id: 1, method: 'Extensions.loadUnpacked', params: { path: root } }) + NUL);
  step('Chrome loads the unpacked extension and assigns the ID pinned in the native host manifest');
  assert.equal(await loaded, extensionId);

  step('the extension connects through the real native host and introduces itself');
  const hello = await next((m) => m.type === 'browser.hello', 'browser.hello', 30000);
  assert.equal(hello.payload.browser, targetName);
  assert.equal(hello.payload.extension_version, manifest.version);

  step('opening a page produces a snapshot that lists it');
  await devtools(`/json/new?${page('a')}`, 'PUT');
  const snapshot = await next((m) => m.type === 'browser.snapshot' && m.payload.tabs.some((t) => t.url === page('a')), 'a snapshot with page A');
  const tabA = snapshot.payload.tabs.find((t) => t.url === page('a'));
  assert.equal(tabA.title, 'Page A');
  assert.ok(Number.isInteger(tabA.tab_id));

  step('the navigation is logged once the title settles');
  const activity = await next((m) => m.type === 'browser.activity' && m.payload.events.some((e) => e.url === page('a')), 'a navigation event for page A');
  const event = activity.payload.events.find((e) => e.url === page('a'));
  assert.equal(event.type, 'navigated');
  assert.equal(event.title, 'Page A');
  assert.match(event.uuid, /^[0-9a-f-]{36}$/);

  step('browser.open_url opens a new tab');
  const opened = await command('browser.open_url', { url: page('b'), browser: targetName });
  assert.equal(opened.status, 'completed', JSON.stringify(opened));
  assert.ok(Number.isInteger(opened.result.tab_id));
  assert.ok((await urls()).includes(page('b')), 'page B should be open');

  step('script and browser-internal URLs are refused and nothing opens');
  const before = (await pageTargets()).length;
  const beforeUrls = await urls();
  for (const url of ['javascript:alert(1)', 'chrome://settings', 'file:///c:/windows/win.ini']) {
    const refused = await command('browser.open_url', { url });
    assert.deepEqual(refused, { status: 'failed', result: { error: 'INVALID_URL' } });
  }
  await sleep(800);
  // Browsers may open unrelated pages of their own, so check only that no refused target opened.
  const refused = /^(javascript:|file:)|\/\/settings|win\.ini/;
  const afterUrls = await urls();
  assert.ok(!afterUrls.some((url) => refused.test(url)), `a refused command opened something: ${afterUrls}`);
  assert.ok(beforeUrls.every((url) => afterUrls.includes(url)), 'refused commands must not close tabs either');

  step('browser.navigate redirects an existing tab');
  const navigated = await command('browser.navigate', { browser: targetName, tab_id: tabA.tab_id, url: page('c') });
  assert.equal(navigated.status, 'completed', JSON.stringify(navigated));
  await next((m) => m.type === 'browser.snapshot' && m.payload.tabs.some((t) => t.tab_id === tabA.tab_id && t.url === page('c')), 'the redirected tab in a snapshot');

  step('browser.close_tab closes a tab, and closing it again reports TAB_NOT_FOUND');
  const closed = await command('browser.close_tab', { browser: targetName, tab_id: opened.result.tab_id });
  assert.equal(closed.status, 'completed', JSON.stringify(closed));
  await sleep(300);
  assert.ok(!(await urls()).includes(page('b')), 'page B should be closed');
  const again = await command('browser.close_tab', { browser: targetName, tab_id: opened.result.tab_id });
  assert.deepEqual(again, { status: 'failed', result: { error: 'TAB_NOT_FOUND' } });

  step('a command meant for the other browser is refused');
  const wrong = await command('browser.open_url', { url: page('d'), browser: targets[targetName].other });
  assert.deepEqual(wrong, { status: 'failed', result: { error: 'WRONG_BROWSER' } });

  step('the agent can ask for a fresh snapshot');
  hostSocket.write(JSON.stringify({ version: 1, id: 'r1', type: 'browser.request_snapshot', occurred_at: new Date().toISOString(), payload: {} }) + '\n');
  await next((m) => m.type === 'browser.snapshot', 'a requested snapshot');

  // --- classroom policy, enforced by the real browser ------------------------
  const site = (name) => `http://${name}.localhost:${webPort}/`;
  const blockedPage = (reason, original) => `chrome-extension://${extensionId}/blocked.html?reason=${reason}&u=${original}`;
  const sendPolicy = (payload) => hostSocket.write(JSON.stringify({ version: 1, id: `p-${Math.random()}`, type: 'policy.update', occurred_at: new Date().toISOString(), payload }) + '\n');
  const tabWhere = async (predicate, what, timeoutMs = 15000) => {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const found = (await pageTargets()).find(predicate);
      if (found) return found;
      await sleep(150);
    }
    throw new Error(`timed out waiting for ${what}. Tabs: ${JSON.stringify((await pageTargets()).map((t) => t.url))}`);
  };
  const open = (url) => devtools(`/json/new?${url}`, 'PUT');

  step('a block rule sends navigation to a blocked site to the blocked page, while other sites load');
  sendPolicy({ version: 'v1', block: ['blocked.localhost'], focus: null });
  await sleep(500);
  await open(site('blocked'));
  const parkedTab = await tabWhere((t) => t.url === blockedPage('blocked', site('blocked')), 'the blocked site to be redirected');
  assert.equal(parkedTab.title, 'Page blocked');
  await open(site('allowed'));
  await tabWhere((t) => t.url === site('allowed'), 'an unblocked site to load normally');

  step('the teacher side sees the attempt as a blocked event and the original address in the snapshot');
  const blockedEvent = await next((m) => m.type === 'browser.activity' && m.payload.events.some((e) => e.type === 'blocked' && e.url === site('blocked')), 'a blocked event');
  assert.equal(blockedEvent.payload.events.find((e) => e.type === 'blocked').title, 'Blocked (blocked)');
  await next((m) => m.type === 'browser.snapshot' && m.payload.tabs.some((t) => t.url === site('blocked')), 'a snapshot reporting the original address');

  step('a focus session parks every tab that is not allowed, including ones already open');
  sendPolicy({ version: 'v2', block: ['blocked.localhost'], focus: { id: 'f1', name: 'e2e', allowed_domains: ['allowed.localhost'], ends_at: new Date(Date.now() + 9000).toISOString() } });
  await tabWhere((t) => t.url === blockedPage('focus', page('c')), 'an already-open tab to be parked by the focus session');
  await open(site('other'));
  await tabWhere((t) => t.url === blockedPage('focus', site('other')), 'a new navigation to a non-allowed site to be blocked');
  await tabWhere((t) => t.url === site('allowed'), 'the allowed site to stay open');
  await open(`${site('allowed')}second`);
  await tabWhere((t) => t.url === `${site('allowed')}second`, 'another page on the allowed site to load');

  step('the session ends by itself at its deadline and tabs return to where they were');
  await tabWhere((t) => t.url === page('c'), 'the parked tab to be restored after the deadline', 40000);
  await tabWhere((t) => t.url === site('other'), 'the other parked tab to be restored', 10000);
  assert.ok((await urls()).includes(blockedPage('blocked', site('blocked'))), 'the standing block rule must survive the end of focus');
  await open(site('blocked'));
  await sleep(1500);
  assert.ok((await pageTargets()).filter((t) => t.url === blockedPage('blocked', site('blocked'))).length >= 2, 'blocked sites stay blocked after focus');

  step('with no agent connection the browser keeps enforcing the last policy');
  await stopAgent();
  await sleep(500);
  await open(site('blocked'));
  await sleep(1500);
  assert.ok((await pageTargets()).filter((t) => t.url === blockedPage('blocked', site('blocked'))).length >= 3, 'enforcement continues offline');
  await startAgent();
  await next((m) => m.type === 'browser.hello', 'the extension to reconnect after the offline check', 45000);

  step('removing the rule releases the parked tabs');
  sendPolicy({ version: 'v3', block: [], focus: null });
  await tabWhere((t) => t.url === site('blocked'), 'a parked tab to be restored when the rule is removed');
  assert.ok(!(await urls()).some((url) => url.startsWith(`chrome-extension://${extensionId}/blocked.html`)), 'no tab stays parked');

  step('after the agent restarts (new port, new secret) the extension reconnects on its own');
  await stopAgent();
  await sleep(500);
  await startAgent();
  const reconnected = await next((m) => m.type === 'browser.hello', 'a second browser.hello after the agent restarted', 45000);
  assert.equal(reconnected.payload.browser, targetName);

  step('and commands work again afterwards');
  const afterRestart = await command('browser.open_url', { url: page('e') });
  assert.equal(afterRestart.status, 'completed', JSON.stringify(afterRestart));

  console.log(`\nbrowser e2e ok: real ${targetName} + real extension + real native host`);
} finally {
  try { if (chrome?.pid) execFileSync('taskkill', ['/PID', String(chrome.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  try { execFileSync('reg', ['delete', registryKey, '/f'], { stdio: 'ignore' }); } catch {}
  servers.forEach((server) => server.close());
  try { agentServer?.close(); } catch {}
  await sleep(500);
  try { rmSync(work, { recursive: true, force: true }); } catch {}
}

function mkdirSyncSafe(path) {
  execFileSync('cmd', ['/c', 'mkdir', path], { stdio: 'ignore' });
}
