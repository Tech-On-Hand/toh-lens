import { useEffect, useState } from "react";
import { getScreenWatchStatus } from "../lib/commands";

// Matches BROWSER_TICK (lib.rs), the same cadence screen_share.rs itself
// polls on — this can't be stale for longer than one real tick either way.
const POLL_INTERVAL_MS = 3_000;

/**
 * The one on-screen sign that a teacher currently has this device's screen
 * open. Screen-watching isn't tied to whether a student is logged in (the
 * backend scopes it to the device, not the session), so this is shown on
 * both the keypad and the logged-in screen — captures start regardless of
 * which one is up.
 */
export function ScreenWatchIndicator() {
  const [watched, setWatched] = useState(false);

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      getScreenWatchStatus()
        .then((result) => {
          if (!cancelled) setWatched(result);
        })
        .catch(() => {
          // Best-effort only — a failed poll just leaves the previous state showing.
        });
    };

    poll();
    const interval = setInterval(poll, POLL_INTERVAL_MS);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  if (!watched) return null;

  return (
    <div className="screen-watch-indicator" role="status">
      <span className="screen-watch-dot" />
      Your teacher is viewing this screen
    </div>
  );
}
