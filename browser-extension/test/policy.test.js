import assert from 'node:assert/strict';
import { test } from 'node:test';
import { blockedPageUrl, buildRules, evaluate, hostMatches, isDomain, parseBlockedUrl, parsePolicy } from '../src/policy.js';

const PAGE = 'chrome-extension://klasid/blocked.html';
const NOW = Date.parse('2026-09-23T09:00:00Z');
const focusUntil = (offsetMs, allowed = ['khan.example']) => ({
  id: 'f1', name: null, allowed_domains: allowed, ends_at_ms: NOW + offsetMs,
});
const policy = ({ block = [], focus = null } = {}) => ({ version: 'v', block, focus });

test('domain rules cover subdomains but not look-alikes', () => {
  assert.ok(hostMatches('example.com', 'example.com'));
  assert.ok(hostMatches('a.b.example.com', 'example.com'));
  assert.ok(hostMatches('EXAMPLE.com', 'example.com'));
  assert.ok(!hostMatches('notexample.com', 'example.com'));
  assert.ok(!hostMatches('example.com.evil.net', 'example.com'));
});

test('isDomain accepts hostnames and rejects everything else', () => {
  for (const good of ['example.com', 'a.b.example.org', 'xn--e1afmkfd.xn--p1ai']) assert.ok(isDomain(good), good);
  for (const bad of ['localhost', '1.2.3.4', 'a..b.com', '-x.com', 'example.c', 'exa mple.com', '', null, 5, 'a'.repeat(260) + '.com']) {
    assert.ok(!isDomain(bad), String(bad));
  }
});

test('parsePolicy keeps valid data, drops junk, and refuses malformed policies whole', () => {
  const good = parsePolicy({
    version: 'abc',
    block: ['games.example', 'games.example', 'not a domain', 'localhost'],
    focus: { id: 'f', name: 'Maths', allowed_domains: ['khan.example', 'bad'], ends_at: '2026-09-23T10:00:00Z' },
  });
  assert.deepEqual(good.block, ['games.example']);
  assert.deepEqual(good.focus, { id: 'f', name: 'Maths', allowed_domains: ['khan.example'], ends_at_ms: Date.parse('2026-09-23T10:00:00Z') });

  assert.equal(parsePolicy({ version: 'abc', block: [], focus: null }).focus, null);
  assert.equal(parsePolicy(null), null);
  assert.equal(parsePolicy({ block: [] }), null);
  assert.equal(parsePolicy({ version: 'v' }), null);
  assert.equal(parsePolicy({ version: 'v', block: [], focus: { id: 'f', allowed_domains: [], ends_at: '2026-09-23T10:00:00Z' } }), null,
    'an empty allow-list would silently block everything');
  assert.equal(parsePolicy({ version: 'v', block: [], focus: { id: 'f', allowed_domains: ['a.example'], ends_at: 'soon' } }), null);
});

test('blocked sites are blocked, including subdomains, and other sites are not', () => {
  const p = policy({ block: ['games.example'] });
  assert.deepEqual(evaluate('https://games.example/play', p, NOW), { blocked: true, reason: 'blocked' });
  assert.deepEqual(evaluate('http://play.games.example/x?y=1', p, NOW), { blocked: true, reason: 'blocked' });
  assert.deepEqual(evaluate('https://notgames.example/', p, NOW), { blocked: false });
});

test('during focus only allowed sites load, and the session simply ends at its deadline', () => {
  const p = policy({ focus: focusUntil(60_000) });
  assert.deepEqual(evaluate('https://khan.example/lesson', p, NOW), { blocked: false });
  assert.deepEqual(evaluate('https://cdn.khan.example/', p, NOW), { blocked: false });
  assert.deepEqual(evaluate('https://youtube.com/', p, NOW), { blocked: true, reason: 'focus' });
  assert.deepEqual(evaluate('https://youtube.com/', p, NOW + 60_000), { blocked: false }, 'the deadline itself is already over');
  assert.deepEqual(evaluate('https://youtube.com/', p, NOW + 3_600_000), { blocked: false });
});

test('a blocked site stays blocked even when the focus session allows it', () => {
  const p = policy({ block: ['khan.example'], focus: focusUntil(60_000, ['khan.example']) });
  assert.deepEqual(evaluate('https://khan.example/', p, NOW), { blocked: true, reason: 'blocked' });
});

test('browser, extension, file and unparseable addresses are never policed', () => {
  const p = policy({ block: ['games.example'], focus: focusUntil(60_000) });
  for (const url of ['chrome://settings', 'about:blank', 'edge://newtab', `${PAGE}?reason=focus&u=https://x.example`, 'file:///c:/a.txt', 'not a url', '']) {
    assert.deepEqual(evaluate(url, p, NOW), { blocked: false }, url);
  }
  assert.deepEqual(evaluate('https://x.example', null, NOW), { blocked: false });
});

test('the blocked-page address round-trips awkward original URLs', () => {
  for (const original of ['https://a.example/', 'https://a.example/p?x=1&u=2&reason=focus', 'http://a.example:8080/a b?c=d#frag']) {
    const url = blockedPageUrl(PAGE, 'focus', original);
    assert.deepEqual(parseBlockedUrl(PAGE, url), { reason: 'focus', url: original });
  }
  assert.equal(parseBlockedUrl(PAGE, `${PAGE}?reason=focus&u=javascript:alert(1)`), null);
  assert.equal(parseBlockedUrl(PAGE, `${PAGE}?reason=focus&u=file:///c:/x`), null);
  assert.equal(parseBlockedUrl(PAGE, `${PAGE}?reason=focus`), null);
  assert.equal(parseBlockedUrl(PAGE, 'https://evil.example/?reason=focus&u=https://a.example'), null);
  assert.equal(parseBlockedUrl(PAGE, 'chrome-extension://otherid/blocked.html?reason=focus&u=https://a.example'), null);
});

test('rules: nothing to enforce means no rules', () => {
  assert.deepEqual(buildRules(policy(), NOW, PAGE), []);
  assert.deepEqual(buildRules(policy({ focus: focusUntil(-1) }), NOW, PAGE), [], 'an expired focus session adds no rules');
});

test('rules: priorities make blocked beat allowed beat the catch-all, for top-level navigations only', () => {
  const rules = buildRules(policy({ block: ['games.example'], focus: focusUntil(60_000, ['khan.example', 'wiki.example']) }), NOW, PAGE);
  const byId = Object.fromEntries(rules.map((rule) => [rule.id, rule]));

  assert.ok(byId[1].priority > byId[2].priority && byId[2].priority > byId[3].priority);
  assert.equal(byId[1].action.type, 'redirect');
  assert.deepEqual(byId[1].condition.requestDomains, ['games.example']);
  assert.equal(byId[2].action.type, 'allow');
  assert.deepEqual(byId[2].condition.requestDomains, ['khan.example', 'wiki.example']);
  assert.equal(byId[3].action.type, 'redirect');
  assert.equal(byId[3].condition.requestDomains, undefined, 'the catch-all applies to every site');
  for (const rule of rules) assert.deepEqual(rule.condition.resourceTypes, ['main_frame']);
  assert.equal(byId[1].action.redirect.regexSubstitution, `${PAGE}?reason=blocked&u=\\1`);
  assert.equal(byId[3].action.redirect.regexSubstitution, `${PAGE}?reason=focus&u=\\1`);
});
