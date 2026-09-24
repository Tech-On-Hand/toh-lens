import { useEffect, useRef, useState } from "react";
import { getAnnouncements, markAnnouncementRead, presentWindow, releaseWindow } from "../lib/commands";
import type { Announcement } from "../types";

// Announcements are only ever noticed by polling (the device holds no socket), so
// this is how quickly a student sees one after the teacher sends it.
const POLL_MS = 3_000;

/**
 * Shows the classroom's oldest unread teacher announcement full-screen until the
 * student acknowledges it. Mounted once in App, like BroadcastViewer, so it works
 * on the keypad and the logged-in screen alike. When a student is logged in the
 * kiosk window is hidden behind the desktop; it is brought forward for the
 * announcement and put away again once the last one is read.
 */
export function AnnouncementOverlay() {
  const [pending, setPending] = useState<Announcement[]>([]);
  const [dismissing, setDismissing] = useState(false);
  const shownRef = useRef(false);

  useEffect(() => {
    let cancelled = false;

    const poll = () => {
      getAnnouncements()
        .then((announcements) => {
          if (!cancelled) setPending(announcements);
        })
        .catch(() => {
          // Best-effort only — a failed poll just retries next tick.
        });
    };

    poll();
    const interval = setInterval(poll, POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  const current = pending[0];

  useEffect(() => {
    if (current && !shownRef.current) {
      shownRef.current = true;
      void presentWindow().catch(() => {});
    } else if (!current && shownRef.current) {
      shownRef.current = false;
      void releaseWindow().catch(() => {});
    }
  }, [current]);

  if (!current) return null;

  const dismiss = async () => {
    setDismissing(true);
    try {
      await markAnnouncementRead(current.id);
      setPending((previous) => previous.filter((announcement) => announcement.id !== current.id));
    } catch {
      // Left on screen; the student can tap again.
    } finally {
      setDismissing(false);
    }
  };

  return (
    <div className="announcement-overlay" role="alertdialog" aria-live="assertive">
      <div className="announcement-card">
        <div className="announcement-from">{current.sent_by ? `Message from ${current.sent_by}` : "Message from your teacher"}</div>
        <p className="announcement-message">{current.message}</p>
        <button type="button" className="announcement-ok" onClick={dismiss} disabled={dismissing}>
          {pending.length > 1 ? `OK (${pending.length - 1} more)` : "OK"}
        </button>
      </div>
    </div>
  );
}
