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
  watched_by: string | null;
  broadcast_id: string | null;
  help_request: HelpRequest | null;
  unread_messages: number;
}

export interface ScreenSessionSummary {
  id: string;
  status: "pending" | "active" | "ended";
  quality: "thumb" | "full";
  offer: RTCSessionDescriptionInit | null;
  answer: RTCSessionDescriptionInit | null;
}

export interface ScreenSessionPoll extends ScreenSessionSummary {
  candidates: { id: number; payload: RTCIceCandidateInit }[];
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

export interface HelpRequest {
  id: string;
  status: "open" | "resolved" | "cancelled";
  message: string | null;
  requested_at: string;
}

export interface Announcement {
  id: string;
  message: string;
  sent_by: string | null;
  sent_at: string;
  expires_at: string;
  total_devices: number;
  delivered: number;
  read: number;
}

export interface ChatMessage {
  id: number;
  uuid: string;
  direction: "to_student" | "to_teacher";
  sender_name: string | null;
  body: string;
  sent_at: string;
  delivered_at: string | null;
  read_at: string | null;
}

export interface ChatThread {
  session: { uuid: string; student_name: string | null } | null;
  messages: ChatMessage[];
}

export interface ActivityReport {
  from: string;
  to: string;
  sessions: {
    session_uuid: string;
    student: { id: number; name: string } | null;
    device: string | null;
    login_time: string;
    logout_time: string | null;
    signed_in_minutes: number;
    active_minutes: number;
    idle_minutes: number;
    apps: { process: string; name: string; minutes: number }[];
    sites: { domain: string; visits: number }[];
    blocked_attempts: number;
  }[];
  classroom_apps: { process: string; name: string; minutes: number }[];
}
