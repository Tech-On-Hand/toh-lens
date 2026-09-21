import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { Classroom, ClassroomPolicy } from "./types";

const DURATIONS = [5, 10, 15, 20, 30, 45, 60, 90];

/** Seconds left, measured against the server's clock so a wrong PC clock cannot mislead the teacher. */
function remaining(policy: ClassroomPolicy, fetchedAt: number, now: number): number {
  if (!policy.focus) return 0;
  const serverNow = Date.parse(policy.server_time) + (now - fetchedAt);
  return Math.max(0, Math.round((Date.parse(policy.focus.expires_at) - serverNow) / 1000));
}

function clock(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}

export default function PolicyPanel({ classroom, onClose, onChanged }: { classroom: Classroom; onClose: () => void; onChanged: () => void }) {
  const [policy, setPolicy] = useState<ClassroomPolicy | null>(null);
  const [fetchedAt, setFetchedAt] = useState(Date.now());
  const [now, setNow] = useState(Date.now());
  const [domains, setDomains] = useState("");
  const [minutes, setMinutes] = useState(20);
  const [name, setName] = useState("");
  const [blockAddress, setBlockAddress] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => api.getPolicy(classroom.id).then((next) => { setPolicy(next); setFetchedAt(Date.now()); }).catch((reason) => setNotice(String(reason))), [classroom.id]);

  useEffect(() => {
    void load();
    const poll = window.setInterval(() => void load(), 5000);
    const tick = window.setInterval(() => setNow(Date.now()), 1000);
    return () => { window.clearInterval(poll); window.clearInterval(tick); };
  }, [load]);

  async function act(work: () => Promise<unknown>, done: string) {
    setBusy(true);
    setNotice("");
    try {
      await work();
      setNotice(done);
      await load();
      onChanged();
    } catch (reason) {
      setNotice(String(reason));
    } finally {
      setBusy(false);
    }
  }

  const parsed = domains.split(/[\s,;]+/).filter(Boolean);
  const left = policy ? remaining(policy, fetchedAt, now) : 0;
  const focus = policy?.focus && left > 0 ? policy.focus : null;

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{classroom.name}</p>
            <h2>Focus and blocked sites</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}

        <h3>Focus session</h3>
        {focus ? (
          <div className="focus-card">
            <div>
              <strong>{focus.name || "Focus session"}</strong>
              <span className="countdown">{clock(left)} left</span>
            </div>
            <p className="muted">Students can only open: {focus.allowed_domains.join(", ")}</p>
            <button className="danger-solid" disabled={busy} onClick={() => void act(() => api.endFocus(classroom.id, focus.id), "Focus ended. Students can browse again.")}>End focus now</button>
          </div>
        ) : (
          <form className="stack" onSubmit={(event) => {
            event.preventDefault();
            void act(async () => { await api.startFocus(classroom.id, parsed, minutes, name.trim() || null); setDomains(""); }, "Focus started.");
          }}>
            <input value={name} onChange={(event) => setName(event.target.value)} placeholder="Name (optional), e.g. Fractions practice" />
            <textarea rows={4} value={domains} onChange={(event) => setDomains(event.target.value)} placeholder={"Sites students may use, separated by spaces or new lines\ne.g. khanacademy.org wikipedia.org"} />
            <div className="row">
              <label>Lasts <select value={minutes} onChange={(event) => setMinutes(Number(event.target.value))}>
                {DURATIONS.map((value) => <option key={value} value={value}>{value} minutes</option>)}
              </select></label>
              <button disabled={busy || parsed.length === 0}>Start focus</button>
            </div>
            <p className="muted">Every other site is blocked on this classroom's computers until the time is up or you end it. Sites blocked below stay blocked.</p>
          </form>
        )}

        <h3>Blocked sites</h3>
        <form className="open-form" onSubmit={(event) => {
          event.preventDefault();
          if (blockAddress.trim()) void act(async () => { await api.addBlockRule(classroom.id, blockAddress.trim()); setBlockAddress(""); }, "Site blocked.");
        }}>
          <input value={blockAddress} onChange={(event) => setBlockAddress(event.target.value)} placeholder="Site to block, e.g. youtube.com" />
          <button disabled={busy || !blockAddress.trim()}>Block</button>
        </form>
        <p className="muted">Blocking a site also blocks its subdomains.</p>
        {policy?.block_rules.length === 0 && <p className="muted">Nothing is blocked in this classroom.</p>}
        {policy?.block_rules.map((rule) => (
          <div key={rule.id} className="tab-row">
            <div className="tab-text">
              <strong>{rule.domain}</strong>
              <small>{rule.scope === "school" ? "Blocked for the whole school" : "This classroom"}</small>
            </div>
            {rule.scope === "classroom" && <button className="ghost" disabled={busy} onClick={() => void act(() => api.removeBlockRule(classroom.id, rule.id), "Site unblocked.")}>Unblock</button>}
          </div>
        ))}
      </aside>
    </div>
  );
}
