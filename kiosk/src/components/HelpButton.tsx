import { useCallback, useEffect, useState } from "react";
import { cancelHelpRequest, commandErrorMessage, getHelpRequest, requestHelp } from "../lib/commands";
import type { HelpRequest } from "../types";

// Also how quickly the button clears once the teacher marks the request resolved.
const POLL_MS = 3_000;

/**
 * "Ask for help" — raises a hand to the teacher, who sees it on their device grid.
 * Shows "Teacher notified" until the teacher resolves it or the student cancels.
 * The request id is chosen here, so tapping again after a dropped connection
 * re-sends the same request instead of raising a second one.
 */
export function HelpButton() {
  const [request, setRequest] = useState<HelpRequest | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      getHelpRequest()
        .then((current) => {
          if (!cancelled) setRequest(current);
        })
        .catch(() => {
          // Best-effort only — a failed poll leaves the last known state showing.
        });
    };

    poll();
    const interval = setInterval(poll, POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  const ask = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      setRequest(await requestHelp(crypto.randomUUID()));
    } catch (reason) {
      setError(commandErrorMessage(reason));
    } finally {
      setBusy(false);
    }
  }, []);

  const cancel = useCallback(async () => {
    if (!request) return;
    setBusy(true);
    setError(null);
    try {
      await cancelHelpRequest(request.id);
      setRequest(null);
    } catch (reason) {
      setError(commandErrorMessage(reason));
    } finally {
      setBusy(false);
    }
  }, [request]);

  if (request) {
    return (
      <div className="help-button help-button--waiting">
        <span>{request.status === "queued" ? "Sends when online" : "Teacher notified"}</span>
        <button type="button" onClick={cancel} disabled={busy}>
          Cancel
        </button>
      </div>
    );
  }

  return (
    <div className="help-button">
      <button type="button" className="help-ask" onClick={ask} disabled={busy}>
        {busy ? "Sending…" : "Ask for help"}
      </button>
      {error && <span className="help-error">Couldn't reach the teacher. Try again.</span>}
    </div>
  );
}
