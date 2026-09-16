import { useEffect, useState } from "react";
import { getRosterCacheStatus } from "../lib/commands";

const POLL_INTERVAL_MS = 60_000;
const STALE_AFTER_HOURS = 24;

function formatAge(lastSyncedAt: string): string {
  const hours = (Date.now() - new Date(lastSyncedAt).getTime()) / (1000 * 60 * 60);
  if (hours < 1) return "less than an hour ago";
  if (hours < 24) return `${Math.floor(hours)}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

/**
 * SRS Risk #5: the roster auto-refreshes every 6h whenever online (see
 * sync.rs), but if this computer has been offline for longer than that, a
 * newly enrolled student may not validate yet. This is a quiet, staff-facing
 * hint — not an error — so it stays subtle rather than blocking the keypad.
 */
export function RosterFreshnessNote() {
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      getRosterCacheStatus()
        .then((status) => {
          if (!cancelled) setLastSyncedAt(status.last_synced_at);
        })
        .catch(() => {
          // Best-effort only.
        });
    };

    poll();
    const interval = setInterval(poll, POLL_INTERVAL_MS);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  if (!lastSyncedAt) {
    return <p className="roster-freshness-note">Roster not yet downloaded — connect to sync.</p>;
  }

  const ageHours = (Date.now() - new Date(lastSyncedAt).getTime()) / (1000 * 60 * 60);
  if (ageHours < STALE_AFTER_HOURS) {
    return null;
  }

  return (
    <p className="roster-freshness-note roster-freshness-note--stale">
      Roster last updated {formatAge(lastSyncedAt)} — newly enrolled students may not validate yet.
    </p>
  );
}
