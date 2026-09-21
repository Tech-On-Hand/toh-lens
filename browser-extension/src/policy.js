// Classroom browsing policy: pure functions, no browser APIs, so the rules that
// decide what is blocked can be tested exhaustively under Node.
//
// A policy is { version, block: [domain], focus: { id, name, allowed_domains, ends_at_ms } | null }.
// Blocked sites always win. A focus session, while active, allows only its own domains.

const DOMAIN = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/;
const MAX_BLOCKED = 500;
const MAX_ALLOWED = 100;

export const RULE_IDS = [1, 2, 3];

export function isDomain(value) {
  return typeof value === 'string' && value.length <= 253 && DOMAIN.test(value);
}

/** A rule covers the domain itself and every subdomain. */
export function hostMatches(host, domain) {
  const lower = host.toLowerCase();
  return lower === domain || lower.endsWith(`.${domain}`);
}

/** Validates a policy received from the agent; anything malformed is refused whole. */
export function parsePolicy(raw) {
  if (!raw || typeof raw !== 'object' || typeof raw.version !== 'string') return null;
  if (!Array.isArray(raw.block)) return null;

  const block = [...new Set(raw.block.filter(isDomain))].slice(0, MAX_BLOCKED);
  let focus = null;

  if (raw.focus !== null && raw.focus !== undefined) {
    const allowed = Array.isArray(raw.focus.allowed_domains) ? [...new Set(raw.focus.allowed_domains.filter(isDomain))].slice(0, MAX_ALLOWED) : [];
    const endsAt = Date.parse(raw.focus.ends_at);
    // A focus session with nothing allowed would silently block everything, so refuse it outright.
    if (allowed.length === 0 || Number.isNaN(endsAt)) return null;
    focus = { id: String(raw.focus.id ?? ''), name: raw.focus.name ? String(raw.focus.name).slice(0, 100) : null, allowed_domains: allowed, ends_at_ms: endsAt };
  }

  return { version: raw.version.slice(0, 80), block, focus };
}

export function activeFocus(policy, nowMs) {
  return policy?.focus && policy.focus.ends_at_ms > nowMs ? policy.focus : null;
}

/** Decides one URL. Only web pages are policed; browser and extension pages never are. */
export function evaluate(url, policy, nowMs) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return { blocked: false };
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return { blocked: false };

  const host = parsed.hostname;
  if (policy?.block.some((domain) => hostMatches(host, domain))) return { blocked: true, reason: 'blocked' };

  const focus = activeFocus(policy, nowMs);
  if (focus && !focus.allowed_domains.some((domain) => hostMatches(host, domain))) return { blocked: true, reason: 'focus' };

  return { blocked: false };
}

// --- the blocked page ----------------------------------------------------------

/** The original address goes last so it can contain "&" and "?" without ambiguity. */
export function blockedPageUrl(pageBase, reason, original) {
  return `${pageBase}?reason=${reason}&u=${original}`;
}

export function isBlockedPage(pageBase, url) {
  return typeof url === 'string' && (url === pageBase || url.startsWith(`${pageBase}?`));
}

/** Recovers { reason, url } from a blocked-page address, or null if it does not carry a web address. */
export function parseBlockedUrl(pageBase, url) {
  if (!isBlockedPage(pageBase, url)) return null;
  const marker = url.indexOf('&u=');
  if (marker === -1) return null;

  const original = url.slice(marker + 3);
  try {
    const { protocol } = new URL(original);
    if (protocol !== 'http:' && protocol !== 'https:') return null;
  } catch {
    return null;
  }

  const reason = /[?&]reason=(blocked|focus)(?:&|$)/.exec(url.slice(0, marker + 1))?.[1] ?? 'blocked';
  return { reason, url: original };
}

// --- browser rules --------------------------------------------------------------

/**
 * declarativeNetRequest rules for top-level navigations only, so a permitted
 * site's images, scripts and embedded frames keep loading. Priorities make
 * blocked sites beat the focus allow-list, which beats the catch-all block.
 */
export function buildRules(policy, nowMs, pageBase) {
  const redirect = (reason) => ({
    type: 'redirect',
    redirect: { regexSubstitution: `${pageBase}?reason=${reason}&u=\\1` },
  });
  const mainFrame = ['main_frame'];
  const rules = [];

  if (policy.block.length > 0) {
    rules.push({
      id: 1,
      priority: 3,
      action: redirect('blocked'),
      condition: { regexFilter: '^(https?://.*)$', requestDomains: policy.block, resourceTypes: mainFrame },
    });
  }

  const focus = activeFocus(policy, nowMs);
  if (focus) {
    rules.push({
      id: 2,
      priority: 2,
      action: { type: 'allow' },
      condition: { requestDomains: focus.allowed_domains, resourceTypes: mainFrame },
    });
    rules.push({
      id: 3,
      priority: 1,
      action: redirect('focus'),
      condition: { regexFilter: '^(https?://.*)$', resourceTypes: mainFrame },
    });
  }

  return rules;
}
