export interface AppConfig {
  school_id: number;
  computer_id: number;
  computer_name: string;
  api_base_url: string;
  last_roster_synced_at: string | null;
  device_uuid: string | null;
  classroom_id: number | null;
  configuration_version: number;
}

export interface StudentSummary {
  id: number;
  admission_number: string;
  full_name: string;
}

export interface RosterStatus {
  student_count: number;
  last_synced_at: string | null;
}

export interface LoginSessionRecord {
  session_uuid: string;
  admission_number: string;
  full_name: string;
  login_time: string;
  logout_time: string | null;
}

export interface SyncStatus {
  is_online: boolean;
  unsynced_count: number;
  last_attempt_at: string | null;
  last_success_at: string | null;
}

export interface SyncResult {
  attempted: number;
  synced: number;
  failed: number;
}

export interface Announcement {
  id: string;
  message: string;
  sent_by: string | null;
  sent_at: string;
  expires_at: string;
}

export interface HelpRequest {
  id: string;
  // "queued": raised with no connection, still waiting to be sent.
  status: "queued" | "open" | "resolved" | "cancelled";
  message: string | null;
  requested_at: string;
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
  session_uuid: string | null;
  // null: the server could not be reached, so the count is unknown.
  unread: number | null;
  messages: ChatMessage[];
  // Written by the student but not delivered yet (queued on disk).
  pending: { uuid: string; body: string }[];
}
