const SERVICE: &str = "com.techonhand.toh-klas.student-agent";
const ACCOUNT: &str = "device-api-token";

pub fn save_token(token: &str) -> Result<(), String> {
    keyring::Entry::new(SERVICE, ACCOUNT)
        .map_err(|e| e.to_string())?
        .set_password(token)
        .map_err(|e| e.to_string())
}

pub fn get_token() -> Option<String> {
    keyring::Entry::new(SERVICE, ACCOUNT).ok()?.get_password().ok()
}

pub fn migrate_legacy_token(conn: &rusqlite::Connection) {
    if get_token().is_some() {
        let _ = crate::db::config_repo::delete_api_token(conn);
        return;
    }

    if let Ok(Some(token)) = crate::db::config_repo::get_api_token(conn) {
        if save_token(&token).is_ok() {
            let _ = crate::db::config_repo::delete_api_token(conn);
        }
    }
}
