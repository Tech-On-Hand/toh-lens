//! Ends a student's session when the kiosk can no longer be sure they are still
//! the one at the keyboard: they walked away, or the computer slept. It runs here,
//! not in the kiosk's web page, for two reasons. The page is hidden behind the
//! desktop while a student is signed in, so it never sees their mouse and keyboard
//! (an idle timer in it would sign out someone who is working), and browsers slow
//! or pause timers in hidden pages (a sleep check in it could misfire). This loop
//! makes no network calls and reads the real, system-wide idle time.
//!
//! Also here: `end_session`, the one way a session is closed by the kiosk on its
//! own account or from the floating bar's Log Out button.

use crate::db::{config_repo, session_repo};
use crate::state::AppState;
use chrono::{DateTime, Duration as Span, Utc};
use serde_json::json;
use std::time::Duration;
use tauri::{AppHandle, Emitter};

/// No keyboard or mouse for this long and the session ends.
pub const IDLE_LOGOUT_SECS: u64 = 20 * 60;
/// How long before that the student is warned (and the kiosk window comes forward).
pub const IDLE_WARNING_SECS: u64 = 60;
const TICK: Duration = Duration::from_secs(2);
/// This loop wakes every couple of seconds, so a gap this long between two of its
/// wake-ups means the whole computer was suspended (sleep, hibernate) in between.
const SUSPEND_GAP: Span = Span::seconds(20);

#[derive(Debug, Clone, Copy, PartialEq)]
pub enum EndReason {
    /// The student (or their neighbour, from the floating bar) chose Log Out.
    Logout,
    Idle,
    Sleep,
}

impl EndReason {
    fn as_str(self) -> &'static str {
        match self {
            EndReason::Logout => "logout",
            EndReason::Idle => "idle",
            EndReason::Sleep => "sleep",
        }
    }
}

#[derive(Debug, PartialEq)]
pub enum IdleDecision {
    Nothing,
    Warn { seconds_remaining: u64 },
    ClearWarning,
    End,
}

/// What to do about `idle_secs` of no input, given whether a warning is showing.
pub fn decide_idle(idle_secs: u64, warning_showing: bool) -> IdleDecision {
    if idle_secs >= IDLE_LOGOUT_SECS {
        return IdleDecision::End;
    }
    if idle_secs >= IDLE_LOGOUT_SECS - IDLE_WARNING_SECS {
        return IdleDecision::Warn { seconds_remaining: IDLE_LOGOUT_SECS - idle_secs };
    }
    if warning_showing { IdleDecision::ClearWarning } else { IdleDecision::Nothing }
}

/// Whether the loop was asleep between two of its wake-ups.
pub fn was_suspended(previous_wake: DateTime<Utc>, now: DateTime<Utc>) -> bool {
    now.signed_duration_since(previous_wake) > SUSPEND_GAP
}

/// Closes the session and puts the kiosk back on the keypad: records the logout
/// (at `at`, or now), reclaims the desktop and shows the kiosk window, hides the
/// floating bar, and tells the kiosk screen why so it can say so.
pub fn end_session(app: &AppHandle, state: &AppState, session_uuid: &str, reason: EndReason, at: Option<DateTime<Utc>>) {
    {
        let conn = state.db.lock().unwrap();
        let ended_at = at.unwrap_or_else(Utc::now).to_rfc3339();
        if let Err(error) = session_repo::close_logout_at(&conn, session_uuid, &ended_at) {
            log::warn!("session guard: could not close session {session_uuid}: {error}");
            return;
        }
    }

    crate::shell_handoff::reclaim_desktop(app, state);
    crate::help_widget::hide(app);
    state.bridge.request_snapshots();
    let _ = app.emit("session-ended", json!({ "reason": reason.as_str() }));

    // Push the finished session promptly instead of waiting for the periodic sync.
    let state = state.clone();
    tauri::async_runtime::spawn(async move {
        crate::sync::try_sync(&state).await;
    });
}

