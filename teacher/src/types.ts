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
  active_tab: { tab_id: number; url: string | null; title: string | null; observed_at: string } | null;
  active_session: {
    uuid: string;
    student: { id: number; full_name: string; admission_number: string } | null;
    started_at: string;
  } | null;
}

export interface BrowserTab {
  tab_id: number;
  window_id: number | null;
  browser: "chrome" | "edge";
  url: string | null;
  title: string | null;
  is_active: boolean;
  observed_at: string;
}

export interface CommandSummary {
  id: string;
  type: string;
  status: "pending" | "delivered" | "completed" | "failed" | "expired";
  result: { error?: string } | null;
  expires_at: string;
}

export interface FocusSession {
  id: string;
  name: string | null;
  allowed_domains: string[];
  started_at: string;
  expires_at: string;
}

export interface ClassroomPolicy {
  block_rules: { id: number; domain: string; scope: "school" | "classroom" }[];
  focus: FocusSession | null;
  server_time: string;
}
