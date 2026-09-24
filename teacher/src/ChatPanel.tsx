import { useCallback, useEffect, useRef, useState } from "react";
import * as api from "./api";
import type { ChatThread, Classroom, Device } from "./types";

/**
 * A conversation with whoever is signed in on one device. Opening it is what marks
 * the student's messages read (the server does that on fetch), so it only polls
 * while it is on screen.
 */
export default function ChatPanel({ classroom, device, onClose, onChanged }: { classroom: Classroom; device: Device; onClose: () => void; onChanged: () => void }) {
  const [thread, setThread] = useState<ChatThread | null>(null);
  const [draft, setDraft] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);

  const load = useCallback(
    () => api.listChatMessages(classroom.id, device.id).then((next) => { setThread(next); onChanged(); }).catch((reason) => setNotice(String(reason))),
    [classroom.id, device.id, onChanged],
  );

  useEffect(() => {
    void load();
    const poll = window.setInterval(() => void load(), 3000);
    return () => window.clearInterval(poll);
  }, [load]);

  useEffect(() => { endRef.current?.scrollIntoView({ block: "end" }); }, [thread?.messages.length]);

  async function send() {
    const body = draft.trim();
    if (!body) return;
    setBusy(true);
    setNotice("");
    try {
      await api.sendChatMessage(classroom.id, device.id, crypto.randomUUID(), body);
      setDraft("");
      await load();
    } catch (reason) {
      setNotice(String(reason));
    } finally {
      setBusy(false);
    }
  }

  const student = thread?.session?.student_name;
  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel chat" onClick={(event) => event.stopPropagation()}>
        <header>
          <div>
            <p className="eyebrow">{device.name}</p>
            <h2>{student ? `Chat with ${student}` : "Chat"}</h2>
          </div>
          <button className="ghost" onClick={onClose}>Close</button>
        </header>
        {notice && <p className="notice">{notice}</p>}
        {thread && !thread.session && <p className="muted">Nobody is signed in on this computer, so there is no one to chat with. A conversation belongs to one student's session; the next student starts with a clean slate.</p>}
        <div className="chat-thread">
          {thread?.session && thread.messages.length === 0 && <p className="muted">No messages yet.</p>}
          {thread?.messages.map((message) => (
            <div key={message.uuid} className={`chat-line ${message.direction === "to_student" ? "mine" : "theirs"}`}>
              <div className="chat-text">{message.body}</div>
              <small>
                {message.direction === "to_student" ? `${message.sender_name ?? "You"} · ` : ""}
                {new Date(message.sent_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
                {message.direction === "to_student" ? (message.read_at ? " · read" : message.delivered_at ? " · delivered" : " · sent") : ""}
              </small>
            </div>
          ))}
          <div ref={endRef} />
        </div>
        {thread?.session && (
          <form className="open-form" onSubmit={(event) => { event.preventDefault(); void send(); }}>
            <input value={draft} maxLength={500} onChange={(event) => setDraft(event.target.value)} placeholder="Write a message to this student" autoFocus />
            <button disabled={busy || !draft.trim()}>Send</button>
          </form>
        )}
      </aside>
    </div>
  );
}