fn open_session_uuid(state: &AppState) -> Option<String> {
    let conn = state.db.lock().unwrap();
    let config = config_repo::get_config(&conn).ok().flatten()?;
    session_repo::find_open_session(&conn, config.computer_id).ok().flatten().map(|session| session.session_uuid)
}

pub fn start(app: AppHandle, state: AppState) {
    tauri::async_runtime::spawn(async move {
        let mut interval = tokio::time::interval(TICK);
        interval.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
        let mut previous_wake = Utc::now();
        let mut warning_showing = false;

        loop {
            interval.tick().await;
            let now = Utc::now();
            let last_wake = previous_wake;
            previous_wake = now;

            let Some(session_uuid) = open_session_uuid(&state) else {
                warning_showing = false;
                continue;
            };

            if was_suspended(last_wake, now) {
                warning_showing = false;
                end_session(&app, &state, &session_uuid, EndReason::Sleep, Some(last_wake));
                continue;
            }

            match decide_idle(crate::app_activity::system_idle_seconds(), warning_showing) {
                IdleDecision::Nothing => {}
                IdleDecision::Warn { seconds_remaining } => {
                    if !warning_showing {
                        warning_showing = true;
                        // The kiosk window is hidden while a student works; bring it forward so they see the warning.
                        crate::shell_handoff::present_window(&app);
                    }
                    let _ = app.emit("session-idle-warning", json!({ "seconds_remaining": seconds_remaining }));
                }
                IdleDecision::ClearWarning => {
                    warning_showing = false;
                    crate::shell_handoff::release_window(&app, &state);
                    let _ = app.emit("session-idle-cleared", ());
                }
                IdleDecision::End => {
                    warning_showing = false;
                    end_session(&app, &state, &session_uuid, EndReason::Idle, None);
                }
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn at(seconds: i64) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339("2026-09-25T09:00:00Z").unwrap().with_timezone(&Utc) + Span::seconds(seconds)
    }

    #[test]
    fn a_student_who_is_working_is_left_alone() {
        assert_eq!(decide_idle(0, false), IdleDecision::Nothing);
        assert_eq!(decide_idle(IDLE_LOGOUT_SECS - IDLE_WARNING_SECS - 1, false), IdleDecision::Nothing);
    }

    #[test]
    fn the_warning_starts_a_minute_before_signing_out_and_counts_down() {
        assert_eq!(decide_idle(IDLE_LOGOUT_SECS - 60, false), IdleDecision::Warn { seconds_remaining: 60 });
        assert_eq!(decide_idle(IDLE_LOGOUT_SECS - 10, true), IdleDecision::Warn { seconds_remaining: 10 });
    }

    #[test]
    fn touching_the_keyboard_during_the_warning_clears_it() {
        assert_eq!(decide_idle(3, true), IdleDecision::ClearWarning);
    }

    #[test]
    fn twenty_minutes_of_nothing_ends_the_session_warned_or_not() {
        assert_eq!(decide_idle(IDLE_LOGOUT_SECS, true), IdleDecision::End);
        assert_eq!(decide_idle(IDLE_LOGOUT_SECS + 500, false), IdleDecision::End);
    }

    #[test]
    fn ordinary_wake_ups_are_not_a_suspend() {
        assert!(!was_suspended(at(0), at(2)));
        assert!(!was_suspended(at(0), at(6))); // a busy moment
        assert!(!was_suspended(at(0), at(20))); // right at the limit
    }

    #[test]
    fn a_long_gap_between_wake_ups_means_the_computer_slept() {
        assert!(was_suspended(at(0), at(21)));
        assert!(was_suspended(at(0), at(3 * 3600)));
    }

    #[test]
    fn the_clock_being_set_back_is_not_a_suspend() {
        assert!(!was_suspended(at(100), at(10)));
    }
}
