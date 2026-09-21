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

## `native-capture/ --raw` + `verify_decode.mjs` (works: the key risk is cleared)

The question this settles: does Windows' own H.264 encoder produce a bitstream a real browser can actually decode, driven directly from Rust with no file container in between? This is the piece the rest of Milestone 4 (WebRTC, thumbnails, broadcast) depends on.

`src/mf.rs` drives the Media Foundation H.264 encoder MFT directly (`CoCreateInstance` on the built-in encoder, NV12 in, Annex-B H.264 out — the same encoder `--encode` uses internally, but reading its raw output instead of letting it write an MP4). `--raw` captures a window with WGC, encodes each frame, and writes the access units to a small length-prefixed file. It also exercises the two controls adaptive quality needs: it requests a keyframe mid-stream and changes the target bitrate mid-stream. `verify_decode.mjs` then feeds that file into a real headless Chrome's `VideoDecoder` (WebCodecs) — the same H.264 decoder a WebView2-based Teacher app would use — with no WebRTC or RTP involved, and checks every access unit decodes with no errors.

```
cargo build --release
target/release/native-capture-spike --raw
node verify_decode.mjs %TEMP%/toh-klas-raw-spike-1280x720.h264frames
```

Result on the development PC: **all 250 access units decoded with zero errors**, at the requested 1280x720, using a hardware-accelerated decoder (`avc1.42c01f`, `hardwareSupported: true`), and the final decoded frame was real video content, not a blank or corrupt image (checked by sampling its pixels, same as the receiver checks elsewhere in this repo). Decoding all 250 frames took 602 ms in total — decoding is not the bottleneck.

Requesting a keyframe mid-stream worked: one appeared 307 ms after the request, well within the sub-second budget a "quality just changed" or "new viewer joined" event needs.

Changing the bitrate mid-stream was inconclusive, not failing: measured output stayed near 100-120 kbps regardless of whether the target was 1.5 Mbps or 300 kbps. The test content (a solid-color block moving over a flat background) is so simple to compress that the encoder likely never needed to approach either target, so this run cannot tell bitrate control apart from "didn't need the bits." It needs richer content (real text and motion, like the WebView2 spike's target page) to mean anything, and is why this is listed as still open below rather than resolved.

Also observed: many more keyframes appeared than the configured GOP size implied (10 in ~7 s), suggesting the H.264 MFT does not fully honor `CODECAPI_AVEncMPVGOPSize` (a name inherited from the MPEG-2 encoder). Worth another look before relying on GOP length for bandwidth planning, but it does not affect decodability.

## `screen-capture/` (does not work; kept as a record)

The idea: reuse the agent's WebView2 for `getDisplayMedia` and WebRTC, in a hidden window, so we would get browser encoders and adaptive bitrate for free.

What happened:

- The `--auto-select-desktop-capture-source` switch is honored: Chromium showed its own "is sharing a window" indicator with no picker.
- Then the capture WebView2 stopped responding: the page's timers never fired again and its DevTools endpoint did not answer. A visible window behaved the same as a hidden one, and timers ran fine when capture was not requested.
- Cause not found after several runs, so it was abandoned rather than worked around.

If someone revisits this: try a WebView2 runtime other than the installed one, and try capturing from a separate WebView2 process from the Tauri UI thread.

## What is still unproven for Milestone 4

1. **WebRTC from Rust to the Teacher app.** `webrtc-rs` sending this H.264 stream to a WebView2 `RTCPeerConnection` over RTP, on a LAN, direct with no TURN. Chromium hides local IPs behind mDNS by default; the Teacher app's WebView2 can turn that off (`--disable-features=WebRtcHideLocalIpsWithMdns`), which the browser spike showed is accepted as a launch argument. RTP also fragments large keyframes across packets (FU-A), which the raw-file test here didn't need to do.
2. **Bitrate control under real content.** Re-run the mid-stream bitrate-change check with something as complex as the WebView2 spike's scrolling-text target, since the flat-color test content couldn't stress it (see above).
3. **Thumbnails vs full view.** Plan: one connection per student, with the encoder switched between a small, low-frame-rate profile and a larger one on request. Not yet measured.
4. **Teacher broadcast to a whole class.** One teacher PC encoding for ~30 students is 30 encoder sessions unless we send one stream a different way. This is the largest performance risk.
5. **Capturing a real monitor**, the wireless-display and multi-monitor cases, and that the secure desktop (UAC, lock screen) cannot be captured.
6. **The capture border and privacy indicator.** WGC can draw a yellow border on some Windows builds; we still need our own always-visible "your screen may be viewed" indicator either way.
7. **Hardware vs software encoding**, and behavior on older/weaker school hardware — not measured on this development PC.
