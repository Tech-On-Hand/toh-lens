import { useCallback, useEffect, useRef, useState } from "react";
import { getChatMessages, markChatRead, sendChatMessage } from "./commands";
import type { ChatMessage } from "../types";

const POLL_MS = 3_000;
// Long enough for the background delivery started by a send to land, so a message
// that got through moves out of "not sent yet" without waiting for the next poll.
const AFTER_SEND_MS = 800;

export interface OutgoingMessage {
  uuid: string;
  body: string;
}

/**
 * The student's conversation with their teacher. Polls (the device holds no
 * socket). What the student types is queued on disk by the kiosk and delivered
 * whenever the server can be reached (see outbox.rs), so it survives an outage or
 * a restart; `pending` is whatever is still waiting to be delivered.
 */
export function useChat(open: boolean) {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [pending, setPending] = useState<OutgoingMessage[]>([]);
  const [unread, setUnread] = useState(0);
  const sessionRef = useRef<string | null>(null);
  const cursorRef = useRef(0);
  const openRef = useRef(open);
  openRef.current = open;

  const poll = useCallback(async () => {
    try {
      const thread = await getChatMessages(cursorRef.current);
      if (thread.session_uuid !== sessionRef.current) {
        // A different student signed in (or out): start clean.
        sessionRef.current = thread.session_uuid;
        cursorRef.current = 0;
        setMessages([]);
        setUnread(0);
        if (thread.session_uuid) return void poll();
      }
      setPending(thread.pending);
      if (thread.messages.length > 0) {
        cursorRef.current = Math.max(cursorRef.current, ...thread.messages.map((message) => message.id));
        setMessages((previous) => {
          const known = new Set(previous.map((message) => message.uuid));
          return [...previous, ...thread.messages.filter((message) => !known.has(message.uuid))];
        });
      }
      // null means the server could not be reached: keep what the badge showed.
      if (thread.unread !== null) {
        setUnread(thread.unread);
        if (openRef.current && thread.unread > 0) {
          void markChatRead().then(() => setUnread(0)).catch(() => {});
        }
      }
    } catch {
      // Best-effort only — a failed poll just retries next tick.
    }
  }, []);

  useEffect(() => {
    void poll();
    const interval = setInterval(() => void poll(), POLL_MS);
    return () => clearInterval(interval);
  }, [poll]);

  useEffect(() => {
    if (open && unread > 0) void markChatRead().then(() => setUnread(0)).catch(() => {});
  }, [open, unread]);

  const send = useCallback(
    async (body: string) => {
      await sendChatMessage(crypto.randomUUID(), body);
      void poll();
      setTimeout(() => void poll(), AFTER_SEND_MS);
    },
    [poll],
  );

  return { messages, pending, unread, send };
}
