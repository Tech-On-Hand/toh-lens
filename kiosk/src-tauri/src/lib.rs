mod api_client;
mod commands;
mod db;
mod models;
mod state;
mod sync;

use state::AppState;
use std::time::Duration;
use tauri::Manager;

const SYNC_INTERVAL: Duration = Duration::from_secs(30);

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .setup(|app| {
            let app_dir = app.path().app_data_dir().expect("failed to resolve app data dir");
            std::fs::create_dir_all(&app_dir).expect("failed to create app data dir");
            let db_path = app_dir.join("toh_lens.sqlite");

            let connection = db::open(&db_path).expect("failed to open local database");
            let state = AppState::new(connection);
            app.manage(state.clone());

            tauri::async_runtime::spawn(async move {
                let mut interval = tokio::time::interval(SYNC_INTERVAL);
                loop {
                    interval.tick().await;
                    sync::try_sync(&state).await;
                }
            });

            Ok(())
        });

    #[cfg(debug_assertions)]
    {
        builder = builder.invoke_handler(tauri::generate_handler![
            commands::get_config,
            commands::save_config,
            commands::get_roster_cache_status,
            commands::refresh_roster,
            commands::validate_admission_number,
            commands::record_login,
            commands::record_logout,
            commands::get_open_session,
            commands::sync_now,
            commands::get_sync_status,
            commands::seed_demo_config,
            commands::seed_demo_roster,
        ]);
    }
    #[cfg(not(debug_assertions))]
    {
        builder = builder.invoke_handler(tauri::generate_handler![
            commands::get_config,
            commands::save_config,
            commands::get_roster_cache_status,
            commands::refresh_roster,
            commands::validate_admission_number,
            commands::record_login,
            commands::record_logout,
            commands::get_open_session,
            commands::sync_now,
            commands::get_sync_status,
        ]);
    }

    builder
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
