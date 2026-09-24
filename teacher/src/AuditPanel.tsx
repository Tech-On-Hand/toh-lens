import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { AuditEntry, Classroom } from "./types";

const LABELS: Record<string, string> = {
  "focus.started": "Started a focus session",
  "focus.ended": "Ended a focus session",
  "focus.expired": "Focus session ran out",
  "block_rule.added": "Blocked a site",
  "block_rule.removed": "Unblocked a site",
  "browser.command_issued": "Sent a browser command",
  "device.updated": "Changed a device",
  "device.revoked": "Revoked a device",
  "screen.started": "Started watching a screen",
  "screen.ended": "Stopped watching a screen",
  "screen.quality_changed": "Changed screen quality",
  "screen.broadcast_started": "Started a screen broadcast",
  "screen.broadcast_ended": "Ended a screen broadcast",
  "announcement.sent": "Sent an announcement",
  "help.requested": "Student asked for help",
  "help.resolved": "Marked a help request done",
};

const PAGE = 50;

/** The one detail worth showing inline for the actions that carry one. */
function detail(entry: AuditEntry): string {
  const data = entry.metadata ?? {};
  if (typeof data.domain === "string") return data.domain;
  if (Array.isArray(data.allowed_domains)) return `allowed: ${data.allowed_domains.join(", ")}`;
  if (typeof data.duration_minutes === "number") return `${data.duration_minutes} min`;
  if (typeof data.quality === "string") return String(data.quality);
  const payload = data.payload as { url?: unknown } | undefined;
  if (payload && typeof payload.url === "string") return payload.url;
  return "";
}

export default function AuditPanel({ classroom, onClose }: { classroom: Classroom; onClose: () => void }) {
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [action, setAction] = useState("");
  const [scope, setScope] = useState<"school" | "classroom">("school");
  const [more, setMore] = useState(false);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);

  const load = useCallback(async (beforeId?: number) => {
    setLoading(true);
    setNotice("");
    try {
      const page = await api.listAudit(classroom.school.id, scope === "classroom" ? classroom.id : null, action || null, beforeId ?? null, PAGE);
      setEntries((previous) => (beforeId ? [...previous, ...page] : page));
      setMore(page.length === PAGE);
    } catch (reason) {
      setNotice(String(reason));
    } finally {
      setLoading(false);
    }
  }, [classroom.school.id, classroom.id, scope, action]);

  useEffect(() => { void load(); }, [load]);

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel report" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{classroom.school.name}</p>
            <h2>Audit history</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}

        <div className="row report-range">
          <label>Show <select value={scope} onChange={(event) => setScope(event.target.value as "school" | "classroom")}>
            <option value="school">Whole school</option>
            <option value="classroom">{classroom.name} only</option>
          </select></label>
          <label>Action <select value={action} onChange={(event) => setAction(event.target.value)}>
            <option value="">All actions</option>
            {Object.entries(LABELS).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
          </select></label>
        </div>

        {!loading && entries.length === 0 && !notice && <p className="muted">Nothing recorded yet.</p>}
        {entries.length > 0 && (
          <table className="report-table">
            <thead><tr><th>When</th><th>What</th><th>Who</th><th>Where</th></tr></thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.id}>
                  <td>{new Date(entry.created_at).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })}</td>
                  <td>
                    {LABELS[entry.action] ?? entry.action}
                    {detail(entry) && <small>{detail(entry)}</small>}
                  </td>
                  <td>{entry.actor?.name ?? <small>System or student</small>}</td>
                  <td>
                    {entry.device_name ?? entry.classroom_name ?? <small>—</small>}
                    {entry.device_name && entry.classroom_name && <small>{entry.classroom_name}</small>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {more && <button className="ghost" disabled={loading} onClick={() => void load(entries[entries.length - 1]?.id)}>{loading ? "Loading…" : "Load older"}</button>}
        <p className="muted">Entries are append-only and cannot be edited or deleted from here.</p>
      </aside>
    </div>
  );
}
