# Milestone 4 spikes: screen capture

Throwaway experiments that decide how the Student Agent captures and sends the screen. Neither is product code.

## Verdict

**Capture natively from Rust with Windows Graphics Capture (WGC); do not use a browser (WebView2) for it.**

## `native-capture/` (works)

Creates its own window with a known animated pattern, captures it with WGC through the `windows-capture` crate, and checks every frame's pixels. Optionally encodes the frames to H.264 through Windows' built-in Media Foundation encoder.

`cargo build --release` then `target/release/native-capture-spike --ghost [--encode]`. Nothing visible appears (the test window is on screen but 1/255 opaque and click-through), it never captures your desktop, and it exits by itself (60 s watchdog).

Results on the development PC (release build, 5 s per size):

| | 1280x720 | 1920x1080 |
|---|---|---|
| Frames delivered | ~35 fps (limited by the test window's 64 Hz timer) | ~38 fps |
| Frames with correct pixels and size | 100% | 100% |
| Copy of a frame to CPU memory | ~1 ms | ~1-2 ms |
| Whole process CPU, capture only | 11% of one core | 16% |
| Whole process CPU, capture + H.264 at 1.5 Mbps / 30 fps | 21% | 25% |
| Encoded output | 1.36 Mbps, valid MP4 | 1.40 Mbps, valid MP4 |

The CPU figures include the test window's own drawing, so they overstate capture cost. Encoding adds roughly 10 points of one core in both cases. Not measured: whether the encoder ran on the GPU or the CPU, any older or weaker school hardware, and capturing a real monitor.

Findings that shape the design:

- WGC only delivers a frame when the window's content changes, so an idle screen costs almost nothing.
- **A window parked far off-screen is not updated by Windows** and yields one frame. Only capture things that are actually on screen (the student's monitor, or a real app window).
- Capturing works without any window being visible to a person.

## `screen-capture/` (does not work; kept as a record)

The idea: reuse the agent's WebView2 for `getDisplayMedia` and WebRTC, in a hidden window, so we would get browser encoders and adaptive bitrate for free.

What happened:

- The `--auto-select-desktop-capture-source` switch is honored: Chromium showed its own "is sharing a window" indicator with no picker.
- Then the capture WebView2 stopped responding: the page's timers never fired again and its DevTools endpoint did not answer. A visible window behaved the same as a hidden one, and timers ran fine when capture was not requested.
- Cause not found after several runs, so it was abandoned rather than worked around.

If someone revisits this: try a WebView2 runtime other than the installed one, and try capturing from a separate WebView2 process from the Tauri UI thread.

## What is still unproven for Milestone 4

1. **Raw H.264 for streaming.** The Media Foundation path used here writes MP4 files. WebRTC needs raw H.264 NAL units, so the next spike is driving the encoder directly and reading its output.
2. **WebRTC from Rust to the Teacher app.** `webrtc-rs` sending H.264 to a WebView2 `RTCPeerConnection` on a LAN, direct with no TURN. Chromium hides local IPs behind mDNS by default; the Teacher app's WebView2 can turn that off (`--disable-features=WebRtcHideLocalIpsWithMdns`), which the browser spike showed is accepted as a launch argument.
3. **Thumbnails vs full view.** Plan: one connection per student, with the encoder switched between a small, low-frame-rate profile and a larger one on request. Not yet measured.
4. **Teacher broadcast to a whole class.** One teacher PC encoding for ~30 students is 30 encoder sessions unless we send one stream a different way. This is the largest performance risk.
5. **Capturing a real monitor**, the wireless-display and multi-monitor cases, and that the secure desktop (UAC, lock screen) cannot be captured.
6. **The capture border and privacy indicator.** WGC can draw a yellow border on some Windows builds; we still need our own always-visible "your screen may be viewed" indicator either way.
