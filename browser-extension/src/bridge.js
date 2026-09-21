// Connects the browser to the TOH Klas Student Agent through the native messaging
// host: reports what the student has open and carries out teacher commands.
// The browser APIs and timers are injected so this runs unchanged under Node tests.

export const HOST_NAME = 'com.techonhand.klas';
export const PROTOCOL_VERSION = 1;

const SNAPSHOT_DEBOUNCE_MS = 400;
const NAVIGATION_SETTLE_MS = 700;
const EVENT_FLUSH_MS = 1000;
const MAX_BUFFERED_EVENTS = 500;
const EVENTS_PER_MESSAGE = 50;
const RECONNECT_BASE_MS = 1000;
const RECONNECT_MAX_MS = 30000;
const STABLE_CONNECTION_MS = 5000;

/** Only web pages may be opened or navigated to, whatever the server sent. */
export function isHttpUrl(value) {
  if (typeof value !== 'string' || value.length === 0 || value.length > 2048) return false;
  try {
    const { protocol } = new URL(value);
    return protocol === 'http:' || protocol === 'https:';
  } catch {
    return false;
  }
}

export function detectBrowser(userAgent) {
  return /\bEdg\//.test(userAgent) ? 'edge' : 'chrome';
}

export class BrowserBridge {
  constructor({
    chrome,
    browser,
    now = () => new Date(),
    uuid = () => crypto.randomUUID(),
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
  }) {
    this.chrome = chrome;
    this.browser = browser;
    this.now = now;
    this.uuid = uuid;
    this.setTimer = setTimer;
    this.clearTimer = clearTimer;

    this.port = null;
    this.connectedAt = 0;
    this.attempt = 0;
    this.reconnectTimer = null;
    this.snapshotTimer = null;
    this.flushTimer = null;
    this.snapshotRunning = false;
    this.snapshotAgain = false;
    this.events = [];
    this.knownUrls = new Map();
    this.pendingNavigation = new Map();
  }

  start() {
    const { tabs, windows, alarms } = this.chrome;

    tabs.onCreated.addListener(() => this.scheduleSnapshot());
    tabs.onAttached.addListener(() => this.scheduleSnapshot());
    tabs.onDetached.addListener(() => this.scheduleSnapshot());
    tabs.onReplaced.addListener(() => this.scheduleSnapshot());
    tabs.onRemoved.addListener((tabId) => {
      this.knownUrls.delete(tabId);
      this.clearNavigation(tabId);
      this.scheduleSnapshot();
    });
    tabs.onUpdated.addListener((tabId, change) => this.onTabUpdated(tabId, change));
    tabs.onActivated.addListener(({ tabId }) => this.onTabActivated(tabId));
    windows.onFocusChanged.addListener(() => this.scheduleSnapshot());
    windows.onCreated.addListener(() => this.scheduleSnapshot());
    windows.onRemoved.addListener(() => this.scheduleSnapshot());

    alarms.create('toh-klas-keepalive', { periodInMinutes: 0.5 });
    alarms.onAlarm.addListener((alarm) => {
      if (alarm.name !== 'toh-klas-keepalive') return;
      if (this.port) this.scheduleSnapshot(0);
      else this.connect();
    });

    this.connect();
  }

  // --- connection -------------------------------------------------------

  connect() {
    if (this.port) return;
    this.clearTimer(this.reconnectTimer);
    this.reconnectTimer = null;

    let port;
    try {
      port = this.chrome.runtime.connectNative(HOST_NAME);
    } catch {
      this.scheduleReconnect();
      return;
    }

    this.port = port;
    this.connectedAt = this.now().getTime();
    port.onMessage.addListener((message) => this.onHostMessage(message));
    port.onDisconnect.addListener(() => {
      void this.chrome.runtime.lastError; // reading it marks the error as handled
      if (this.port !== port) return;
      this.port = null;
      if (this.now().getTime() - this.connectedAt > STABLE_CONNECTION_MS) this.attempt = 0;
      this.scheduleReconnect();
    });

    this.send('browser.hello', {
      browser: this.browser,
      extension_version: this.chrome.runtime.getManifest().version,
    });
    this.scheduleSnapshot(0);
    // A missing host is only reported as a disconnect shortly after connectNative
    // returns, so buffered events wait to see the connection survive.
    this.scheduleFlush();
  }

