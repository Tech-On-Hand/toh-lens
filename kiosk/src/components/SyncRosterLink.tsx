import { useState } from "react";
import { refreshRoster } from "../lib/commands";

type State = { kind: "idle" } | { kind: "syncing" } | { kind: "done"; count: number } | { kind: "failed" };

/**
 * A small underlined "Sync student list" under the help button: downloads the
 * roster now, so a student added a moment ago can sign in without waiting for
 * the 6-hourly refresh or restarting the app. Says what happened, briefly.
 */
export function SyncRosterLink() {
  const [state, setState] = useState<State>({ kind: "idle" });

  const sync = async () => {
    setState({ kind: "syncing" });
    try {
      const status = await refreshRoster();
      setState({ kind: "done", count: status.student_count });
    } catch {
      setState({ kind: "failed" });
    }
  };

  return (
    <div className="sync-roster">
      <button type="button" className="sync-roster-link" onClick={sync} disabled={state.kind === "syncing"}>
        {state.kind === "syncing" ? "Syncing…" : "Sync student list"}
      </button>
      {state.kind === "done" && (
        <span className="sync-roster-note">
          Updated: {state.count} {state.count === 1 ? "student" : "students"}
        </span>
      )}
      {state.kind === "failed" && <span className="sync-roster-note sync-roster-note--error">Couldn't sync. Check the connection.</span>}
    </div>
  );
}
