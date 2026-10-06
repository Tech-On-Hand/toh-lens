import { listen } from "@tauri-apps/api/event";
import { useCallback, useEffect, useRef, useState } from "react";
import { ScreenWatchIndicator } from "../components/ScreenWatchIndicator";
import { SyncStatusBadge } from "../components/SyncStatusBadge";
import { commandErrorMessage, hideHelpWidget, recordLogout, showHelpWidget } from "../lib/commands";
import type { LoginSessionRecord } from "../types";

// Why the keypad is back, by the reason the native session guard gives. A plain
// Log Out needs no explanation.
const NOTICES: Record<string, string | undefined> = {
  sleep: "This computer went to sleep, so you were signed out. Please sign in again.",
  idle: "You were signed out because the computer was idle. Please sign in again.",
};

interface LoggedInScreenProps {
  session: LoginSessionRecord;
  onLogout: (notice?: string) => void;
}

/**
 * Idle and sleep are decided by the kiosk's native side (session_guard.rs), not in
 * this page: the page is hidden behind the desktop while a student works, so it
 * never sees their keyboard or mouse, and hidden pages have their timers slowed.
 * This screen just reports what the guard says: a countdown before an idle
 * sign-out, and the end of the session (from idle, sleep, or the floating bar's
 * Log Out button).
 */
export function LoggedInScreen({ session, onLogout }: LoggedInScreenProps) {
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [idleSecondsLeft, setIdleSecondsLeft] = useState<number | null>(null);

  const onLogoutRef = useRef(onLogout);
  onLogoutRef.current = onLogout;

  const handleLogout = useCallback(async () => {
    setIsLoggingOut(true);
    setError(null);

    try {
      await recordLogout(session.session_uuid);
      onLogoutRef.current();
    } catch (err) {
      setError(commandErrorMessage(err));
      setIsLoggingOut(false);
    }
  }, [session.session_uuid]);

  // The kiosk window is hidden behind the desktop while a student is logged in,
  // so the way to reach the teacher (and to sign out) is a small floating bar.
  useEffect(() => {
    void showHelpWidget().catch(() => {});
    return () => void hideHelpWidget().catch(() => {});
  }, []);

  useEffect(() => {
    let disposed = false;
    const unlisteners: Array<() => void> = [];
    const track = (pending: Promise<() => void>) =>
      void pending.then((unlisten) => (disposed ? unlisten() : unlisteners.push(unlisten))).catch(() => {});

    track(listen<{ reason: string }>("session-ended", (event) => onLogoutRef.current(NOTICES[event.payload.reason])));
    track(listen<{ seconds_remaining: number }>("session-idle-warning", (event) => setIdleSecondsLeft(event.payload.seconds_remaining)));
    track(listen("session-idle-cleared", () => setIdleSecondsLeft(null)));

    return () => {
      disposed = true;
      unlisteners.forEach((unlisten) => unlisten());
    };
  }, []);

  return (
    <div className="screen loggedin-screen">
      <ScreenWatchIndicator />
      <SyncStatusBadge />

      <img className="brand-mark" src="/toh-mark.svg" alt="" />
      <h1>Welcome, {session.full_name}</h1>
      <p>You're logged in on this computer. Have a great lesson!</p>

      {error && <p className="setup-error">{error}</p>}

      <button type="button" className="logout-button" onClick={() => void handleLogout()} disabled={isLoggingOut}>
        {isLoggingOut ? "Logging out…" : "Log Out"}
      </button>

      {idleSecondsLeft !== null && !isLoggingOut && (
        <div className="idle-warning" role="alert">
          Logging out in {idleSecondsLeft}s because nobody is using this computer — press any key or move the mouse to stay signed in.
        </div>
      )}
    </div>
  );
}
