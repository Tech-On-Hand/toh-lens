import { useCallback, useEffect, useState } from "react";
import { ScreenWatchIndicator } from "../components/ScreenWatchIndicator";
import { SyncStatusBadge } from "../components/SyncStatusBadge";
import { commandErrorMessage, hideHelpWidget, recordLogout, showHelpWidget } from "../lib/commands";
import { useIdleTimeout } from "../lib/useIdleTimeout";
import { useSleepDetector } from "../lib/useSleepDetector";
import type { LoginSessionRecord } from "../types";

const SLEEP_NOTICE = "This computer went to sleep, so you were signed out. Please sign in again.";

interface LoggedInScreenProps {
  session: LoginSessionRecord;
  onLogout: (notice?: string) => void;
}

// A typical lesson period is long enough that these defaults shouldn't
// interrupt real use, while still closing forgotten sessions well before
// the next student sits down.
const IDLE_TIMEOUT_MS = 20 * 60 * 1000;
const IDLE_WARNING_MS = 60 * 1000;

export function LoggedInScreen({ session, onLogout }: LoggedInScreenProps) {
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleLogout = useCallback(async (notice?: string) => {
    setIsLoggingOut(true);
    setError(null);

    try {
      await recordLogout(session.session_uuid);
      onLogout(notice);
    } catch (err) {
      setError(commandErrorMessage(err));
      setIsLoggingOut(false);
    }
  }, [session.session_uuid, onLogout]);

  // The kiosk window is hidden behind the desktop while a student is logged in,
  // so the way to reach the teacher is a small floating button instead.
  useEffect(() => {
    void showHelpWidget().catch(() => {});
    return () => void hideHelpWidget().catch(() => {});
  }, []);

  const { isWarning, secondsRemaining } = useIdleTimeout({
    idleMs: IDLE_TIMEOUT_MS,
    warningMs: IDLE_WARNING_MS,
    onIdle: handleLogout,
  });

  // FR-1 identity risk: sleep/hibernate pauses this window's timers, so a
  // different student could wake the machine and carry on as whoever was
  // signed in, with none of the idle-timeout warning's grace period. Signs
  // out at once, no warning, the moment a suspected sleep is detected.
  const forceLogoutAfterSleep = useCallback(() => {
    void handleLogout(SLEEP_NOTICE);
  }, [handleLogout]);
  useSleepDetector(forceLogoutAfterSleep);

  return (
    <div className="screen loggedin-screen">
      <ScreenWatchIndicator />
      <SyncStatusBadge />

      <h1>Welcome, {session.full_name}</h1>
      <p>You're logged in on this computer. Have a great lesson!</p>

      {error && <p className="setup-error">{error}</p>}

      <button type="button" className="logout-button" onClick={() => void handleLogout()} disabled={isLoggingOut}>
        {isLoggingOut ? "Logging out…" : "Log Out"}
      </button>

      {isWarning && !isLoggingOut && (
        <div className="idle-warning" role="alert">
          Logging out in {secondsRemaining}s due to inactivity — tap anywhere to stay logged in.
        </div>
      )}
    </div>
  );
}
