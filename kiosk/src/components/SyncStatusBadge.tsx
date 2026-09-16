import { useEffect, useState } from "react";
import { getSyncStatus } from "../lib/commands";
import type { SyncStatus } from "../types";

const POLL_INTERVAL_MS = 10_000;

export function SyncStatusBadge() {
  const [status, setStatus] = useState<SyncStatus | null>(null);

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      getSyncStatus()
        .then((result) => {
          if (!cancelled) setStatus(result);
        })
        .catch(() => {
          // Best-effort indicator only — a failed poll just leaves the
          // previous status showing until the next tick.
        });
    };

    poll();
    const interval = setInterval(poll, POLL_INTERVAL_MS);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  if (!status) return null;

  const label = status.is_online
    ? status.unsynced_count > 0
      ? `Syncing… ${status.unsynced_count} pending`
      : "Online — synced"
    : `Offline${status.unsynced_count > 0 ? ` — ${status.unsynced_count} pending` : ""}`;

  return (
    <div className={`sync-status-badge ${status.is_online ? "sync-status-badge--online" : "sync-status-badge--offline"}`}>
      <span className="sync-status-dot" />
      {label}
    </div>
  );
}
