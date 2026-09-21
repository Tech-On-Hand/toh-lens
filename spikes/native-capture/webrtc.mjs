// Orchestrates the WebRTC spike: launches the Rust sender (real WGC capture -> real
// Windows H.264 encoder -> real WebRTC/RTP) and a real headless Chrome as the
// receiver, with a tiny HTTP relay standing in for the signaling Reverb would carry
// in production. No STUN/TURN configured on either side: this specifically tests the
// direct-connection path the architecture treats as the common case.
//
//   cargo build --release --bin webrtc-send   (in this folder)
//   node webrtc.mjs
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const here = import.meta.dirname;
const rustExe = join(here, 'target', 'release', 'webrtc-send.exe');
const chromeExe = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const queues = { toRust: [], toBrowser: [] };
let counter = 0;
const rustLogs = [];
const browserLogs = [];
let rustStats = null;
let resolveReport;
const reported = new Promise((resolve) => { resolveReport = resolve; });

const server = http.createServer((request, response) => {
  const url = new URL(request.url, 'http://x');

  if (request.method === 'GET' && url.pathname === '/') {
    return response.writeHead(200, { 'content-type': 'text/html' }).end(readFileSync(join(here, 'webrtc-receive.html')));
  }
  if (request.method === 'GET' && (url.pathname === '/from-browser' || url.pathname === '/from-rust')) {
    const since = Number(url.searchParams.get('since') ?? 0);
    const queue = url.pathname === '/from-browser' ? queues.toRust : queues.toBrowser;
    return response.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({ messages: queue.filter((m) => m.n > since) }));
  }

  let body = '';
  request.on('data', (chunk) => { body += chunk; });
  request.on('end', () => {
    const data = body ? JSON.parse(body) : {};
    if (url.pathname === '/to-rust') queues.toRust.push({ n: ++counter, data });
    if (url.pathname === '/to-browser') queues.toBrowser.push({ n: ++counter, data: toBrowserPayload(data) });
    if (url.pathname === '/log') { browserLogs.push(data); console.log(`  [browser] ${data.event}${data.state ? ` ${data.state}` : ''}${data.message ? ` ${data.message}` : ''}`); }
    if (url.pathname === '/rust-stats') rustStats = data;
    if (url.pathname === '/report') resolveReport(data);
    response.writeHead(204).end();
  });
});

// The Rust side's ToBrowser enum is {kind:"Answer",sdp} / {kind:"Candidate",candidate};
// the browser only understands plain {answer} / {candidate}.
function toBrowserPayload(message) {
  if (message.kind === 'Answer') return { answer: message.sdp };
  if (message.kind === 'Candidate') return { candidate: message.candidate };
  return message;
}

const port = await new Promise((resolve) => {
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1', () => { const { port: free } = probe.address(); probe.close(() => resolve(free)); });
});
await new Promise((resolve) => server.listen(port, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${port}`;

const profile = mkdtempSync(join(tmpdir(), 'toh-klas-webrtc-'));
let rust;
let chrome;
try {
  console.log('starting the Rust sender (WGC capture -> H.264 -> WebRTC, no STUN/TURN)');
  rust = spawn(rustExe, [base], { stdio: ['ignore', 'pipe', 'pipe'] });
  const tag = (stream, prefix) => stream.on('data', (chunk) => {
    for (const line of chunk.toString().split('\n')) {
      if (!line.trim()) continue;
      rustLogs.push(line);
      console.log(`  ${prefix} ${line}`);
    }
  });
  tag(rust.stdout, '[rust]');
  tag(rust.stderr, '[rust:err]');

  console.log('starting the Teacher side (headless Chrome, receive-only, no STUN/TURN)');
  chrome = spawn(chromeExe, [
    '--headless=new', `--user-data-dir=${profile}`, '--autoplay-policy=no-user-gesture-required',
    '--disable-features=WebRtcHideLocalIpsWithMdns', '--no-first-run', `${base}/`,
  ], { stdio: 'ignore' });

  const timeout = new Promise((resolve) => setTimeout(() => resolve({ ok: false, reason: 'timed out after 30s' }), 30000));
  const report = await Promise.race([reported, timeout]);
  // The Rust side keeps sending a couple more seconds after the browser samples its
  // stats; give it a moment to post its own totals before tearing everything down.
  for (let i = 0; i < 30 && rustStats === null; i += 1) await new Promise((resolve) => setTimeout(resolve, 200));

  console.log('\n=== result ===');
  if (!report.ok) {
    console.log('FAILED:', report.reason ?? `browser report: ${JSON.stringify(report)}`, report.stack ?? '');
    process.exitCode = 1;
  } else {
    console.log(`connection state (browser) : ${report.connectionState}`);
    console.log(`candidate pair              : local=${report.candidatePair?.local} remote=${report.candidatePair?.remote} ${report.candidatePair?.protocol}`);
    console.log(`decoded video size          : ${report.videoSize.width}x${report.videoSize.height}`);
    console.log(`frames decoded              : ${report.framesDecoded} (freezes: ${report.freezeCount})`);
    console.log(`packets received/lost       : ${report.packetsReceived}/${report.packetsLost}`);
    console.log(`decoder                     : ${report.decoder}`);
    console.log(`picture is not blank        : mean brightness ${report.meanBrightness}`);
    console.log(`rust side reported          : ${JSON.stringify(rustStats)}`);

    const directPath = report.candidatePair?.local === 'host' && report.candidatePair?.remote === 'host';
    const ok = report.connectionState === 'connected' && report.framesDecoded > 30 && report.packetsLost === 0 && directPath && report.meanBrightness > 3;
    console.log(`\ndirect connection (no relay): ${directPath}`);
    console.log(ok ? 'PASS' : 'FAIL');
    process.exitCode = ok ? 0 : 1;
  }
} finally {
  for (const child of [rust, chrome]) {
    try { if (child?.pid) execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  }
  server.close();
  await new Promise((resolve) => setTimeout(resolve, 800));
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
}
