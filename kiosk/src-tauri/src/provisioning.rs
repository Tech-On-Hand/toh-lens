//! Unattended enrollment. A provisioning script drops a small JSON file on the
//! machine (`%ProgramData%\TOH Klas\provisioning.json`); the first time the agent
//! starts without a configuration it reads that file and enrolls itself, so nobody
//! has to type a server address and a code on every computer of a rollout.
//!
//! ```json
//! { "api_base_url": "https://klas.school.example", "enrollment_code": "AB12-CD34", "device_name": "Lab PC 01" }
//! ```
//!
//! `device_name` is optional (the computer's name is used). The file is deleted once
//! enrollment succeeds, and the code inside is single-use anyway.

use serde::Deserialize;
use std::path::PathBuf;

#[derive(Debug, PartialEq, Deserialize)]
pub struct ProvisioningFile {
    pub api_base_url: String,
    pub enrollment_code: String,
    #[serde(default)]
    pub device_name: Option<String>,
}

pub fn file_path() -> Option<PathBuf> {
    std::env::var_os("ProgramData").map(|dir| PathBuf::from(dir).join("TOH Klas").join("provisioning.json"))
}

/// Reads and checks the file. `Ok(None)` when there is none.
pub fn read() -> Result<Option<ProvisioningFile>, String> {
    let Some(path) = file_path().filter(|path| path.exists()) else { return Ok(None) };
    let text = std::fs::read_to_string(&path).map_err(|e| format!("Could not read {}: {e}", path.display()))?;
    parse(&text).map(Some).map_err(|e| format!("{} is not valid: {e}", path.display()))
}

pub fn parse(text: &str) -> Result<ProvisioningFile, String> {
    // A UTF-8 byte-order mark is what Windows PowerShell 5.1 puts at the start of a
    // file written with `Set-Content -Encoding UTF8`.
    let mut file: ProvisioningFile = serde_json::from_str(text.trim_start_matches('\u{feff}')).map_err(|e| e.to_string())?;

    file.api_base_url = file.api_base_url.trim().to_string();
    file.enrollment_code = file.enrollment_code.trim().to_string();
    file.device_name = file.device_name.map(|name| name.trim().to_string()).filter(|name| !name.is_empty());

    if !(file.api_base_url.starts_with("http://") || file.api_base_url.starts_with("https://")) {
        return Err("api_base_url must start with http:// or https://".into());
    }
    if file.enrollment_code.is_empty() {
        return Err("enrollment_code is empty".into());
    }
    Ok(file)
}

pub fn remove() {
    if let Some(path) = file_path() {
        if let Err(error) = std::fs::remove_file(&path) {
            log::warn!("provisioning: enrolled, but could not delete {}: {error}", path.display());
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_complete_file_is_read_and_tidied() {
        let file = parse(r#"{ "api_base_url": " https://klas.example ", "enrollment_code": " ab12-cd34 ", "device_name": " Lab PC 01 " }"#).unwrap();

        assert_eq!(file.api_base_url, "https://klas.example");
        assert_eq!(file.enrollment_code, "ab12-cd34");
        assert_eq!(file.device_name.as_deref(), Some("Lab PC 01"));
    }

    #[test]
    fn the_device_name_is_optional() {
        let file = parse(r#"{ "api_base_url": "http://10.0.0.5:8000", "enrollment_code": "AB12-CD34" }"#).unwrap();
        assert_eq!(file.device_name, None);

        let blank = parse(r#"{ "api_base_url": "http://10.0.0.5:8000", "enrollment_code": "AB12-CD34", "device_name": "  " }"#).unwrap();
        assert_eq!(blank.device_name, None);
    }

    #[test]
    fn a_byte_order_mark_from_windows_powershell_is_tolerated() {
        assert!(parse("\u{feff}{\"api_base_url\": \"https://klas.example\", \"enrollment_code\": \"AB12-CD34\"}").is_ok());
    }

    #[test]
    fn a_file_that_cannot_work_is_refused_with_a_reason() {
        assert!(parse(r#"{ "api_base_url": "klas.example", "enrollment_code": "AB12-CD34" }"#).unwrap_err().contains("http"));
        assert!(parse(r#"{ "api_base_url": "https://klas.example", "enrollment_code": "  " }"#).unwrap_err().contains("enrollment_code"));
        assert!(parse(r#"{ "api_base_url": "https://klas.example" }"#).is_err());
        assert!(parse("not json").is_err());
    }
}