  scheduleReconnect() {
    if (this.reconnectTimer) return;
    const delay = Math.min(RECONNECT_MAX_MS, RECONNECT_BASE_MS * 2 ** this.attempt);
    this.attempt += 1;
    this.reconnectTimer = this.setTimer(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  send(type, payload, id = this.uuid()) {
    if (!this.port) return false;
    try {
      this.port.postMessage({
        version: PROTOCOL_VERSION,
        id,
        type,
        occurred_at: this.now().toISOString(),
        payload,
      });
      return true;
    } catch {
      return false;
    }
  }

  // --- reporting --------------------------------------------------------

  scheduleSnapshot(delay = SNAPSHOT_DEBOUNCE_MS) {
    this.clearTimer(this.snapshotTimer);
    this.snapshotTimer = this.setTimer(() => {
      this.snapshotTimer = null;
      void this.sendSnapshot();
    }, delay);
  }

  async sendSnapshot() {
    if (!this.port) return;
    if (this.snapshotRunning) {
      this.snapshotAgain = true;
      return;
    }
    this.snapshotRunning = true;
    try {
      const [tabs, focused] = await Promise.all([
        this.chrome.tabs.query({}),
        this.chrome.windows.getLastFocused().catch(() => null),
      ]);

      const list = tabs
        .filter((tab) => !tab.incognito && Number.isInteger(tab.id) && tab.id >= 0)
        .map((tab) => ({
          tab_id: tab.id,
          window_id: tab.windowId ?? null,
          url: tab.url ?? tab.pendingUrl ?? null,
          title: tab.title ?? null,
          // "Active" means the student is looking at it: the selected tab of the focused window.
          active: Boolean(tab.active && focused?.focused && focused.id === tab.windowId),
        }));

      this.send('browser.snapshot', { observed_at: this.now().toISOString(), tabs: list });
    } catch {
      // A failed query (browser shutting down) is retried by the next trigger.
    } finally {
      this.snapshotRunning = false;
      if (this.snapshotAgain) {
        this.snapshotAgain = false;
        this.scheduleSnapshot(0);
      }
    }
  }

  onTabUpdated(tabId, change) {
    if (change.url !== undefined || change.title !== undefined || change.status !== undefined) {
      this.scheduleSnapshot();
    }
    // Wait for the title to settle so a navigation is logged once, with its final title.
    if (change.url !== undefined || change.title !== undefined || change.status === 'complete') {
      this.clearNavigation(tabId);
      this.pendingNavigation.set(
        tabId,
        this.setTimer(() => {
          this.pendingNavigation.delete(tabId);
          void this.recordNavigation(tabId);
        }, NAVIGATION_SETTLE_MS),
      );
    }
  }

  clearNavigation(tabId) {
    const timer = this.pendingNavigation.get(tabId);
    if (timer !== undefined) this.clearTimer(timer);
    this.pendingNavigation.delete(tabId);
  }

  async recordNavigation(tabId) {
    const tab = await this.chrome.tabs.get(tabId).catch(() => null);
    if (!tab || tab.incognito || !tab.url || this.knownUrls.get(tabId) === tab.url) return;
    this.knownUrls.set(tabId, tab.url);
    this.recordEvent('navigated', tab);
  }

  async onTabActivated(tabId) {
    this.scheduleSnapshot();
    const tab = await this.chrome.tabs.get(tabId).catch(() => null);
    if (!tab || tab.incognito || !tab.url) return;
    this.knownUrls.set(tabId, tab.url);
    this.recordEvent('activated', tab);
  }

  recordEvent(type, tab) {
    this.events.push({
      uuid: this.uuid(),
      type,
      url: tab.url,
      title: tab.title ?? null,
      occurred_at: this.now().toISOString(),
    });
    if (this.events.length > MAX_BUFFERED_EVENTS) {
      this.events.splice(0, this.events.length - MAX_BUFFERED_EVENTS);
    }
    this.scheduleFlush();
  }

  scheduleFlush() {
    if (this.flushTimer) return;
    this.flushTimer = this.setTimer(() => {
      this.flushTimer = null;
      this.flushEvents();
    }, EVENT_FLUSH_MS);
  }

  flushEvents() {
    while (this.events.length > 0 && this.port) {
      const batch = this.events.slice(0, EVENTS_PER_MESSAGE);
      if (!this.send('browser.activity', { browser: this.browser, events: batch })) return;
      this.events.splice(0, batch.length);
    }
  }

  // --- commands ---------------------------------------------------------

  async onHostMessage(message) {
    if (!message || message.version !== PROTOCOL_VERSION || typeof message.type !== 'string') return;

    if (message.type === 'browser.request_snapshot') {
      this.scheduleSnapshot(0);
      return;
    }

    if (!message.type.startsWith('browser.') || typeof message.id !== 'string') return;

    let outcome;
    try {
      outcome = await this.execute(message.type, message.payload ?? {});
    } catch {
      outcome = fail('COMMAND_ERROR');
    }
    this.send('response', outcome, message.id);
  }

  async execute(type, payload) {
    if (payload.browser !== undefined && payload.browser !== this.browser) return fail('WRONG_BROWSER');

    switch (type) {
      case 'browser.open_url':
        return this.openUrl(payload);
      case 'browser.navigate':
        return this.navigate(payload);
      case 'browser.close_tab':
        return this.closeTab(payload);
      default:
        return fail('UNSUPPORTED_COMMAND');
    }
  }

  async openUrl({ url }) {
    if (!isHttpUrl(url)) return fail('INVALID_URL');

    const windows = await this.chrome.windows.getAll();
    if (windows.length === 0) {
      const created = await this.chrome.windows.create({ url, focused: true });
      return done({ tab_id: created?.tabs?.[0]?.id ?? null });
    }

    const tab = await this.chrome.tabs.create({ url, active: true });
    if (tab.windowId !== undefined) await this.chrome.windows.update(tab.windowId, { focused: true }).catch(() => {});
    return done({ tab_id: tab.id });
  }

  async navigate({ tab_id: tabId, url }) {
    if (!isHttpUrl(url)) return fail('INVALID_URL');
    if (!Number.isInteger(tabId)) return fail('INVALID_TAB');
    const tab = await this.chrome.tabs.update(tabId, { url }).catch(() => null);
    return tab ? done({ tab_id: tabId }) : fail('TAB_NOT_FOUND');
  }

  async closeTab({ tab_id: tabId }) {
    if (!Number.isInteger(tabId)) return fail('INVALID_TAB');
    const existing = await this.chrome.tabs.get(tabId).catch(() => null);
    if (!existing) return fail('TAB_NOT_FOUND');
    await this.chrome.tabs.remove(tabId);
    return done({ tab_id: tabId });
  }
}

const done = (result) => ({ status: 'completed', result });
const fail = (error) => ({ status: 'failed', result: { error } });
