import { listen } from "@tauri-apps/api/event";
import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import AnnouncementPanel from "./AnnouncementPanel";
import ChatPanel from "./ChatPanel";
import BrowserPanel, { hostOf } from "./BrowserPanel";
import AuditPanel from "./AuditPanel";
import FleetPanel from "./FleetPanel";
import ReportPanel from "./ReportPanel";
import PolicyPanel from "./PolicyPanel";
import ScreenThumbnail from "./ScreenThumbnail";
import type { Classroom, Device, TeacherSession } from "./types";

function Login({ onLogin }: { onLogin: (session: TeacherSession) => void }) {
  const [apiBaseUrl, setApiBaseUrl] = useState("http://127.0.0.1:8000");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  return <main className="login-shell">
    <section className="login-panel">
      <div className="brand-mark">K</div>
      <p className="eyebrow">TECH ON HAND</p>
      <h1>TOH Klas</h1>
      <p className="muted">Your classroom, clear at a glance.</p>
      <form onSubmit={async event => {
        event.preventDefault(); setBusy(true); setError("");
        try { onLogin(await api.login(apiBaseUrl, email, password)); }
        catch (reason) { setError(String(reason)); }
        finally { setBusy(false); }
      }}>
        <label>Server<input type="url" value={apiBaseUrl} onChange={e => setApiBaseUrl(e.target.value)} required /></label>
        <label>Email<input type="email" value={email} onChange={e => setEmail(e.target.value)} required /></label>
        <label>Password<input type="password" value={password} onChange={e => setPassword(e.target.value)} required /></label>
        {error && <p className="error">{error}</p>}
        <button disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>
      </form>
    </section>
  </main>;
}

