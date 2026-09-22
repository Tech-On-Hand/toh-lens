mod api_client;
mod browser_bridge;
mod commands;
mod credential_store;
mod db;
mod mf;
mod models;
mod policy_sync;
mod screen_share;
mod shell_handoff;
mod state;
mod sync;

use state::AppState;
use std::time::Duration;
use tauri::Manager;

const SYNC_INTERVAL: Duration = Duration::from_secs(30);
const BROWSER_TICK: Duration = Duration::from_secs(3);

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let mut builder = tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .setup(|app| {
            let app_dir = app.path().app_data_dir().expect("failed to resolve app data dir");
            std::fs::create_dir_all(&app_dir).expect("failed to create app data dir");
            let db_path = app_dir.join("toh_lens.sqlite");

            let connection = db::open(&db_path).expect("failed to open local database");
            credential_store::migrate_legacy_token(&connection);
            let state = AppState::new(connection);
            app.manage(state.clone());

            let bridge_state = state.clone();
            tauri::async_runtime::spawn(async move {
                if browser_bridge::start(bridge_state.clone()).await.is_err() {
                    return;
                }
                let mut interval = tokio::time::interval(BROWSER_TICK);
                interval.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
                let mut tick_number = 0u64;
                loop {
                    interval.tick().await;
                    browser_bridge::tick(&bridge_state, tick_number).await;
                    bridge_state.screen_share.tick(&bridge_state).await;
                    tick_number += 1;
                }
            });

            tauri::async_runtime::spawn(async move {
                let mut interval = tokio::time::interval(SYNC_INTERVAL);
                loop {
                    interval.tick().await;
                    sync::send_heartbeat(&state).await;
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
            commands::enroll_device,
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
            commands::enroll_device,
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
