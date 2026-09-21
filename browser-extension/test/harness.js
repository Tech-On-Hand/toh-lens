import { BrowserBridge } from '../src/bridge.js';

export const EXTENSION_URL = 'chrome-extension://klasid/';

export const settle = async () => {
  for (let i = 0; i < 6; i += 1) await new Promise((resolve) => setImmediate(resolve));
};

const emitter = () => {
  const listeners = [];
  return { addListener: (fn) => listeners.push(fn), fire: (...args) => listeners.forEach((fn) => fn(...args)) };
};

export function harness({ tabs = [], windows = [{ id: 1, focused: true }], focused = { id: 1, focused: true }, browser = 'chrome' } = {}) {
  const state = { hostDown: false, tabs: [...tabs], windows, focused, nextTabId: 100, clock: Date.parse('2026-09-22T08:00:00Z') };
  const events = Object.fromEntries(
    ['onCreated', 'onAttached', 'onDetached', 'onReplaced', 'onRemoved', 'onUpdated', 'onActivated'].map((name) => [name, emitter()]),
  );
  const windowEvents = Object.fromEntries(['onFocusChanged', 'onCreated', 'onRemoved'].map((name) => [name, emitter()]));
  const alarmEvent = emitter();
  const ports = [];
  const calls = { create: [], update: [], remove: [], windowsCreate: [] };
  const stored = {};
  const dnr = { rules: [], updates: 0 };
  const timers = new Map();
  let timerId = 0;

  const missing = (id) => Promise.reject(new Error(`No tab with id ${id}`));
  const chrome = {
    runtime: {
      lastError: undefined,
      getManifest: () => ({ version: '0.1.0' }),
      getURL: (path) => EXTENSION_URL + path,
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
        const found = state.tabs.find((tab) => tab.id === id);
        if (!found) return missing(id);
        if (options.url) found.url = options.url;
        return found;
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
    storage: {
      local: {
        get: async (key) => (key in stored ? { [key]: structuredClone(stored[key]) } : {}),
        set: async (values) => Object.assign(stored, structuredClone(values)),
      },
    },
    declarativeNetRequest: {
      updateDynamicRules: async ({ removeRuleIds, addRules }) => {
        dnr.rules = [...dnr.rules.filter((rule) => !removeRuleIds.includes(rule.id)), ...addRules];
        dnr.updates += 1;
      },
    },
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
  return { bridge, chrome, state, ports, calls, stored, dnr, timers, events, windowEvents, alarmEvent, runTimers, sentOfType };
}

export const tab = (id, url, extra = {}) => ({ id, windowId: 1, url, title: `Title ${id}`, active: false, incognito: false, ...extra });
