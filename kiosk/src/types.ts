export interface AppConfig {
  school_id: number;
  computer_id: number;
  computer_name: string;
  api_base_url: string;
  last_roster_synced_at: string | null;
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
