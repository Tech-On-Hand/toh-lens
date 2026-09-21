import { invoke } from "@tauri-apps/api/core";
import type { BrowserTab, Classroom, CommandSummary, Device, TeacherSession } from "./types";

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
