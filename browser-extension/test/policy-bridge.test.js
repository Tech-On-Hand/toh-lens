import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EXTENSION_URL, harness, settle, tab } from './harness.js';

const PAGE = `${EXTENSION_URL}blocked.html`;
const parked = (reason, original) => `${PAGE}?reason=${reason}&u=${original}`;

const pushPolicy = async (ports, payload) => {
  ports.at(-1).onMessage.fire({ version: 1, id: 'p1', type: 'policy.update', occurred_at: '2026-09-22T08:00:00Z', payload });
  await settle();
};
const inMinutes = (state, minutes) => new Date(state.clock + minutes * 60_000).toISOString();

test('a block rule installs browser rules, persists, and moves an open tab off the site', async () => {
  const { bridge, ports, stored, dnr, state } = harness({
    tabs: [tab(1, 'https://games.example/play'), tab(2, 'https://khan.example/'), tab(3, 'chrome://settings'), tab(4, 'https://games.example/private', { incognito: true })],
  });
  bridge.start();
  await pushPolicy(ports, { version: 'v1', block: ['games.example'], focus: null });

  assert.equal(dnr.rules.length, 1);
  assert.deepEqual(dnr.rules[0].condition.requestDomains, ['games.example']);
  assert.equal(stored.policy.version, 'v1');
  assert.equal(state.tabs[0].url, parked('blocked', 'https://games.example/play'));
  assert.equal(state.tabs[1].url, 'https://khan.example/');
  assert.equal(state.tabs[2].url, 'chrome://settings');
  assert.equal(state.tabs[3].url, 'https://games.example/private', 'private windows are never touched');
});

test('a focus session blocks everything else, and lifting it restores the tabs to where they were', async () => {
  const { bridge, ports, dnr, state } = harness({ tabs: [tab(1, 'https://youtube.com/watch?v=1&t=2'), tab(2, 'https://khan.example/lesson')] });
  bridge.start();

  await pushPolicy(ports, { version: 'v1', block: [], focus: { id: 'f', allowed_domains: ['khan.example'], ends_at: inMinutes(state, 20) } });
  assert.deepEqual(dnr.rules.map((rule) => rule.id).sort(), [2, 3]);
  assert.equal(state.tabs[0].url, parked('focus', 'https://youtube.com/watch?v=1&t=2'));
  assert.equal(state.tabs[1].url, 'https://khan.example/lesson');

  await pushPolicy(ports, { version: 'v2', block: [], focus: null });
  assert.equal(dnr.rules.length, 0);
  assert.equal(state.tabs[0].url, 'https://youtube.com/watch?v=1&t=2', 'the student is returned to the page they left');
});

test('focus ends by itself at its deadline, on the local clock, with no agent involved', async () => {
  const { bridge, ports, dnr, state, timers } = harness({ tabs: [tab(1, 'https://youtube.com/')] });
  bridge.start();
  await pushPolicy(ports, { version: 'v1', block: [], focus: { id: 'f', allowed_domains: ['khan.example'], ends_at: inMinutes(state, 5) } });
  assert.equal(state.tabs[0].url, parked('focus', 'https://youtube.com/'));

  const expiry = timers.get(bridge.expiryTimer);
  assert.ok(expiry, 'an expiry timer is scheduled');
  assert.ok(Math.abs(expiry.ms - (5 * 60_000 + 50)) < 5, `timer fires just after the deadline, not ${expiry.ms}ms`);

  state.clock += 5 * 60_000 + 1000;
  expiry.fn();
  await settle();

  assert.equal(dnr.rules.length, 0);
  assert.equal(state.tabs[0].url, 'https://youtube.com/');
});

test('the keep-alive alarm also ends an expired session, covering a suspended service worker', async () => {
  const { bridge, ports, dnr, state, alarmEvent } = harness({ tabs: [tab(1, 'https://youtube.com/')] });
  bridge.start();
  await pushPolicy(ports, { version: 'v1', block: [], focus: { id: 'f', allowed_domains: ['khan.example'], ends_at: inMinutes(state, 5) } });

  state.clock += 6 * 60_000;
  alarmEvent.fire({ name: 'toh-klas-keepalive' });
  await settle();

  assert.equal(dnr.rules.length, 0);
  assert.equal(state.tabs[0].url, 'https://youtube.com/');
});

test('a block rule outlives the end of focus, and stays enforced afterwards', async () => {
  const { bridge, ports, dnr, state } = harness({ tabs: [tab(1, 'https://games.example/')] });
  bridge.start();

  await pushPolicy(ports, { version: 'v1', block: ['games.example'], focus: { id: 'f', allowed_domains: ['khan.example'], ends_at: inMinutes(state, 5) } });
  await pushPolicy(ports, { version: 'v2', block: ['games.example'], focus: null });

  assert.deepEqual(dnr.rules.map((rule) => rule.id), [1]);
  assert.equal(state.tabs[0].url, parked('blocked', 'https://games.example/'));
});

test('malformed policy updates change nothing', async () => {
  const { bridge, ports, dnr, stored } = harness();
  bridge.start();
  await pushPolicy(ports, { version: 'v1', block: ['games.example'], focus: null });
  const before = structuredClone(dnr.rules);

  await pushPolicy(ports, null);
  await pushPolicy(ports, { block: [] });
  await pushPolicy(ports, { version: 'bad', block: [], focus: { id: 'f', allowed_domains: [], ends_at: '2099-01-01T00:00:00Z' } });
  await pushPolicy(ports, { version: 'bad', block: 'games.example', focus: null });

  assert.deepEqual(dnr.rules, before);
  assert.equal(stored.policy.version, 'v1');
});

test('the stored policy is enforced at startup before the agent says anything', async () => {
  const { bridge, dnr, stored, state } = harness({ tabs: [tab(1, 'https://games.example/')] });
  stored.policy = { version: 'old', block: ['games.example'], focus: null };

  bridge.start();
  await settle();

  assert.equal(dnr.rules.length, 1);
  assert.equal(state.tabs[0].url, parked('blocked', 'https://games.example/'));
});

test('a stored focus session that already ran out is not enforced after a restart', async () => {
  const { bridge, dnr, stored } = harness();
  stored.policy = { version: 'old', block: [], focus: { id: 'f', name: null, allowed_domains: ['khan.example'], ends_at_ms: Date.parse('2026-09-22T07:00:00Z') } };

  bridge.start();
  await settle();

  assert.equal(dnr.rules.length, 0);
});

test('snapshots report a blocked tab as the address it tried to reach', async () => {
  const { bridge, ports, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://games.example/play', { active: true })] });
  bridge.start();
  await pushPolicy(ports, { version: 'v1', block: ['games.example'], focus: null });
  await runTimers();

  const last = sentOfType('browser.snapshot').at(-1);
  assert.equal(last.payload.tabs[0].url, 'https://games.example/play');
});

test('landing on the blocked page is logged as a blocked attempt, not a navigation', async () => {
  const { bridge, state, events, runTimers, sentOfType } = harness({ tabs: [tab(1, 'https://khan.example/')] });
  bridge.start();
  await runTimers();

  state.tabs = [tab(1, parked('focus', 'https://youtube.com/watch?v=9'), { title: 'Page blocked' })];
  events.onUpdated.fire(1, { status: 'complete' });
  await runTimers();

  const logged = sentOfType('browser.activity').flatMap((m) => m.payload.events);
  assert.deepEqual(logged.map((e) => [e.type, e.url, e.title]), [['blocked', 'https://youtube.com/watch?v=9', 'Blocked (focus)']]);
});
