// Asks the running capture page (over the WebView2 DevTools port) what state it is in.
// One short run: the small target window is on screen for roughly ten seconds.
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';

const debugPort = 9345;
const child = spawn(join(import.meta.dirname, 'target', 'debug', 'screen-capture-spike.exe'), [], {
  env: { ...process.env, SPIKE_PORT: '1', SPIKE_DEBUG_PORT: String(debugPort), SPIKE_VISIBLE: process.env.SPIKE_VISIBLE ?? '0' },
  stdio: 'ignore',
});
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

try {
  await sleep(8000);
  const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
  console.log('targets:', targets.map((t) => `${t.type} ${t.url.slice(0, 60)}`));
  const page = targets.find((t) => t.url.includes('capture.html'));
  const socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve) => { socket.onopen = resolve; });

  let nextId = 0;
  const ask = (expression) => new Promise((resolve) => {
    const id = ++nextId;
    const timer = setTimeout(() => resolve('NO ANSWER in 5s: the page main thread looks blocked'), 5000);
    const handler = (event) => {
      const message = JSON.parse(event.data);
      if (message.id !== id) return;
      clearTimeout(timer);
      socket.removeEventListener('message', handler);
      resolve(JSON.stringify(message.result?.result?.value ?? message));
    };
    socket.addEventListener('message', handler);
    socket.send(JSON.stringify({ id, method: 'Runtime.evaluate', params: { expression, returnByValue: true } }));
  });

  console.log('state       :', await ask('window.__state'));
  console.log('visibility  :', await ask('document.visibilityState'));
  console.log('page uptime :', await ask('Math.round(performance.now())'));
  console.log('timers alive:', await ask('new Promise((r) => setTimeout(() => r("yes"), 300))'));
  socket.close();
} finally {
  try { execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' }); } catch {}
}
