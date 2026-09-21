import { invoke } from "@tauri-apps/api/core";
import type { BrowserTab, Classroom, ClassroomPolicy, CommandSummary, Device, FocusSession, TeacherSession } from "./types";

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
