// Spike: does "one encode, N sends" (a single TrackLocalStaticSample bound to N
// RTCPeerConnections) actually work at a real N, for the teacher-broadcasts-their-
// screen direction of Milestone 4? Answers what N buys you (encode/CPU cost stays
// flat) and what it can't (network egress, which is inherently N times the bitrate).
//
//   cargo build --release --bin broadcast-send   (in this folder)
//   node broadcast.mjs [count]
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const here = import.meta.dirname;
const rustExe = join(here, 'target', 'release', 'broadcast-send.exe');
const chromeExe = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const count = Number(process.argv[2] ?? 30);

const connections = new Map(); // id -> { toRust: [], toBrowser: [], counter }
const connState = (id) => connections.get(id) ?? connections.set(id, { toRust: [], toBrowser: [], counter: 0 }).get(id);

function toBrowserPayload(message) {
  if (message.kind === 'Answer') return { answer: message.sdp };
  if (message.kind === 'Candidate') return { candidate: message.candidate };
  return message;
}

let rustStats = null;
let resolveReport;
const reported = new Promise((resolve) => { resolveReport = resolve; });
const rustLogs = [];

const server = http.createServer((request, response) => {
  const url = new URL(request.url, 'http://x');

  if (request.method === 'GET' && url.pathname === '/') {
    return response.writeHead(200, { 'content-type': 'text/html' }).end(readFileSync(join(here, 'broadcast-receive.html')));
  }

  const connMatch = url.pathname.match(/^\/conn\/(\d+)\/(to-rust|to-browser|from-browser|from-rust)$/);
  if (connMatch) {
    const [, idText, action] = connMatch;
    const state = connState(Number(idText));

    if (action === 'from-browser' || action === 'from-rust') {
      const since = Number(url.searchParams.get('since') ?? 0);
      const queue = action === 'from-browser' ? state.toRust : state.toBrowser;
      return response.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({ messages: queue.filter((m) => m.n > since) }));
    }

    let body = '';
    request.on('data', (chunk) => { body += chunk; });
    return request.on('end', () => {
      const data = JSON.parse(body || '{}');
      if (action === 'to-rust') state.toRust.push({ n: ++state.counter, data });
      if (action === 'to-browser') state.toBrowser.push({ n: ++state.counter, data: toBrowserPayload(data) });
      response.writeHead(204).end();
    });
  }

  let body = '';
  request.on('data', (chunk) => { body += chunk; });
  request.on('end', () => {
    const data = body ? JSON.parse(body) : {};
    if (url.pathname === '/rust-stats') rustStats = data;
    if (url.pathname === '/report') resolveReport(data);
    response.writeHead(204).end();
  });
});

const port = await new Promise((resolve) => {
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1', () => { const { port: free } = probe.address(); probe.close(() => resolve(free)); });
});
await new Promise((resolve) => server.listen(port, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${port}`;

const profile = mkdtempSync(join(tmpdir(), 'toh-klas-broadcast-'));
let rust;
let chrome;
try {
  console.log(`starting the Rust "teacher" (one capture, one encode, ${count} peer connections, no STUN/TURN)`);
  rust = spawn(rustExe, [base, String(count)], { stdio: ['ignore', 'pipe', 'pipe'] });
  const tag = (stream, prefix) => stream.on('data', (chunk) => {
    for (const line of chunk.toString().split('\n')) {
      if (!line.trim()) continue;
      rustLogs.push(line);
      console.log(`  ${prefix} ${line}`);
    }
  });
  tag(rust.stdout, '[rust]');
  tag(rust.stderr, '[rust:err]');

  console.log(`starting the "class" (one headless Chrome, ${count} independent RTCPeerConnections)`);
  chrome = spawn(chromeExe, [
    '--headless=new', `--user-data-dir=${profile}`, '--autoplay-policy=no-user-gesture-required',
    '--disable-features=WebRtcHideLocalIpsWithMdns', '--no-first-run', `${base}/?count=${count}`,
  ], { stdio: 'ignore' });

  const timeout = new Promise((resolve) => setTimeout(() => resolve({ ok: false, reason: 'timed out after 60s' }), 60000));
  const report = await Promise.race([reported, timeout]);
  for (let i = 0; i < 30 && rustStats === null; i += 1) await new Promise((resolve) => setTimeout(resolve, 200));

  console.log('\n=== result ===');
  if (!report.ok) {
    console.log('FAILED:', report.reason ?? JSON.stringify(report), report.stack ?? '');
    process.exitCode = 1;
  } else {
    const avgFps = (report.totalFramesDecoded / report.connected / 9).toFixed(1);
    console.log(`viewers connected            : ${report.connected}/${report.count} (direct host-to-host, no relay: ${report.directPathCount}/${report.count})`);
    console.log(`time to connect all viewers  : ${report.connectMs} ms`);
    console.log(`total frames decoded         : ${report.totalFramesDecoded} across all viewers (~${avgFps} fps/viewer over the 9s sample)`);
    console.log(`total packets lost           : ${report.totalPacketsLost}, freezes: ${report.totalFreezes}`);
    console.log(`total bytes received         : ${report.totalBytesReceived} (${(report.totalBytesReceived * 8 / 9 / 1000).toFixed(0)} kbps aggregate, measured on the receiving side)`);
    console.log(`sampled pictures not blank   : brightness ${JSON.stringify(report.brightnessSamples)}`);
    console.log(`rust side reported           : ${JSON.stringify(rustStats)}`);

    const ok = report.connected === report.count && report.directPathCount === report.count && report.totalFramesDecoded > report.count * 30 && report.brightnessSamples.every((b) => b > 3);
    console.log(`\n${ok ? 'PASS' : 'FAIL'}`);
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
