import { invoke } from "@tauri-apps/api/core";
import type { Classroom, Device, TeacherSession } from "./types";

export const login = (apiBaseUrl: string, email: string, password: string) =>
  invoke<TeacherSession>("login", { apiBaseUrl, email, password });
export const restoreSession = () => invoke<TeacherSession | null>("restore_session");
export const logout = () => invoke<void>("logout");
export const listClassrooms = () => invoke<Classroom[]>("list_classrooms");
export const listDevices = (classroomId: number) => invoke<Device[]>("list_devices", { classroomId });
export const startRealtime = (classroomId: number) => invoke<void>("start_realtime", { classroomId });
export const revokeDevice = (deviceId: number) => invoke<void>("revoke_device", { deviceId });
