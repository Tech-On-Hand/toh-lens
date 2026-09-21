// Feeds a raw H.264 frame container (written by `native-capture-spike --raw`) into
// Chrome's real WebCodecs decoder and reports whether it decodes cleanly.
//
//   target/release/native-capture-spike --raw
//   node verify_decode.mjs %TEMP%/toh-klas-raw-spike-1280x720.h264frames
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const framesPath = process.argv[2];
if (!framesPath) {
  console.error('usage: node verify_decode.mjs <path-to-.h264frames>');
  process.exit(2);
}
const chromeExe = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const here = import.meta.dirname;

let resolveReport;
const reported = new Promise((resolve) => { resolveReport = resolve; });
const logs = [];

const server = http.createServer((request, response) => {
  if (request.method === 'GET' && request.url === '/') {
    return response.writeHead(200, { 'content-type': 'text/html' }).end(readFileSync(join(here, 'decode.html')));
  }
  if (request.method === 'GET' && request.url === '/frames') {
    return response.writeHead(200, { 'content-type': 'application/octet-stream' }).end(readFileSync(framesPath));
  }
  let body = '';
  request.on('data', (chunk) => { body += chunk; });
  request.on('end', () => {
    const data = body ? JSON.parse(body) : {};
    if (request.url === '/log') logs.push(data);
    if (request.url === '/report') resolveReport(data);
    response.writeHead(204).end();
  });
});

const port = await new Promise((resolve) => {
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1', () => { const { port: free } = probe.address(); probe.close(() => resolve(free)); });
});
await new Promise((resolve) => server.listen(port, '127.0.0.1', resolve));

const profile = mkdtempSync(join(tmpdir(), 'toh-klas-decode-'));
let chrome;
try {
  chrome = spawn(chromeExe, ['--headless=new', `--user-data-dir=${profile}`, '--no-first-run', `http://127.0.0.1:${port}/`], { stdio: 'ignore' });

  const timeout = new Promise((resolve) => setTimeout(() => resolve({ ok: false, reason: 'timed out after 20s' }), 20000));
  const report = await Promise.race([reported, timeout]);

  console.log('=== decode result ===');
  console.log('input file:', framesPath, `(${readFileSync(framesPath).length} bytes)`);
  logs.forEach((entry) => console.log(`  [browser] ${entry.event}: ${JSON.stringify(entry)}`));

  if (!report.ok) {
    console.log('FAILED:', report.reason, report.stack ?? '');
    process.exit(1);
  }

  console.log(`codec string      : ${report.codec}`);
  console.log(`hardware decoder  : ${report.hardwareSupported}`);
  console.log(`access units fed  : ${report.totalFrames} (${report.keyframes} keyframes)`);
  console.log(`frames decoded    : ${report.decoded}`);
  console.log(`decode errors     : ${report.errors.length}${report.errors.length ? ' -> ' + report.errors.join('; ') : ''}`);
  console.log(`decode time       : ${report.decodeMs.toFixed(0)} ms for ${report.totalFrames} frames (${(report.totalFrames / (report.decodeMs / 1000)).toFixed(0)} fps)`);
  console.log(`last decoded size : ${report.decodedSize ? `${report.decodedSize.width}x${report.decodedSize.height}` : 'n/a'}`);
  console.log(`last frame not blank: mean brightness ${report.meanBrightness}`);

  const ok = report.errors.length === 0 && report.decoded === report.totalFrames && report.decoded > 0;
  console.log(`\n${ok ? 'PASS' : 'FAIL'}`);
  process.exit(ok ? 0 : 1);
} finally {
  try { if (chrome?.pid) execFileSync('taskkill', ['/PID', String(chrome.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
  server.close();
  await new Promise((resolve) => setTimeout(resolve, 500));
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
}
