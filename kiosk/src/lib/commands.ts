import { invoke } from "@tauri-apps/api/core";
import type {
  Announcement,
  AppConfig,
  ChatThread,
  HelpRequest,
  LoginSessionRecord,
  RosterStatus,
  StudentSummary,
  SyncResult,
  SyncStatus,
} from "../types";

export function getConfig(): Promise<AppConfig | null> {
  return invoke("get_config");
}

export function saveConfig(apiBaseUrl: string, apiToken: string): Promise<AppConfig> {
  return invoke("save_config", { apiBaseUrl, apiToken });
}

export function enrollDevice(apiBaseUrl: string, enrollmentCode: string, deviceName: string): Promise<AppConfig> {
  return invoke("enroll_device", { apiBaseUrl, enrollmentCode, deviceName });
}

export function getRosterCacheStatus(): Promise<RosterStatus> {
  return invoke("get_roster_cache_status");
}

export function refreshRoster(): Promise<RosterStatus> {
  return invoke("refresh_roster");
}

export function validateAdmissionNumber(admissionNumber: string): Promise<StudentSummary> {
  return invoke("validate_admission_number", { admissionNumber });
}

export function recordLogin(admissionNumber: string): Promise<LoginSessionRecord> {
  return invoke("record_login", { admissionNumber });
}

export function recordLogout(sessionUuid: string): Promise<void> {
  return invoke("record_logout", { sessionUuid });
}

export function getOpenSession(): Promise<LoginSessionRecord | null> {
  return invoke("get_open_session");
}

export function syncNow(): Promise<SyncResult> {
  return invoke("sync_now");
}

export function getSyncStatus(): Promise<SyncStatus> {
  return invoke("get_sync_status");
}

export function getScreenWatchStatus(): Promise<boolean> {
  return invoke("get_screen_watch_status");
}

export function getAnnouncements(): Promise<Announcement[]> {
  return invoke("get_announcements");
}

export function markAnnouncementRead(announcementId: string): Promise<void> {
  return invoke("mark_announcement_read", { announcementId });
}

export async function getHelpRequest(): Promise<HelpRequest | null> {
  const result = await invoke<{ help_request: HelpRequest | null }>("get_help_request");
  return result.help_request;
}

export function requestHelp(requestId: string, message?: string): Promise<HelpRequest> {
  return invoke("request_help", { requestId, message: message ?? null });
}

export function cancelHelpRequest(requestId: string): Promise<void> {
  return invoke("cancel_help_request", { requestId });
}

export function getChatMessages(after: number): Promise<ChatThread> {
  return invoke("get_chat_messages", { after });
}

export function sendChatMessage(messageId: string, body: string): Promise<void> {
  return invoke("send_chat_message", { messageId, body });
}

export function markChatRead(): Promise<void> {
  return invoke("mark_chat_read");
}

export function setHelpWidgetExpanded(expanded: boolean): Promise<void> {
  return invoke("set_help_widget_expanded", { expanded });
}

/** Bring the kiosk window over the desktop (e.g. to show an announcement). */
export function presentWindow(): Promise<void> {
  return invoke("present_window");
}

/** Put the desktop back after `presentWindow`, if a student is logged in. */
export function releaseWindow(): Promise<void> {
  return invoke("release_window");
}

export function showHelpWidget(): Promise<void> {
  return invoke("show_help_widget");
}

export function hideHelpWidget(): Promise<void> {
  return invoke("hide_help_widget");
}

/** Dev-only: exercises the keypad/login flow before a backend exists. */
export function seedDemoConfig(): Promise<AppConfig> {
  return invoke("seed_demo_config");
}

/** Dev-only: exercises the keypad/login flow before a backend exists. */
export function seedDemoRoster(): Promise<RosterStatus> {
  return invoke("seed_demo_roster");
}

/** Best-effort extraction of a message from a rejected invoke() call. */
export function commandErrorMessage(error: unknown): string {
  if (typeof error === "string") return error;
  if (error instanceof Error) return error.message;
  return "Something went wrong.";
}
