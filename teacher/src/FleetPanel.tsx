import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { Classroom, Fleet } from "./types";

function lastSeen(iso: string | null): string {
  if (!iso) return "Never";
  const minutes = Math.round((Date.now() - Date.parse(iso)) / 60_000);
  if (minutes < 2) return "Just now";
  if (minutes < 60) return `${minutes} min ago`;
  if (minutes < 60 * 48) return `${Math.round(minutes / 60)} h ago`;
  return `${Math.round(minutes / 1440)} days ago`;
}

export default function FleetPanel({ classroom, onClose }: { classroom: Classroom; onClose: () => void }) {
  const [fleet, setFleet] = useState<Fleet | null>(null);
  const [notice, setNotice] = useState("");

  const load = useCallback(
    () => api.getFleet(classroom.school.id).then(setFleet).catch((reason) => setNotice(String(reason))),
    [classroom.school.id],
  );

  useEffect(() => {
    void load();
    const poll = window.setInterval(() => void load(), 15000);
    return () => window.clearInterval(poll);
  }, [load]);

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel report" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{classroom.school.name}</p>
            <h2>Fleet health</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}

        {fleet && (
          <>
            <section className="summary">
              <div><strong>{fleet.summary.devices}</strong><span>Devices</span></div>
              <div><strong>{fleet.summary.online}</strong><span>Online now</span></div>
              <div className={fleet.summary.needing_attention > 0 ? "needs-help" : undefined}><strong>{fleet.summary.needing_attention}</strong><span>Need attention</span></div>
            </section>
            {fleet.devices.length === 0 && <p className="muted">No devices are enrolled in this school yet.</p>}
            {fleet.devices.length > 0 && (
              <table className="report-table">
                <thead><tr><th>Device</th><th>Status</th><th>Agent</th><th>Needs attention</th></tr></thead>
                <tbody>
                  {fleet.devices.map((device) => (
                    <tr key={device.id}>
                      <td><strong>{device.name}</strong><small>{device.classroom ?? "No classroom"}{device.hostname ? ` · ${device.hostname}` : ""}</small></td>
                      <td><span className={`status ${device.status}`}>● {device.status}</span><small>{lastSeen(device.last_seen_at)}</small></td>
                      <td>{device.agent_version ?? "—"}<small>{device.operating_system ?? ""}</small></td>
                      <td>{device.issues.length === 0 ? <small>Nothing</small> : device.issues.map((issue) => <div key={issue.code} className="blocked">{issue.message}</div>)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            <p className="muted">Offline is only flagged after a day, so computers switched off overnight or at weekends do not show up here.{fleet.summary.newest_agent_version ? ` Newest agent in this school: ${fleet.summary.newest_agent_version}.` : ""}</p>
          </>
        )}
      </aside>
    </div>
  );
}
