import { useEffect, useRef, useState } from "react";
import type { ChatMessage } from "../types";
import type { OutgoingMessage } from "../lib/useChat";

interface ChatPanelProps {
  messages: ChatMessage[];
  pending: OutgoingMessage[];
  onSend: (body: string) => Promise<void>;
  onClose: () => void;
}

export function ChatPanel({ messages, pending, onSend, onClose }: ChatPanelProps) {
  const [draft, setDraft] = useState("");
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: "end" });
  }, [messages.length, pending.length]);

  const submit = async () => {
    const body = draft.trim();
    if (!body) return;
    setDraft("");
    await onSend(body);
  };

  const unsent = pending.filter((message) => !messages.some((sent) => sent.uuid === message.uuid));

  return (
    <div className="chat-panel">
      <header className="chat-header">
        <strong>Chat with your teacher</strong>
        <button type="button" onClick={onClose}>
          Close
        </button>
      </header>
      <div className="chat-messages">
        {messages.length === 0 && unsent.length === 0 && <p className="chat-empty">No messages yet. Type below to write to your teacher.</p>}
        {messages.map((message) => (
          <div key={message.uuid} className={`chat-bubble chat-bubble--${message.direction === "to_teacher" ? "mine" : "theirs"}`}>
            {message.direction === "to_student" && message.sender_name && <small>{message.sender_name}</small>}
            {message.body}
          </div>
        ))}
        {unsent.map((message) => (
          <div key={message.uuid} className="chat-bubble chat-bubble--mine chat-bubble--unsent">
            {message.body}
            <small>Not sent yet, retrying…</small>
          </div>
        ))}
        <div ref={endRef} />
      </div>
      <form
        className="chat-form"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <input value={draft} maxLength={500} onChange={(event) => setDraft(event.target.value)} placeholder="Write a message" autoFocus />
        <button type="submit" disabled={!draft.trim()}>
          Send
        </button>
      </form>
    </div>
  );
}
