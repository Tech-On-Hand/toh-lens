import { invoke } from "@tauri-apps/api/core";
import type {
  AppConfig,
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
