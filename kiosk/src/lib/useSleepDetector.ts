import { useEffect, useRef } from "react";

// A restart is caught separately (the kiosk reclaims any dangling session at
// startup, see reclaimStaleSession); this catches staying logged in through a
// sleep/hibernate with no restart in between.
const TICK_MS = 2_000;
// A gap this much bigger than the tick interval means the OS suspended this
// process (sleep, hibernate, lid closed) rather than ordinary lag: a suspended
// process's timers simply don't fire until it resumes, so the real time
// between ticks (Date.now(), which keeps advancing while asleep) jumps far
// more than a timer that was merely running a little behind ever would.
export const SUSPECTED_SLEEP_GAP_MS = 10_000;

/**
 * FR-1 identity risk: sleeping/hibernating pauses this process's timers, so
 * the idle-timeout warning never fires for the real time spent asleep — a
 * different student could wake the machine and carry on as whoever was
 * signed in, with no grace period the way idle timeout gives. `onWake` fires
 * immediately (not a warning) the moment a suspected sleep is detected,
 * however brief.
 */
export function useSleepDetector(onWake: () => void): void {
  const onWakeRef = useRef(onWake);
  onWakeRef.current = onWake;

  useEffect(() => {
    let lastTick = Date.now();
    const interval = window.setInterval(() => {
      const now = Date.now();
      const gap = now - lastTick;
      lastTick = now;
      if (gap > SUSPECTED_SLEEP_GAP_MS) onWakeRef.current();
    }, TICK_MS);
    return () => window.clearInterval(interval);
  }, []);
}
