use tauri::{WebviewUrl, WebviewWindowBuilder};

// Every window shares one WebView2 environment, so they must all use the same
// browser arguments. `auto-select` answers the screen-share picker without a
// person; `WebRtcHideLocalIpsWithMdns` off lets peers on a LAN see real addresses.
const BASE_ARGS: &str = "--auto-select-desktop-capture-source=TOHKLAS-SPIKE-TARGET --disable-features=WebRtcHideLocalIpsWithMdns";

// Chromium slows or freezes pages it believes nobody can see. A capture window must
// keep running, so these switch that off.
const ANTI_THROTTLE: &str = "--disable-background-timer-throttling --disable-renderer-backgrounding --disable-backgrounding-occluded-windows";

fn main() {
    let port = std::env::var("SPIKE_PORT").expect("SPIKE_PORT must be set");
    let visible = std::env::var("SPIKE_VISIBLE").is_ok_and(|v| v == "1");
    let skip = std::env::var("SPIKE_SKIP_CAPTURE").is_ok_and(|v| v == "1");
    let anti_throttle = std::env::var("SPIKE_ANTITHROTTLE").is_ok_and(|v| v == "1");

    // A second `--disable-features` would override the first, so merge them.
    let mut args = BASE_ARGS.to_string();
    if anti_throttle {
        args = args.replace("WebRtcHideLocalIpsWithMdns", "WebRtcHideLocalIpsWithMdns,CalculateNativeWinOcclusion");
        args.push(' ');
        args.push_str(ANTI_THROTTLE);
    }

    if let Ok(debug_port) = std::env::var("SPIKE_DEBUG_PORT") {
        args.push_str(&format!(" --remote-debugging-port={debug_port}"));
    }

    tauri::Builder::default()
        .setup(move |app| {
            // The thing being captured: a visible window with motion and small text.
            WebviewWindowBuilder::new(app, "target", WebviewUrl::App("target.html".into()))
                .title("TOHKLAS-SPIKE-TARGET")
                .inner_size(320.0, 180.0)
                .position(60.0, 60.0)
                .focused(false)
                .additional_browser_args(&args)
                .build()?;

            // The capturer: never shown, like the Student Agent's window after login.
            WebviewWindowBuilder::new(app, "capture", WebviewUrl::App(format!("capture.html?port={port}{}", if skip { "&skip=1" } else { "" }).into()))
                .title("capture")
                .visible(visible)
                .additional_browser_args(&args)
                .build()?;
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running the spike");
}
