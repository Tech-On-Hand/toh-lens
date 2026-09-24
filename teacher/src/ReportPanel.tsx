import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { ActivityReport, Classroom } from "./types";

function isoDate(date: Date): string {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}

function daysAgo(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return isoDate(date);
}

function duration(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  return minutes % 60 === 0 ? `${hours} h` : `${hours} h ${minutes % 60} min`;
}

function clock(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "now";
}

const PRESETS = [
  { label: "Today", from: () => daysAgo(0), to: () => daysAgo(0) },
  { label: "Yesterday", from: () => daysAgo(1), to: () => daysAgo(1) },
  { label: "Last 7 days", from: () => daysAgo(6), to: () => daysAgo(0) },
];

export default function ReportPanel({ classroom, onClose }: { classroom: Classroom; onClose: () => void }) {
  const [from, setFrom] = useState(daysAgo(0));
  const [to, setTo] = useState(daysAgo(0));
  const [report, setReport] = useState<ActivityReport | null>(null);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setNotice("");
    api.getActivityReport(classroom.id, from, to)
      .then(setReport)
      .catch((reason) => setNotice(String(reason)))
      .finally(() => setLoading(false));
  }, [classroom.id, from, to]);

  useEffect(() => { load(); }, [load]);

  const multiDay = from !== to;

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel report" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{classroom.name}</p>
            <h2>Activity report</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}

        <div className="row report-range">
          {PRESETS.map((preset) => (
            <button key={preset.label} className={from === preset.from() && to === preset.to() ? "ghost active" : "ghost"} onClick={() => { setFrom(preset.from()); setTo(preset.to()); }}>{preset.label}</button>
          ))}
          <label>From <input type="date" value={from} max={to} onChange={(event) => event.target.value && setFrom(event.target.value)} /></label>
          <label>To <input type="date" value={to} min={from} max={daysAgo(0)} onChange={(event) => event.target.value && setTo(event.target.value)} /></label>
        </div>

        {loading && !report && <p className="muted">Loading…</p>}
        {report && report.sessions.length === 0 && <p className="muted">No students signed in on these dates.</p>}

        {report && report.classroom_apps.length > 0 && (
          <>
            <h3>Most used applications</h3>
            <p className="report-apps">{report.classroom_apps.map((app) => `${app.name} ${duration(app.minutes)}`).join(" · ")}</p>
          </>
        )}

        {report && report.sessions.length > 0 && (
          <table className="report-table">
            <thead>
              <tr><th>Student</th><th>Signed in</th><th>Active</th><th>Applications</th><th>Sites</th></tr>
            </thead>
            <tbody>
              {report.sessions.map((session) => (
                <tr key={session.session_uuid}>
                  <td>
                    <strong>{session.student?.name ?? "Unknown student"}</strong>
                    <small>{session.device ?? ""}</small>
                  </td>
                  <td>
                    {duration(session.signed_in_minutes)}
                    <small>{multiDay ? `${new Date(session.login_time).toLocaleDateString([], { month: "short", day: "numeric" })} · ` : ""}{clock(session.login_time)}–{clock(session.logout_time)}</small>
                  </td>
                  <td>
                    {duration(session.active_minutes)}
                    {session.idle_minutes > 0 && <small>{duration(session.idle_minutes)} idle</small>}
                  </td>
                  <td>
                    {session.apps.length === 0 ? <small>—</small> : session.apps.map((app) => <div key={app.process}>{app.name} <small>{duration(app.minutes)}</small></div>)}
                  </td>
                  <td>
                    {session.sites.length === 0 && session.blocked_attempts === 0 ? <small>—</small> : session.sites.map((site) => <div key={site.domain}>{site.domain} <small>×{site.visits}</small></div>)}
                    {session.blocked_attempts > 0 && <small className="blocked">{session.blocked_attempts} blocked attempt{session.blocked_attempts === 1 ? "" : "s"}</small>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <p className="muted">Applications are recorded by name only (never window titles). Time is counted only while a student is signed in; time with no keyboard or mouse input is shown as idle.</p>
      </aside>
    </div>
  );
}
