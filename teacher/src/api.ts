import { invoke } from "@tauri-apps/api/core";
import type {
  AuditEntry,
  Fleet,
  ActivityReport,
  BrowserTab,
  Classroom,
  ChatMessage,
  ChatThread,
  ClassroomPolicy,
  Announcement,
  CommandSummary,
  Device,
  FocusSession,
  HelpRequest,
  ScreenSessionPoll,
  ScreenSessionSummary,
  TeacherSession,
} from "./types";

export const login = (apiBaseUrl: string, email: string, password: string) =>
  invoke<TeacherSession>("login", { apiBaseUrl, email, password });
export const restoreSession = () => invoke<TeacherSession | null>("restore_session");
export const logout = () => invoke<void>("logout");
export const listClassrooms = () => invoke<Classroom[]>("list_classrooms");
export const listDevices = (classroomId: number) => invoke<Device[]>("list_devices", { classroomId });
export const startRealtime = (classroomId: number) => invoke<void>("start_realtime", { classroomId });
export const revokeDevice = (deviceId: number) => invoke<void>("revoke_device", { deviceId });
export const listBrowserTabs = (classroomId: number, deviceId: number) =>
  invoke<BrowserTab[]>("list_browser_tabs", { classroomId, deviceId });
export const sendCommand = (classroomId: number, deviceId: number, command: object) =>
  invoke<CommandSummary>("send_command", { classroomId, deviceId, command });
export const getCommand = (classroomId: number, deviceId: number, commandId: string) =>
  invoke<CommandSummary>("get_command", { classroomId, deviceId, commandId });
export const getPolicy = (classroomId: number) => invoke<ClassroomPolicy>("get_policy", { classroomId });
export const startFocus = (classroomId: number, allowedDomains: string[], durationMinutes: number, name: string | null) =>
  invoke<FocusSession>("start_focus", { classroomId, allowedDomains, durationMinutes, name });
export const endFocus = (classroomId: number, focusId: string) => invoke<FocusSession>("end_focus", { classroomId, focusId });
export const addBlockRule = (classroomId: number, domain: string) => invoke<unknown>("add_block_rule", { classroomId, domain });
export const removeBlockRule = (classroomId: number, ruleId: number) => invoke<unknown>("remove_block_rule", { classroomId, ruleId });
export const startScreenSession = (classroomId: number, deviceId: number, offer: RTCSessionDescriptionInit) =>
  invoke<ScreenSessionSummary>("start_screen_session", { classroomId, deviceId, offer });
export const pollScreenSession = (classroomId: number, deviceId: number, sessionId: string, after: number) =>
  invoke<ScreenSessionPoll>("poll_screen_session", { classroomId, deviceId, sessionId, after });
export const sendScreenCandidates = (classroomId: number, deviceId: number, sessionId: string, candidates: RTCIceCandidateInit[]) =>
  invoke<unknown>("send_screen_candidates", { classroomId, deviceId, sessionId, candidates });
export const setScreenQuality = (classroomId: number, deviceId: number, sessionId: string, quality: "thumb" | "full") =>
  invoke<ScreenSessionSummary>("set_screen_quality", { classroomId, deviceId, sessionId, quality });
export const endScreenSession = (classroomId: number, deviceId: number, sessionId: string) =>
  invoke<unknown>("end_screen_session", { classroomId, deviceId, sessionId });
export const startBroadcast = (classroomId: number, deviceId: number) =>
  invoke<{ id: string }>("start_broadcast", { classroomId, deviceId });
export const endBroadcast = (classroomId: number, deviceId: number, broadcastId: string) =>
  invoke<unknown>("end_broadcast", { classroomId, deviceId, broadcastId });
export const listAnnouncements = (classroomId: number) => invoke<Announcement[]>("list_announcements", { classroomId });
export const sendAnnouncement = (classroomId: number, message: string, durationMinutes: number) =>
  invoke<Announcement>("send_announcement", { classroomId, message, durationMinutes });
export const resolveHelpRequest = (classroomId: number, requestId: string) =>
  invoke<HelpRequest>("resolve_help_request", { classroomId, requestId });
export const listChatMessages = (classroomId: number, deviceId: number) =>
  invoke<ChatThread>("list_chat_messages", { classroomId, deviceId });
export const sendChatMessage = (classroomId: number, deviceId: number, messageId: string, body: string) =>
  invoke<ChatMessage>("send_chat_message", { classroomId, deviceId, messageId, body });
export const getActivityReport = (classroomId: number, from: string, to: string) =>
  invoke<ActivityReport>("get_activity_report", { classroomId, from, to });
export const listAudit = (schoolId: number, classroomId: number | null, action: string | null, beforeId: number | null, limit: number) =>
  invoke<AuditEntry[]>("list_audit", { schoolId, classroomId, action, beforeId, limit });
export const getFleet = (schoolId: number) => invoke<Fleet>("get_fleet", { schoolId });
