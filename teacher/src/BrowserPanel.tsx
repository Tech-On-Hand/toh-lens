import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { BrowserTab, Classroom, CommandSummary, Device } from "./types";

const FAILURES: Record<string, string> = {
  EXTENSION_UNAVAILABLE: "The browser extension is not connected on that computer.",
  EXTENSION_TIMEOUT: "The browser did not respond.",
  SESSION_MISMATCH: "That student is no longer signed in.",
  SESSION_CHANGED: "The student signed out before it could run.",
  INVALID_URL: "That address is not allowed. Use an http:// or https:// address.",
  TAB_NOT_FOUND: "That tab was already closed.",
  WRONG_BROWSER: "That tab belongs to a different browser.",
  EXPIRED: "The student's computer did not respond in time.",
};

export function hostOf(url: string | null): string {
  try {
    return new URL(url ?? "").hostname;
  } catch {
    return url ?? "";
  }
}

const finished = (command: CommandSummary) => ["completed", "failed", "expired"].includes(command.status);

function describe(command: CommandSummary): string {
  if (command.status === "completed") return "Done.";
  const code = command.result?.error ?? (command.status === "expired" ? "EXPIRED" : "");
  return FAILURES[code] ?? "The command could not be completed.";
}

function withScheme(address: string): string {
  const trimmed = address.trim();
  return /^[a-z][a-z0-9+.-]*:/i.test(trimmed) ? trimmed : `https://${trimmed}`;
}

export default function BrowserPanel({ classroom, device, onClose }: { classroom: Classroom; device: Device; onClose: () => void }) {
  const [tabs, setTabs] = useState<BrowserTab[]>([]);
  const [address, setAddress] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const sessionId = device.active_session?.uuid ?? null;

  const load = useCallback(
    () => api.listBrowserTabs(classroom.id, device.id).then(setTabs).catch((reason) => setNotice(String(reason))),
    [classroom.id, device.id],
  );

  useEffect(() => {
    void load();
    const timer = window.setInterval(() => void load(), 4000);
    return () => window.clearInterval(timer);
  }, [load]);

  async function run(command: object) {
    if (!sessionId) {
      setNotice("No student is signed in on this computer.");
      return;
    }
    setBusy(true);
    setNotice("Sending…");
    try {
      let result = await api.sendCommand(classroom.id, device.id, { ...command, student_session_id: sessionId });
      for (let attempt = 0; attempt < 24 && !finished(result); attempt += 1) {
        await new Promise((resolve) => window.setTimeout(resolve, 750));
        result = await api.getCommand(classroom.id, device.id, result.id);
      }
      setNotice(finished(result) ? describe(result) : "Still waiting for the student's computer.");
    } catch (reason) {
      setNotice(String(reason));
    } finally {
      setBusy(false);
      void load();
    }
  }

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{device.name}</p>
            <h2>{device.active_session?.student?.full_name ?? "No student signed in"}</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>

        <form className="open-form" onSubmit={(event) => { event.preventDefault(); if (address.trim()) void run({ type: "browser.open_url", payload: { url: withScheme(address) } }); }}>
          <input value={address} onChange={(event) => setAddress(event.target.value)} placeholder="Address to open, e.g. khanacademy.org" />
          <button disabled={busy || !address.trim() || !sessionId}>Open page</button>
        </form>
        {notice && <p className="notice">{notice}</p>}

        <h3>Open tabs</h3>
        {tabs.length === 0 && <p className="muted">No browser tabs reported yet. The browser may be closed, or the extension is not connected.</p>}
        {tabs.map((tab) => (
          <div key={`${tab.browser}-${tab.tab_id}`} className={tab.is_active ? "tab-row viewing" : "tab-row"}>
            <div className="tab-text">
              <span className="tag">{tab.browser}</span>
              {tab.is_active && <span className="tag live">Viewing</span>}
              <strong>{tab.title || hostOf(tab.url) || "Untitled"}</strong>
              <small title={tab.url ?? ""}>{hostOf(tab.url)}</small>
            </div>
            <div className="tab-actions">
              <button className="ghost" disabled={busy || !address.trim() || !sessionId}
                onClick={() => void run({ type: "browser.navigate", payload: { browser: tab.browser, tab_id: tab.tab_id, url: withScheme(address) } })}>Go to address</button>
              <button className="ghost" disabled={busy || !sessionId}
                onClick={() => void run({ type: "browser.close_tab", payload: { browser: tab.browser, tab_id: tab.tab_id } })}>Close</button>
            </div>
          </div>
        ))}
      </aside>
    </div>
  );
}
