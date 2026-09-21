import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BrowserBridge, detectBrowser, HOST_NAME, isHttpUrl } from '../src/bridge.js';

const settle = async () => {
  for (let i = 0; i < 6; i += 1) await new Promise((resolve) => setImmediate(resolve));
};

const emitter = () => {
  const listeners = [];
  return { addListener: (fn) => listeners.push(fn), fire: (...args) => listeners.forEach((fn) => fn(...args)) };
};

function harness({ tabs = [], windows = [{ id: 1, focused: true }], focused = { id: 1, focused: true }, browser = 'chrome' } = {}) {
  const state = { hostDown: false, tabs: [...tabs], windows, focused, nextTabId: 100, clock: Date.parse('2026-09-22T08:00:00Z') };
  const events = Object.fromEntries(
    ['onCreated', 'onAttached', 'onDetached', 'onReplaced', 'onRemoved', 'onUpdated', 'onActivated'].map((name) => [name, emitter()]),
  );
  const windowEvents = Object.fromEntries(['onFocusChanged', 'onCreated', 'onRemoved'].map((name) => [name, emitter()]));
  const alarmEvent = emitter();
  const ports = [];
  const calls = { create: [], update: [], remove: [], windowsCreate: [] };
  const timers = new Map();
  let timerId = 0;

  const missing = (id) => Promise.reject(new Error(`No tab with id ${id}`));
  const chrome = {
    runtime: {
      lastError: undefined,
      getManifest: () => ({ version: '0.1.0' }),
      connectNative: (name) => {
        const port = { name, sent: [], onMessage: emitter(), onDisconnect: emitter(), postMessage: (m) => port.sent.push(m) };
        ports.push(port);
        // Like Chrome, a missing host is reported as a disconnect after connectNative returns.
        if (state.hostDown) queueMicrotask(() => port.onDisconnect.fire());
        return port;
      },
    },
    tabs: {
      ...events,
      query: async () => state.tabs,
      get: async (id) => state.tabs.find((tab) => tab.id === id) ?? missing(id),
      create: async (options) => {
        calls.create.push(options);
        const tab = { id: state.nextTabId++, windowId: 1, ...options };
        state.tabs.push(tab);
        return tab;
      },
      update: async (id, options) => {
        calls.update.push([id, options]);
        return state.tabs.find((tab) => tab.id === id) ?? missing(id);
      },
      remove: async (id) => {
        calls.remove.push(id);
        state.tabs = state.tabs.filter((tab) => tab.id !== id);
      },
    },
    windows: {
      ...windowEvents,
      getLastFocused: async () => state.focused,
      getAll: async () => state.windows,
      create: async (options) => {
        calls.windowsCreate.push(options);
        return { id: 9, tabs: [{ id: 500 }] };
      },
      update: async () => ({}),
    },
    alarms: { create: () => {}, onAlarm: alarmEvent },
  };

  const bridge = new BrowserBridge({
    chrome,
    browser,
    now: () => new Date(state.clock),
    uuid: (() => {
      let n = 0;
      return () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`;
    })(),
    setTimer: (fn, ms) => {
      timers.set(++timerId, { fn, ms });
      return timerId;
    },
    clearTimer: (id) => timers.delete(id),
  });

  const runTimers = async () => {
    for (let guard = 0; guard < 20 && timers.size > 0; guard += 1) {
      const due = [...timers.entries()];
      due.forEach(([id]) => timers.delete(id));
      due.forEach(([, timer]) => timer.fn());
      await settle();
    }
  };

  const sentOfType = (type) => ports.flatMap((port) => port.sent).filter((message) => message.type === type);
  return { bridge, chrome, state, ports, calls, timers, events, windowEvents, alarmEvent, runTimers, sentOfType };
}

const tab = (id, url, extra = {}) => ({ id, windowId: 1, url, title: `Title ${id}`, active: false, incognito: false, ...extra });

test('start connects to the native host and introduces the browser', () => {
  const { bridge, ports, sentOfType } = harness({ browser: 'edge' });
  bridge.start();

  assert.equal(ports.length, 1);
  assert.equal(ports[0].name, HOST_NAME);
  const [hello] = sentOfType('browser.hello');
  assert.deepEqual(hello.payload, { browser: 'edge', extension_version: '0.1.0' });
  assert.equal(hello.version, 1);
});

test('a snapshot lists normal tabs and marks only the selected tab of the focused window active', async () => {
  const { bridge, runTimers, sentOfType } = harness({
    tabs: [
      tab(1, 'https://a.example/', { active: true }),
      tab(2, 'https://b.example/'),
      tab(3, 'https://other-window.example/', { windowId: 2, active: true }),
      tab(4, 'https://private.example/', { incognito: true, active: true }),
      tab(-1, 'devtools://x'),
    ],
    windows: [{ id: 1 }, { id: 2 }],
  });
  bridge.start();
  await runTimers();

  const [snapshot] = sentOfType('browser.snapshot');
  assert.deepEqual(snapshot.payload.tabs.map((t) => [t.tab_id, t.active]), [[1, true], [2, false], [3, false]]);
  assert.equal(snapshot.payload.observed_at, '2026-09-22T08:00:00.000Z');
});

test('no tab is active when the browser does not have focus', async () => {
  const { bridge, runTimers, sentOfType } = harness({
    tabs: [tab(1, 'https://a.example/', { active: true })],
    focused: { id: 1, focused: false },
  });
  bridge.start();
  await runTimers();

  assert.equal(sentOfType('browser.snapshot')[0].payload.tabs[0].active, false);
});

test('a burst of tab events collapses into a single snapshot', async () => {
  const { bridge, events, windowEvents, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/', { active: true })] });
  bridge.start();
  await runTimers();
  const before = sentOfType('browser.snapshot').length;

  events.onCreated.fire({});
  events.onRemoved.fire(7);
  events.onAttached.fire();
  windowEvents.onFocusChanged.fire(1);
  await runTimers();

  assert.equal(sentOfType('browser.snapshot').length, before + 1);
});

test('a navigation is logged once, after the page settles, and repeats are ignored', async () => {
  const { bridge, state, events, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/')] });
  bridge.start();
  await runTimers();

  state.tabs = [tab(1, 'https://khan.example/lesson', { title: 'Lesson' })];
  events.onUpdated.fire(1, { url: 'https://khan.example/lesson' });
  events.onUpdated.fire(1, { title: 'Lesson' });
  events.onUpdated.fire(1, { status: 'complete' });
  await runTimers();
  events.onUpdated.fire(1, { status: 'complete' });
  await runTimers();

  const activity = sentOfType('browser.activity');
  assert.equal(activity.length, 1);
  assert.deepEqual(activity[0].payload.events.map((e) => [e.type, e.url, e.title]), [['navigated', 'https://khan.example/lesson', 'Lesson']]);
  assert.match(activity[0].payload.events[0].uuid, /^[0-9a-f-]{36}$/);
  assert.equal(activity[0].payload.browser, 'chrome');
});

test('activating a tab is logged, but private tabs never are', async () => {
  const { bridge, events, runTimers, sentOfType } = harness({
    tabs: [tab(1, 'https://a.example/'), tab(2, 'https://private.example/', { incognito: true })],
  });
  bridge.start();
  await runTimers();

  events.onActivated.fire({ tabId: 1 });
  events.onActivated.fire({ tabId: 2 });
  await runTimers();

  const logged = sentOfType('browser.activity').flatMap((m) => m.payload.events);
  assert.deepEqual(logged.map((e) => [e.type, e.url]), [['activated', 'https://a.example/']]);
});

test('events recorded while the host is down are delivered once it is back, capped at 500', async () => {
  const { bridge, state, ports, events, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/')] });
  bridge.start();
  await runTimers();

  state.hostDown = true;
  ports[0].onDisconnect.fire();
  for (let i = 0; i < 520; i += 1) {
    state.tabs = [tab(1, `https://site${i}.example/`)];
    events.onUpdated.fire(1, { status: 'complete' });
    await runTimers();
  }
  assert.equal(sentOfType('browser.activity').length, 0, 'nothing may be written to a port that is already dying');

  state.hostDown = false;
  await runTimers();
  const delivered = ports.at(-1).sent.filter((m) => m.type === 'browser.activity').flatMap((m) => m.payload.events);
  assert.equal(delivered.length, 500);
  assert.equal(delivered.at(-1).url, 'https://site519.example/');
  assert.equal(delivered[0].url, 'https://site20.example/');
});

test('reconnects back off exponentially, cap at 30 seconds, and reset after a stable connection', async () => {
  const { bridge, state, ports, timers } = harness();
  bridge.start();
  const delays = [];

  for (let i = 0; i < 7; i += 1) {
    ports.at(-1).onDisconnect.fire();
    const timer = timers.get(bridge.reconnectTimer);
    delays.push(timer.ms);
    timers.delete(bridge.reconnectTimer);
    timer.fn();
  }
  assert.deepEqual(delays, [1000, 2000, 4000, 8000, 16000, 30000, 30000]);

  state.clock += 60000;
  ports.at(-1).onDisconnect.fire();
  assert.equal(timers.get(bridge.reconnectTimer).ms, 1000);
});

const command = (ports, type, payload, id = 'cmd-1') => {
  ports.at(-1).onMessage.fire({ version: 1, id, type, occurred_at: '2026-09-22T08:00:00Z', payload });
};

test('open_url opens a tab and answers with the command id', async () => {
  const { bridge, ports, calls, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/')] });
  bridge.start();

  command(ports, 'browser.open_url', { url: 'https://example.com/lesson' });
  await settle();

  assert.deepEqual(calls.create, [{ url: 'https://example.com/lesson', active: true }]);
  const [response] = sentOfType('response');
  assert.equal(response.id, 'cmd-1');
  assert.deepEqual(response.payload, { status: 'completed', result: { tab_id: 100 } });
});

test('open_url creates a window when the browser has none', async () => {
  const { bridge, ports, calls, sentOfType } = harness({ windows: [] });
  bridge.start();

  command(ports, 'browser.open_url', { url: 'https://example.com' });
  await settle();

  assert.equal(calls.windowsCreate.length, 1);
  assert.equal(sentOfType('response')[0].payload.result.tab_id, 500);
});

test('script, file, browser-internal and malformed URLs are refused by the extension itself', async () => {
  const { bridge, ports, calls, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/')] });
  bridge.start();

  const bad = ['javascript:alert(1)', 'file:///c:/windows/win.ini', 'chrome://settings', 'data:text/html,hi', 'not a url', '', null, 'https://' + 'a'.repeat(3000)];
  for (const [index, url] of bad.entries()) {
    command(ports, 'browser.open_url', { url }, `open-${index}`);
    command(ports, 'browser.navigate', { tab_id: 1, url }, `nav-${index}`);
  }
  await settle();

  assert.equal(calls.create.length, 0);
  assert.equal(calls.update.length, 0);
  const responses = sentOfType('response');
  assert.equal(responses.length, bad.length * 2);
  assert.ok(responses.every((r) => r.payload.status === 'failed' && r.payload.result.error === 'INVALID_URL'));
});

test('navigate and close_tab act on the named tab and report a missing one', async () => {
  const { bridge, ports, calls, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/')] });
  bridge.start();

  command(ports, 'browser.navigate', { tab_id: 1, url: 'https://b.example/' }, 'n1');
  command(ports, 'browser.navigate', { tab_id: 99, url: 'https://b.example/' }, 'n2');
  command(ports, 'browser.close_tab', { tab_id: 1 }, 'c1');
  command(ports, 'browser.close_tab', { tab_id: 99 }, 'c2');
  await settle();

  assert.deepEqual(calls.update[0], [1, { url: 'https://b.example/' }]);
  assert.deepEqual(calls.remove, [1]);
  const byId = Object.fromEntries(sentOfType('response').map((r) => [r.id, r.payload]));
  assert.equal(byId.n1.status, 'completed');
  assert.equal(byId.n2.result.error, 'TAB_NOT_FOUND');
  assert.equal(byId.c1.status, 'completed');
  assert.equal(byId.c2.result.error, 'TAB_NOT_FOUND');
});

test('commands for the other browser, unknown commands, and bad protocol versions are not executed', async () => {
  const { bridge, ports, calls, sentOfType } = harness({ browser: 'chrome' });
  bridge.start();

  command(ports, 'browser.open_url', { url: 'https://example.com', browser: 'edge' }, 'w1');
  command(ports, 'browser.wipe_history', {}, 'u1');
  ports[0].onMessage.fire({ version: 2, id: 'v1', type: 'browser.open_url', payload: { url: 'https://example.com' } });
  ports[0].onMessage.fire({ version: 1, id: 's1', type: 'system.shutdown', payload: {} });
  ports[0].onMessage.fire(null);
  await settle();

  assert.equal(calls.create.length, 0);
  const byId = Object.fromEntries(sentOfType('response').map((r) => [r.id, r.payload]));
  assert.equal(byId.w1.result.error, 'WRONG_BROWSER');
  assert.equal(byId.u1.result.error, 'UNSUPPORTED_COMMAND');
  assert.deepEqual(Object.keys(byId).sort(), ['u1', 'w1']);
});

test('a snapshot request from the agent triggers an immediate snapshot', async () => {
  const { bridge, ports, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://a.example/', { active: true })] });
  bridge.start();
  await runTimers();
  const before = sentOfType('browser.snapshot').length;

  ports[0].onMessage.fire({ version: 1, id: 'r', type: 'browser.request_snapshot', payload: {} });
  await runTimers();

  assert.equal(sentOfType('browser.snapshot').length, before + 1);
});

test('isHttpUrl and detectBrowser', () => {
  assert.ok(isHttpUrl('http://example.com'));
  assert.ok(isHttpUrl('https://example.com/a?b=1#c'));
  assert.ok(!isHttpUrl('ftp://example.com'));
  assert.equal(detectBrowser('Mozilla/5.0 Chrome/126.0 Safari/537.36 Edg/126.0'), 'edge');
  assert.equal(detectBrowser('Mozilla/5.0 Chrome/126.0 Safari/537.36'), 'chrome');
});
