use serde::{Deserialize, Serialize};

/// Kiosk configuration as exposed to the frontend. `api_token` is deliberately
/// omitted — the renderer never needs it, it only needs to know setup is done.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AppConfig {
    pub school_id: i64,
    pub computer_id: i64,
    pub computer_name: String,
    pub api_base_url: String,
    pub last_roster_synced_at: Option<String>,
    pub device_uuid: Option<String>,
    pub classroom_id: Option<i64>,
    pub configuration_version: i64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct StudentSummary {
    pub id: i64,
    pub admission_number: String,
    pub full_name: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RosterStatus {
    pub student_count: i64,
    pub last_synced_at: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LoginSessionRecord {
    pub session_uuid: String,
    pub admission_number: String,
    pub full_name: String,
    pub login_time: String,
    pub logout_time: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SyncStatus {
    pub is_online: bool,
    pub unsynced_count: i64,
    pub last_attempt_at: Option<String>,
    pub last_success_at: Option<String>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct SyncResult {
    pub attempted: usize,
    pub synced: usize,
    pub failed: usize,
}

// --- Wire types for talking to the Laravel backend ---

#[derive(Debug, Clone, Deserialize)]
pub struct ComputerMeResponse {
    pub id: i64,
    pub school_id: i64,
    pub name: String,
    pub device_uuid: Option<String>,
    pub classroom_id: Option<i64>,
}

#[derive(Debug, Clone, Serialize)]
pub struct EnrollmentRequest {
    pub code: String,
    pub device_uuid: String,
    pub name: String,
    pub hostname: String,
    pub operating_system: String,
    pub agent_version: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DeviceConfiguration {
    pub id: i64,
    pub device_uuid: String,
    pub school_id: i64,
    pub classroom_id: Option<i64>,
    pub name: String,
    pub configuration_version: i64,
}

#[derive(Debug, Clone, Deserialize)]
pub struct EnrollmentData {
    pub token: String,
    pub device: DeviceConfiguration,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ApiEnvelope<T> {
    pub success: bool,
    pub data: Option<T>,
}

#[derive(Debug, Clone, Serialize)]
pub struct HeartbeatRequest {
    pub hostname: String,
    pub operating_system: String,
    pub agent_version: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct HeartbeatData {
    pub configuration: DeviceConfiguration,
}

#[derive(Debug, Clone, Deserialize)]
pub struct RosterStudentDto {
    pub id: i64,
    pub admission_number: String,
    pub full_name: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct RosterResponse {
    pub students: Vec<RosterStudentDto>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SyncSessionPayload {
    pub uuid: String,
    pub admission_number: String,
    pub login_time: String,
    pub logout_time: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SyncSessionsRequestBody {
    pub sessions: Vec<SyncSessionPayload>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SyncResultRow {
    pub uuid: String,
    pub status: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SyncSessionsResponseBody {
    pub results: Vec<SyncResultRow>,
}
