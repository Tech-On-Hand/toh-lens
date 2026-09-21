// Spike: can the student agent's own (hidden) WebView2 capture the screen silently
// and stream it to a teacher over a direct WebRTC connection, at two quality levels?
//
//   cargo build            (in this folder)
//   node run.mjs
//
// A visible "TOHKLAS-SPIKE-TARGET" window appears for about half a minute. Only that
// window is captured, never your desktop. The receiver reports numbers, not pictures.
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const here = import.meta.dirname;
const spikeExe = join(here, 'target', 'debug', 'screen-capture-spike.exe');
const chromeExe = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const queues = { recv: [], caps: [] };
const logs = [];
const phases = [];
let counter = 0;
let resolveReport;
const reported = new Promise((resolve) => { resolveReport = resolve; });

function cpuSeconds() {
  const command = "$ids = (Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'screen-capture-spike.exe' -or $_.CommandLine -like '*com.techonhand.spike*' }).ProcessId; " +
    '(Get-Process -Id $ids -ErrorAction SilentlyContinue | Measure-Object -Property CPU -Sum).Sum';
  return Number(spawnSync('powershell', ['-NoProfile', '-Command', command], { encoding: 'utf8' }).stdout.trim()) || 0;
}

const server = http.createServer((request, response) => {
  response.setHeader('access-control-allow-origin', '*');
  response.setHeader('access-control-allow-headers', 'content-type');
  if (request.method === 'OPTIONS') return response.writeHead(204).end();

  const url = new URL(request.url, 'http://x');
  if (request.method === 'GET' && url.pathname === '/receiver.html') {
    return response.writeHead(200, { 'content-type': 'text/html' }).end(readFileSync(join(here, 'receiver.html')));
  }
  if (request.method === 'GET' && url.pathname.startsWith('/poll/')) {
    const since = Number(url.searchParams.get('since') ?? 0);
    const role = url.pathname.split('/')[2];
    return response.writeHead(200, { 'content-type': 'application/json' }).end(JSON.stringify({ messages: queues[role].filter((m) => m.n > since) }));
  }

  let body = '';
  request.on('data', (chunk) => { body += chunk; });
  request.on('end', () => {
    const data = body ? JSON.parse(body) : {};
    if (url.pathname.startsWith('/signal/')) queues[url.pathname.split('/')[2]].push({ n: ++counter, data });
    if (url.pathname === '/log') {
      logs.push(data);
      if (data.event !== 'outbound' && data.event !== 'alive') console.log(`  [${data.at}] ${data.event}${data.state ? ` ${data.state}` : ''}${data.mode ? ` ${data.mode}` : ''}${data.message ? ` ${data.message}` : ''}`);
    }
    if (url.pathname === '/phase') {
      const now = { t: Date.now(), cpu: cpuSeconds() };
      if (data.edge === 'start') phases.push({ mode: data.mode, start: now });
      else phases.findLast((p) => p.mode === data.mode && !p.end).end = now;
    }
    if (url.pathname === '/report') resolveReport(data);
    response.writeHead(204).end();
  });
});

const port = await new Promise((resolve) => {
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1', () => { const { port: free } = probe.address(); probe.close(() => resolve(free)); });
});
await new Promise((resolve) => server.listen(port, '127.0.0.1', resolve));

const profile = mkdtempSync(join(tmpdir(), 'toh-klas-spike-'));
let app;
let receiver;
try {
  console.log('starting the student app (visible target window + hidden capture window)');
  app = spawn(spikeExe, [], { env: { ...process.env, SPIKE_PORT: String(port) }, stdio: 'ignore' });

  console.log('starting the teacher (headless Chrome)');
  receiver = spawn(chromeExe, [
    '--headless=new', `--user-data-dir=${profile}`, '--autoplay-policy=no-user-gesture-required',
    '--disable-features=WebRtcHideLocalIpsWithMdns', '--no-first-run', `http://127.0.0.1:${port}/receiver.html`,
  ], { stdio: 'ignore' });

  const timeout = new Promise((resolve) => setTimeout(() => resolve({ ok: false, reason: 'timed out after 120s' }), 120000));
  const report = await Promise.race([reported, timeout]);

  console.log('\n=== result ===');
  if (!report.ok) {
    console.log('FAILED:', report.reason);
    const student = logs.filter((l) => l.at === 'student' && l.event !== 'outbound').map((l) => `${l.event} ${JSON.stringify(l)}`);
    console.log(student.join('\n'));
  } else {
    const student = logs.find((l) => l.event === 'page-loaded');
    const started = logs.find((l) => l.event === 'capture-started');
    console.log(`capture window was: ${student.visibility} (the student's hidden window)`);
    console.log(`silent capture: ${started ? `yes, after ${started.ms} ms; source "${started.label}"; ${JSON.stringify(started.settings)}` : 'NO'}`);
    console.log(`network path: local=${report.path?.local} remote=${report.path?.remote} ${report.path?.protocol}, rtt ${report.rttMs} ms`);
    console.log(`video codec: ${report.codec}; decoder: ${report.decoder}; freezes: ${report.freezes}`);
    const encoder = logs.filter((l) => l.event === 'outbound').at(-1);
    console.log(`encoder: ${encoder?.encoder} (power efficient/hardware: ${encoder?.powerEfficient})`);
    console.log(`teacher's picture is not blank: mean brightness ${report.meanBrightness} (target background is dark green, text is light)`);
    console.log('\nmode   size        fps    kbps    student CPU (% of one core)');
    report.results.forEach((r, i) => {
      const phase = phases.filter((p) => p.mode === r.mode)[r.mode === 'thumb' ? (i === 0 ? 0 : 1) : 0];
      const cpu = phase?.end ? (((phase.end.cpu - phase.start.cpu) / ((phase.end.t - phase.start.t) / 1000)) * 100).toFixed(0) : '?';
      console.log(`${r.mode.padEnd(6)} ${r.size.padEnd(11)} ${String(r.decodedFps).padEnd(6)} ${String(r.kbps).padEnd(7)} ${cpu}%`);
    });
  }
} finally {
  for (const child of [app, receiver]) {
    try { if (child?.pid) execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  }
  server.close();
  await new Promise((resolve) => setTimeout(resolve, 1000));
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
}
process.exit(0);
