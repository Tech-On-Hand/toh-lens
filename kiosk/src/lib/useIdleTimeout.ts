import { useEffect, useState } from "react";

interface UseIdleTimeoutOptions {
  /** Total time with no activity before `onIdle` fires, in milliseconds. */
  idleMs: number;
  /** How long before the deadline to start warning, in milliseconds. */
  warningMs: number;
  onIdle: () => void;
}

const ACTIVITY_EVENTS = ["mousemove", "mousedown", "keydown", "touchstart", "click"] as const;

/**
 * SRS Risk #2 fallback: a student who forgets to click "Log Out" would
 * otherwise leave a session open until the next login silently closes it.
 * This gives a warning, then logs out automatically, so session boundaries
 * stay reasonably accurate without relying on students remembering.
 */
export function useIdleTimeout({ idleMs, warningMs, onIdle }: UseIdleTimeoutOptions): {
  isWarning: boolean;
  secondsRemaining: number;
} {
  const [isWarning, setIsWarning] = useState(false);
  const [secondsRemaining, setSecondsRemaining] = useState(Math.ceil(warningMs / 1000));

  useEffect(() => {
    let warningTimer: number;
    let idleTimer: number;
    let countdownInterval: number;

    const clearAll = () => {
      window.clearTimeout(warningTimer);
      window.clearTimeout(idleTimer);
      window.clearInterval(countdownInterval);
    };

    const reset = () => {
      clearAll();
      setIsWarning(false);
      setSecondsRemaining(Math.ceil(warningMs / 1000));

      warningTimer = window.setTimeout(() => {
        setIsWarning(true);
        let remaining = Math.ceil(warningMs / 1000);
        setSecondsRemaining(remaining);
        countdownInterval = window.setInterval(() => {
          remaining -= 1;
          setSecondsRemaining(Math.max(0, remaining));
        }, 1000);
      }, Math.max(0, idleMs - warningMs));

      idleTimer = window.setTimeout(() => {
        clearAll();
        onIdle();
      }, idleMs);
    };

    reset();
    for (const event of ACTIVITY_EVENTS) {
      window.addEventListener(event, reset);
    }

    return () => {
      clearAll();
      for (const event of ACTIVITY_EVENTS) {
        window.removeEventListener(event, reset);
      }
    };
  }, [idleMs, warningMs, onIdle]);

  return { isWarning, secondsRemaining };
}
