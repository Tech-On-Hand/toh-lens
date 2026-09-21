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

## `native-capture/bin/webrtc_send.rs` + `webrtc.mjs` (works: end-to-end proof)

The question this settles: does the video actually reach the Teacher app? Real WGC capture, real Media Foundation H.264 encoding, sent over a real `webrtc-rs` `RTCPeerConnection` with **no STUN or TURN server configured on either side**, to a real headless Chrome acting as the Teacher app, which is the same connectivity path a WebView2-hosted Teacher app would use. `webrtc.mjs` runs a tiny local HTTP relay to carry SDP/ICE — standing in for what Reverb carries in production — then launches both sides and checks what the browser actually decoded and measured, not just what Rust believes it sent.

```
cargo build --release --bin webrtc-send
node webrtc.mjs
```

Result on the development PC, three consecutive runs, all passing: the two peers connected directly over UDP with **no relay** (`candidatePair: {local: "host", remote: "host"}` — confirmed from the browser's own `RTCPeerConnection.getStats()`, not asserted by either side), zero packets lost, zero decoder freezes, and Chrome's real video pipeline decoded 300+ frames per run of visibly real, non-blank video. Typical run: Rust sent ~358 access units (~125 KB) over 10 s; connection reached the `connected` state within about a second of the offer arriving.

This clears the second-largest risk after decodability itself: on a shared LAN (which is the expected classroom setup, and is what this loopback test approximates), a direct connection negotiates and carries real video with no TURN deployment needed. It says nothing about networks with client isolation or a teacher connecting from off-site — those still fall back to TURN as planned, untested here.

Implementation notes for whoever builds this for real: `TrackLocalStaticSample` accepts our encoder's Annex-B output as-is (the crate's H264 payloader finds NAL start codes itself, no conversion needed); `MediaEngine::register_default_codecs()` negotiated a mutually acceptable H.264 profile with Chrome's offer with no manual codec configuration; the RTCP reader loop on the sender (`rtp_sender.read(...)` in a spawned task) must run or the connection stalls, following the crate's own convention.

## `native-capture/bin/broadcast_send.rs` + `broadcast.mjs` (works, with an important caveat)

The question this settles: when a teacher broadcasts their own screen to the whole class, do we really need "30 encoder sessions," or does `webrtc-rs`'s documented ability to bind one `TrackLocalStaticSample` to many `RTCPeerConnection`s (its own doc comment: "the packets will still be sent to all PeerConnections") actually hold up at a real N? This is the opposite direction from the `webrtc_send` spike above (there, students send to one teacher; here, one teacher sends to many students) and was flagged as the largest remaining risk.

One capture, one Media Foundation encoder, one `write_sample()` call per frame — bound to N real `RTCPeerConnection`s, each negotiated independently (no STUN/TURN), against a real headless Chrome running N independent `RTCPeerConnection`s standing in for N students' machines.

```
cargo build --release --bin broadcast-send
node broadcast.mjs 30
```

Result at N=30, two consecutive runs: **all 30 connected directly (host-to-host, no relay) in about 1.1-1.3 seconds total**, zero packets lost, and the teacher-side process — the one thing that has to scale — used **40-53% of one CPU core** to encode once and fan out to all 30, versus roughly 30 times that for 30 separate encoders (the earlier full-quality single-capture spike measured ~21-25% of a core for one encoder alone). Aggregate measured egress (summed from the 30 receivers' own stats, not asserted by the sender) matched the theoretical `bitrate x 30` almost exactly, which is the correct and unavoidable cost: **N still buys nothing for network egress** — every viewer needs their own copy of the bytes over the wire regardless of encoding architecture. At the tested ~90 kbps/viewer, 30 viewers is a genuine ~2.6 Mbps of sustained upload from the teacher's machine, worth checking against real school upload bandwidth before relying on this for a whole class.

**Caveat that matters:** decode quality at N=30 was noticeably worse than 1:1 (about 130 freezes across the 30 viewers, and each viewer decoding only ~40% of the frames sent) — but a control run at N=5 on the same machine showed **zero freezes** and full frame rate. This points squarely at the test itself, not the design: this spike runs the encoder AND all 30 decoders on one physical machine, sharing one CPU, which never happens in production (encode is on the teacher's PC; each decode is on its own separate student PC). The 1:1 `webrtc_send` spike above already proved a single decode is cheap and clean (zero freezes, zero loss). The freeze count here should not be read as "30 students can't watch a teacher broadcast smoothly" — it hasn't been tested on 30 separate machines, which is what would actually tell you that.

## `screen-capture/` (does not work; kept as a record)

The idea: reuse the agent's WebView2 for `getDisplayMedia` and WebRTC, in a hidden window, so we would get browser encoders and adaptive bitrate for free.

What happened:

- The `--auto-select-desktop-capture-source` switch is honored: Chromium showed its own "is sharing a window" indicator with no picker.
- Then the capture WebView2 stopped responding: the page's timers never fired again and its DevTools endpoint did not answer. A visible window behaved the same as a hidden one, and timers ran fine when capture was not requested.
- Cause not found after several runs, so it was abandoned rather than worked around.

If someone revisits this: try a WebView2 runtime other than the installed one, and try capturing from a separate WebView2 process from the Tauri UI thread.

## What is still unproven for Milestone 4

1. **A real WebView2 as the receiver**, not headless Chrome. They share the same rendering/media engine, so this is a low-risk gap, but it hasn't been run inside the actual Teacher app, and the Teacher app's own launch flags (`--disable-features=WebRtcHideLocalIpsWithMdns`) haven't been exercised together with a live connection.
2. **Networks that block a direct connection** (client isolation, a teacher off-site). The design's TURN fallback is unbuilt and untested — this spike only proves the direct path, which is the expected common case.
3. **Bitrate control under real content.** Re-run the mid-stream bitrate-change check with something as complex as the WebView2 spike's scrolling-text target, since the flat-color test content couldn't stress it (see above).
4. **Thumbnails vs full view.** Plan: one connection per student, with the encoder switched between a small, low-frame-rate profile and a larger one on request, using the same `force_keyframe`/`set_bitrate` calls proven above. Not yet measured.
5. **Real per-machine capacity for the teacher-broadcast direction.** The one-encode-N-sends mechanism and its CPU/bandwidth cost on the sending side are now proven (see below). What's still open is decode quality on ~30 *separate* real student machines at once, and whether typical school upload bandwidth (a few Mbps of sustained egress from the teacher's machine) is actually available.
6. **Capturing a real monitor**, the wireless-display and multi-monitor cases, and that the secure desktop (UAC, lock screen) cannot be captured.
7. **The capture border and privacy indicator.** WGC can draw a yellow border on some Windows builds; we still need our own always-visible "your screen may be viewed" indicator either way.
8. **Hardware vs software encoding**, and behavior on older/weaker school hardware — not measured on this development PC.
