import { useCallback, useState } from "react";
import { setHelpWidgetExpanded } from "../lib/commands";
import { useChat } from "../lib/useChat";
import { ChatPanel } from "./ChatPanel";
import { HelpButton } from "./HelpButton";

/**
 * The whole content of the small always-on-top window (see help_widget.rs): a slim
 * bar with "Ask for help" and "Chat", which grows into the chat panel when opened.
 */
export function HelpWidget() {
  const [open, setOpen] = useState(false);
  const chat = useChat(open);

  const toggle = useCallback((next: boolean) => {
    setOpen(next);
    void setHelpWidgetExpanded(next).catch(() => {});
  }, []);

  if (open) {
    return (
      <div className="help-widget help-widget--chat">
        <ChatPanel messages={chat.messages} pending={chat.pending} onSend={chat.send} onClose={() => toggle(false)} />
      </div>
    );
  }

  return (
    <div className="help-widget">
      <HelpButton />
      <button type="button" className={`chat-open ${chat.unread > 0 ? "chat-open--unread" : ""}`} onClick={() => toggle(true)}>
        Chat{chat.unread > 0 ? ` (${chat.unread})` : ""}
      </button>
    </div>
  );
}
