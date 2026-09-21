// Drives the real, built native host binary the way Chrome and the Student Agent
// would: framed messages on stdin/stdout, and a loopback socket standing in for
// the agent. Run after `cargo build --release`:  node e2e/host-e2e.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import net from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const exe = join(import.meta.dirname, '..', 'target', 'release', 'toh-klas-native-host.exe');
const token = 'e2e-secret';
const dir = mkdtempSync(join(tmpdir(), 'toh-klas-e2e-'));

const frame = (message) => {
  const body = Buffer.from(JSON.stringify(message));
  const header = Buffer.alloc(4);
  header.writeUInt32LE(body.length);
  return Buffer.concat([header, body]);
};

const agentLines = [];
const server = net.createServer((socket) => {
  let buffer = '';
  socket.on('data', (chunk) => {
    buffer += chunk;
    let index;
    while ((index = buffer.indexOf('\n')) !== -1) {
      const line = buffer.slice(0, index);
      buffer = buffer.slice(index + 1);
      agentLines.push(JSON.parse(line));
      if (agentLines.length === 2) {
        socket.write(JSON.stringify({ version: 1, id: 'c1', type: 'browser.open_url', payload: { url: 'https://example.com' } }) + '\n');
      }
    }
  });
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
writeFileSync(join(dir, 'bridge.json'), JSON.stringify({ port: server.address().port, token }));

const host = spawn(exe, ['chrome-extension://ifialcnolohnhdlojcngcdgiglgffeih/'], {
  env: { ...process.env, TOH_KLAS_BRIDGE_DIR: dir },
  stdio: ['pipe', 'pipe', 'inherit'],
});

const stdout = [];
host.stdout.on('data', (chunk) => stdout.push(chunk));
host.stdin.write(frame({ version: 1, id: 'h1', type: 'browser.hello', payload: { browser: 'chrome' } }));
host.stdin.write(frame({ version: 1, id: 's1', type: 'browser.snapshot', payload: { tabs: [{ tab_id: 1, title: 'Zażółć gęślą jaźń \u{1F600}' }] } }));

const deadline = Date.now() + 5000;
while (Buffer.concat(stdout).length === 0 && Date.now() < deadline) await new Promise((r) => setTimeout(r, 25));

const output = Buffer.concat(stdout);
assert.ok(output.length > 4, 'the host wrote nothing to stdout');
const length = output.readUInt32LE(0);
assert.equal(output.length, 4 + length, 'stdout must contain exactly one complete frame');
assert.deepEqual(JSON.parse(output.subarray(4).toString('utf8')), {
  version: 1, id: 'c1', type: 'browser.open_url', payload: { url: 'https://example.com' },
});

assert.deepEqual(agentLines[0], { type: 'bridge.auth', token });
assert.equal(agentLines[1].type, 'browser.hello');
assert.equal(agentLines.length, 3);
assert.equal(agentLines[2].type, 'browser.snapshot');
assert.equal(agentLines[2].payload.tabs[0].title, 'Zażółć gęślą jaźń \u{1F600}', 'UTF-8 must survive the relay unchanged');

host.stdin.end();
await new Promise((resolve) => host.on('exit', resolve));
server.close();
console.log('host e2e ok: auth, upstream relay, downstream frame, clean exit on stdin close');
