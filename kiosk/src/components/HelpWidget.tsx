import { useCallback, useEffect, useState } from "react";
import { logoutCurrentSession, setHelpWidgetExpanded } from "../lib/commands";
import { useChat } from "../lib/useChat";
import { ChatPanel } from "./ChatPanel";
import { HelpButton } from "./HelpButton";

// How long "Sure?" waits for the second tap before going back to "Log out".
const CONFIRM_MS = 4_000;

/**
 * The whole content of the small always-on-top window (see help_widget.rs): a slim
 * bar with "Ask for help", "Chat" and "Log out", which grows into the chat panel
 * when opened. It is the only part of the kiosk a student can see while they work
 * on the desktop, so it is also where they sign out.
 */
export function HelpWidget() {
  const [open, setOpen] = useState(false);
  const [confirmingLogout, setConfirmingLogout] = useState(false);
  const chat = useChat(open);

  const toggle = useCallback((next: boolean) => {
    setOpen(next);
    void setHelpWidgetExpanded(next).catch(() => {});
  }, []);

  useEffect(() => {
    if (!confirmingLogout) return;
    const timer = window.setTimeout(() => setConfirmingLogout(false), CONFIRM_MS);
    return () => window.clearTimeout(timer);
  }, [confirmingLogout]);

  const logout = () => {
    if (!confirmingLogout) {
      setConfirmingLogout(true);
      return;
    }
    void logoutCurrentSession().catch(() => setConfirmingLogout(false));
  };

  if (open) {
    return (
      <div className="help-widget help-widget--chat">
        <ChatPanel messages={chat.messages} pending={chat.pending} onSend={chat.send} onClose={() => toggle(false)} />
      </div>
    );
  }

  return (
    <div className="help-widget">
      {/* Lets a student drag the bar elsewhere if it's covering something they need. */}
      <div className="widget-drag-handle" data-tauri-drag-region="" title="Drag to move">
        <span /><span /><span /><span /><span /><span />
      </div>
      <HelpButton />
      <button type="button" className={`chat-open ${chat.unread > 0 ? "chat-open--unread" : ""}`} onClick={() => toggle(true)}>
        Chat{chat.unread > 0 ? ` (${chat.unread})` : ""}
      </button>
      <button type="button" className={`widget-logout ${confirmingLogout ? "widget-logout--confirm" : ""}`} onClick={logout}>
        {confirmingLogout ? "Sure? Log out" : "Log out"}
      </button>
    </div>
  );
}
