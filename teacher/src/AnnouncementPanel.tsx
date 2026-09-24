import { useCallback, useEffect, useState } from "react";
import * as api from "./api";
import type { Announcement, Classroom } from "./types";

const DURATIONS = [2, 5, 10, 20, 30, 60];
const MAX_LENGTH = 500;

/** How long ago, in words a teacher can take in at a glance. */
function ago(iso: string, now: number): string {
  const seconds = Math.max(0, Math.round((now - Date.parse(iso)) / 1000));
  if (seconds < 60) return "just now";
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min ago`;
  return `${Math.floor(minutes / 60)} h ago`;
}

export default function AnnouncementPanel({ classroom, onClose }: { classroom: Classroom; onClose: () => void }) {
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [message, setMessage] = useState("");
  const [minutes, setMinutes] = useState(10);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(Date.now());

  const load = useCallback(
    () => api.listAnnouncements(classroom.id).then(setAnnouncements).catch((reason) => setNotice(String(reason))),
    [classroom.id],
  );

  useEffect(() => {
    void load();
    const poll = window.setInterval(() => void load(), 4000);
    const tick = window.setInterval(() => setNow(Date.now()), 15000);
    return () => { window.clearInterval(poll); window.clearInterval(tick); };
  }, [load]);

  async function send() {
    setBusy(true);
    setNotice("");
    try {
      await api.sendAnnouncement(classroom.id, message.trim(), minutes);
      setMessage("");
      setNotice("Sent. It appears on every student computer within a few seconds.");
      await load();
    } catch (reason) {
      setNotice(String(reason));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{classroom.name}</p>
            <h2>Announcements</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}

        <form className="stack" onSubmit={(event) => { event.preventDefault(); if (message.trim()) void send(); }}>
          <textarea rows={4} maxLength={MAX_LENGTH} value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Message for every student in this classroom, e.g. Save your work, we're moving on in 2 minutes." />
          <div className="row">
            <label>Stays up for <select value={minutes} onChange={(event) => setMinutes(Number(event.target.value))}>
              {DURATIONS.map((value) => <option key={value} value={value}>{value} minutes</option>)}
            </select></label>
            <button disabled={busy || !message.trim()}>Send to class</button>
          </div>
          <p className="muted">Students see it full-screen until they tap OK. A computer that is offline gets it when it reconnects, if it hasn't expired. {message.length}/{MAX_LENGTH}</p>
        </form>

        <h3>Recent</h3>
        {announcements.length === 0 && <p className="muted">Nothing sent yet.</p>}
        {announcements.map((announcement) => (
          <div key={announcement.id} className="tab-row">
            <div className="tab-text">
              <strong>{announcement.message}</strong>
              <small>
                {ago(announcement.sent_at, now)} · read by {announcement.read} of {announcement.total_devices}
                {announcement.delivered > announcement.read ? ` · ${announcement.delivered - announcement.read} on screen, not yet dismissed` : ""}
              </small>
            </div>
          </div>
        ))}
      </aside>
    </div>
  );
}