function ClassroomView({ session, onLogout }: { session: TeacherSession; onLogout: () => void }) {
  const [classrooms, setClassrooms] = useState<Classroom[]>([]);
  const [selected, setSelected] = useState<Classroom | null>(null);
  const [devices, setDevices] = useState<Device[]>([]);
  const [view, setView] = useState<"grid" | "list">("grid");
  const [connection, setConnection] = useState("Connecting");
  const [inspectingId, setInspectingId] = useState<number | null>(null);
  const [policyOpen, setPolicyOpen] = useState(false);
  const [announceOpen, setAnnounceOpen] = useState(false);
  const [reportOpen, setReportOpen] = useState(false);
  const [adminPanel, setAdminPanel] = useState<"audit" | "fleet" | null>(null);
  const [chatId, setChatId] = useState<number | null>(null);

  const refresh = useCallback(async (classroom = selected) => {
    if (classroom) setDevices(await api.listDevices(classroom.id));
  }, [selected]);

  useEffect(() => { api.listClassrooms().then(items => { setClassrooms(items); setSelected(items[0] ?? null); }); }, []);
  useEffect(() => {
    if (!selected) return;
    refresh(selected); api.startRealtime(selected.id).then(() => setConnection("Live")).catch(() => setConnection("Reconnecting"));
    const timer = window.setInterval(() => refresh(selected), 15000);
    return () => window.clearInterval(timer);
  }, [selected, refresh]);
  useEffect(() => {
    let dispose = () => {};
    let pending: number | undefined;
    // Browser activity can arrive many times a second, so coalesce refreshes.
    listen("classroom-event", () => { window.clearTimeout(pending); pending = window.setTimeout(() => refresh(), 500); }).then(fn => { dispose = fn; });
    return () => { dispose(); window.clearTimeout(pending); };
  }, [refresh]);

  const online = devices.filter(device => device.status === "online").length;
  const handsUp = devices.filter(device => device.help_request).length;
  return <div className="app-shell">
    <aside>
      <div className="brand"><div className="brand-mark small">K</div><strong>TOH Klas</strong></div>
      <p className="nav-label">CLASSROOMS</p>
      {classrooms.map(classroom => <button key={classroom.id} className={selected?.id === classroom.id ? "room active" : "room"} onClick={() => setSelected(classroom)}>
        <span>{classroom.name}</span><small>{classroom.school.name}</small>
      </button>)}
      <div className="profile"><span>{session.user.name}</span><small>{session.user.email}</small><button onClick={onLogout}>Sign out</button></div>
    </aside>
    <main className="classroom-main">
      <header><div><p className="eyebrow">{selected?.school.name ?? "YOUR SCHOOL"}</p><h1>{selected?.name ?? "No classroom assigned"}</h1></div>
        <div className="header-actions"><span className={`connection ${connection.toLowerCase()}`}>● {connection}</span><button onClick={() => setReportOpen(true)}>Reports</button>{session.is_administrator && <button onClick={() => setAdminPanel("fleet")}>Fleet</button>}{session.is_administrator && <button onClick={() => setAdminPanel("audit")}>Audit</button>}<button onClick={() => setAnnounceOpen(true)}>Announce</button><button onClick={() => setPolicyOpen(true)}>Focus &amp; blocked sites</button><button onClick={() => setView(view === "grid" ? "list" : "grid")}>{view === "grid" ? "List view" : "Grid view"}</button></div>
      </header>
      <section className="summary"><div><strong>{devices.length}</strong><span>Devices</span></div><div><strong>{online}</strong><span>Online now</span></div><div><strong>{devices.filter(d => d.active_session).length}</strong><span>Active students</span></div><div className={handsUp > 0 ? "needs-help" : undefined}><strong>{handsUp}</strong><span>Need help</span></div></section>
      <section className={view === "grid" ? "device-grid" : "device-list"}>
        {selected && devices.map(device => <article className={device.help_request ? "device-card hand-up" : "device-card"} key={device.device_uuid}>
          <div className="device-head"><strong>{device.name}</strong><span className={`status ${device.status}`}>● {device.status}</span></div>
          {device.help_request && <div className="help-banner">
            <div><strong>Needs help</strong>{device.help_request.message && <span>{device.help_request.message}</span>}</div>
            <button onClick={async () => { await api.resolveHelpRequest(selected.id, device.help_request!.id); await refresh(); }}>Done</button>
          </div>}
          <ScreenThumbnail classroomId={selected.id} device={device} />
          <h3>{device.active_session?.student?.full_name ?? "No active student"}</h3>
          <p>{device.hostname ?? "Hostname unavailable"} · Agent {device.agent_version ?? "—"}</p>
          {device.active_tab && <p className="active-tab" title={device.active_tab.url ?? ""}><strong>{hostOf(device.active_tab.url)}</strong> {device.active_tab.title}</p>}
          {device.active_session && device.status === "online" && <button className="ghost" onClick={() => setInspectingId(device.id)}>Browser</button>}
          {device.active_session && device.status === "online" && <button className={device.unread_messages > 0 ? "ghost unread" : "ghost"} onClick={() => setChatId(device.id)}>Chat{device.unread_messages > 0 ? ` (${device.unread_messages})` : ""}</button>}
          {session.is_administrator && <button className="danger" onClick={async () => { if (confirm(`Revoke ${device.name}?`)) { await api.revokeDevice(device.id); await refresh(); } }}>Revoke</button>}
        </article>)}
        {selected && devices.length === 0 && <div className="empty"><h2>No devices yet</h2><p>Use an enrollment code from the web administration area to add a student computer.</p></div>}
      </section>
      {selected && adminPanel === "fleet" && <FleetPanel classroom={selected} onClose={() => setAdminPanel(null)} />}
      {selected && adminPanel === "audit" && <AuditPanel classroom={selected} onClose={() => setAdminPanel(null)} />}
      {selected && reportOpen && <ReportPanel classroom={selected} onClose={() => setReportOpen(false)} />}
      {selected && announceOpen && <AnnouncementPanel classroom={selected} onClose={() => setAnnounceOpen(false)} />}
      {selected && policyOpen && <PolicyPanel classroom={selected} onClose={() => setPolicyOpen(false)} onChanged={() => refresh()} />}
      {selected && devices.find(d => d.id === chatId) && <ChatPanel classroom={selected} device={devices.find(d => d.id === chatId)!} onClose={() => setChatId(null)} onChanged={() => refresh()} />}
      {selected && devices.find(d => d.id === inspectingId) && <BrowserPanel classroom={selected} device={devices.find(d => d.id === inspectingId)!} onClose={() => setInspectingId(null)} />}
    </main>
  </div>;
}

export default function App() {
  const [session, setSession] = useState<TeacherSession | null | undefined>(undefined);
  useEffect(() => { api.restoreSession().then(setSession).catch(() => setSession(null)); }, []);
  if (session === undefined) return <main className="login-shell">Loading TOH Klas…</main>;
  if (!session) return <Login onLogin={setSession} />;
  return <ClassroomView session={session} onLogout={async () => { await api.logout(); setSession(null); }} />;
}
