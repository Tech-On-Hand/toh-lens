export interface TeacherSession {
  user: { id: number; name: string; email: string };
  is_administrator: boolean;
}

export interface Classroom {
  id: number;
  uuid: string;
  name: string;
  school: { id: number; name: string };
}

export interface Device {
  id: number;
  device_uuid: string;
  name: string;
  hostname: string | null;
  operating_system: string | null;
  agent_version: string | null;
  status: "online" | "offline";
  last_seen_at: string | null;
  active_session: {
    uuid: string;
    student: { id: number; full_name: string; admission_number: string } | null;
    started_at: string;
  } | null;
}
