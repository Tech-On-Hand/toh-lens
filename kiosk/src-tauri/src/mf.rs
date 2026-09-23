//! A thin wrapper over Windows' built-in H.264 encoder (a Media Foundation transform)
//! that produces the raw Annex-B bitstream WebRTC needs, not an MP4 file.
//!
//! Ported from `spikes/native-capture/src/mf.rs`, where this exact code was proven to
//! produce a bitstream that decodes cleanly in a real browser (see spikes/README.md).

use std::mem::ManuallyDrop;

use windows::core::{Interface, GUID};
use windows::Win32::Foundation::VARIANT_BOOL;
use windows::Win32::Media::MediaFoundation::*;
use windows::Win32::System::Com::{CoCreateInstance, CoInitializeEx, CLSCTX_INPROC_SERVER, COINIT_MULTITHREADED};
use windows::Win32::System::Variant::{VARIANT, VARIANT_0, VARIANT_0_0, VARIANT_0_0_0, VT_BOOL, VT_UI4};

/// Microsoft's software H.264 encoder. Hardware encoders are asynchronous transforms
/// and are a separate, later step; this one is always present.
const CLSID_MS_H264_ENCODER: GUID = GUID::from_u128(0x6ca50344_051a_4ded_9779_a43305165e35);

pub struct EncodedFrame {
    pub data: Vec<u8>,
    pub key: bool,
    pub timestamp_100ns: i64,
    pub has_parameter_sets: bool,
}

unsafe impl Send for H264Encoder {}

pub struct H264Encoder {
    transform: IMFTransform,
    codec: ICodecAPI,
    width: usize,
    height: usize,
    output_size: u32,
    provides_samples: bool,
    nv12: Vec<u8>,
}

fn pack(high: u32, low: u32) -> u64 {
    (u64::from(high) << 32) | u64::from(low)
}

fn variant_u32(value: u32) -> VARIANT {
    VARIANT {
        Anonymous: VARIANT_0 {
            Anonymous: ManuallyDrop::new(VARIANT_0_0 {
                vt: VT_UI4,
                wReserved1: 0,
                wReserved2: 0,
                wReserved3: 0,
                Anonymous: VARIANT_0_0_0 { ulVal: value },
            }),
        },
    }
}

fn variant_bool(value: bool) -> VARIANT {
    VARIANT {
        Anonymous: VARIANT_0 {
            Anonymous: ManuallyDrop::new(VARIANT_0_0 {
                vt: VT_BOOL,
                wReserved1: 0,
                wReserved2: 0,
                wReserved3: 0,
                Anonymous: VARIANT_0_0_0 { boolVal: VARIANT_BOOL(if value { -1 } else { 0 }) },
            }),
        },
    }
}

impl H264Encoder {
    pub fn new(width: u32, height: u32, fps: u32, bitrate: u32) -> windows::core::Result<Self> {
        unsafe {
            let _ = CoInitializeEx(None, COINIT_MULTITHREADED);
            MFStartup(MF_VERSION, MFSTARTUP_FULL)?;

            let transform: IMFTransform = CoCreateInstance(&CLSID_MS_H264_ENCODER, None, CLSCTX_INPROC_SERVER)?;

            // Constrained-baseline-style output (no B-frames) is what WebRTC peers expect.
            let output = MFCreateMediaType()?;
            output.SetGUID(&MF_MT_MAJOR_TYPE, &MFMediaType_Video)?;
            output.SetGUID(&MF_MT_SUBTYPE, &MFVideoFormat_H264)?;
            output.SetUINT32(&MF_MT_AVG_BITRATE, bitrate)?;
            output.SetUINT64(&MF_MT_FRAME_SIZE, pack(width, height))?;
            output.SetUINT64(&MF_MT_FRAME_RATE, pack(fps, 1))?;
            output.SetUINT64(&MF_MT_PIXEL_ASPECT_RATIO, pack(1, 1))?;
            output.SetUINT32(&MF_MT_INTERLACE_MODE, MFVideoInterlace_Progressive.0 as u32)?;
            output.SetUINT32(&MF_MT_MPEG2_PROFILE, eAVEncH264VProfile_Base.0 as u32)?;
            transform.SetOutputType(0, &output, 0)?;

            let input = MFCreateMediaType()?;
            input.SetGUID(&MF_MT_MAJOR_TYPE, &MFMediaType_Video)?;
            input.SetGUID(&MF_MT_SUBTYPE, &MFVideoFormat_NV12)?;
            input.SetUINT64(&MF_MT_FRAME_SIZE, pack(width, height))?;
            input.SetUINT64(&MF_MT_FRAME_RATE, pack(fps, 1))?;
            input.SetUINT64(&MF_MT_PIXEL_ASPECT_RATIO, pack(1, 1))?;
            input.SetUINT32(&MF_MT_INTERLACE_MODE, MFVideoInterlace_Progressive.0 as u32)?;
            transform.SetInputType(0, &input, 0)?;

            let codec: ICodecAPI = transform.cast()?;
            codec.SetValue(&CODECAPI_AVLowLatencyMode, &variant_bool(true))?;
            codec.SetValue(&CODECAPI_AVEncCommonRateControlMode, &variant_u32(eAVEncCommonRateControlMode_CBR.0 as u32))?;
            codec.SetValue(&CODECAPI_AVEncCommonMeanBitRate, &variant_u32(bitrate))?;
            codec.SetValue(&CODECAPI_AVEncMPVGOPSize, &variant_u32(fps * 10))?;
            codec.SetValue(&CODECAPI_AVEncMPVDefaultBPictureCount, &variant_u32(0))?;

            transform.ProcessMessage(MFT_MESSAGE_NOTIFY_BEGIN_STREAMING, 0)?;
            transform.ProcessMessage(MFT_MESSAGE_NOTIFY_START_OF_STREAM, 0)?;

            let info = transform.GetOutputStreamInfo(0)?;
            Ok(Self {
                transform,
                codec,
                width: width as usize,
                height: height as usize,
                output_size: info.cbSize,
                provides_samples: info.dwFlags & MFT_OUTPUT_STREAM_PROVIDES_SAMPLES.0 as u32 != 0,
                nv12: vec![0; width as usize * height as usize * 3 / 2],
            })
        }
    }

    /// Forces a keyframe on the next `encode()` call — useful for a viewer that
    /// just joined and has no reference frame yet. Full-view escalation doesn't
    /// use this: Media Foundation doesn't support changing `MF_MT_FRAME_SIZE` on
    /// a live transform, so switching resolution rebuilds the encoder outright
    /// (see `screen_share.rs`), which already starts on a keyframe by construction.
    #[allow(dead_code)] // not yet wired to anything; reserved for a future new-viewer join
    pub fn force_keyframe(&self) -> windows::core::Result<()> {
        unsafe { self.codec.SetValue(&CODECAPI_AVEncVideoForceKeyFrame, &variant_u32(1)) }
    }

    /// Changes the target bitrate while streaming, without a full rebuild —
    /// unlike resolution, Media Foundation does support this live.
    #[allow(dead_code)] // not yet wired to anything; reserved for future adaptive-bitrate tuning
    pub fn set_bitrate(&self, bits_per_second: u32) -> windows::core::Result<()> {
        unsafe { self.codec.SetValue(&CODECAPI_AVEncCommonMeanBitRate, &variant_u32(bits_per_second)) }
    }

    /// Encodes one BGRA frame and returns whatever the encoder has ready. `src_width`/
    /// `src_height` describe the real captured frame (`windows-capture` always hands
    /// over the monitor's native resolution); the encoder downscales to its own
    /// configured output size rather than requiring the caller to do it.
    pub fn encode(&mut self, bgra: &[u8], pitch: usize, src_width: usize, src_height: usize, timestamp_100ns: i64, duration_100ns: i64) -> windows::core::Result<Vec<EncodedFrame>> {
        bgra_to_nv12(bgra, pitch, src_width, src_height, self.width, self.height, &mut self.nv12);

        unsafe {
            let buffer = MFCreateMemoryBuffer(self.nv12.len() as u32)?;
            let mut destination = std::ptr::null_mut();
            buffer.Lock(&mut destination, None, None)?;
            std::ptr::copy_nonoverlapping(self.nv12.as_ptr(), destination, self.nv12.len());
            buffer.Unlock()?;
            buffer.SetCurrentLength(self.nv12.len() as u32)?;

            let sample = MFCreateSample()?;
            sample.AddBuffer(&buffer)?;
            sample.SetSampleTime(timestamp_100ns)?;
            sample.SetSampleDuration(duration_100ns)?;
            self.transform.ProcessInput(0, &sample, 0)?;
        }
        self.drain()
    }

    fn drain(&mut self) -> windows::core::Result<Vec<EncodedFrame>> {
        let mut frames = Vec::new();
        loop {
            unsafe {
                let provided = if self.provides_samples {
                    None
                } else {
                    let sample = MFCreateSample()?;
                    sample.AddBuffer(&MFCreateMemoryBuffer(self.output_size.max(1 << 20))?)?;
                    Some(sample)
                };
                let mut output = MFT_OUTPUT_DATA_BUFFER {
                    dwStreamID: 0,
                    pSample: ManuallyDrop::new(provided),
                    dwStatus: 0,
                    pEvents: ManuallyDrop::new(None),
                };
                let mut status = 0u32;
                let result = self.transform.ProcessOutput(0, std::slice::from_mut(&mut output), &mut status);
                let sample = ManuallyDrop::take(&mut output.pSample);
                let _events = ManuallyDrop::take(&mut output.pEvents);

                match result {
                    Ok(()) => {
                        let Some(sample) = sample else { break };
                        let media = sample.ConvertToContiguousBuffer()?;
                        let (mut data, mut length) = (std::ptr::null_mut(), 0u32);
                        media.Lock(&mut data, None, Some(&mut length))?;
                        let bytes = std::slice::from_raw_parts(data, length as usize).to_vec();
                        media.Unlock()?;

                        let kinds = nal_types(&bytes);
                        frames.push(EncodedFrame {
                            key: kinds.contains(&5),
                            has_parameter_sets: kinds.contains(&7) && kinds.contains(&8),
                            timestamp_100ns: sample.GetSampleTime().unwrap_or(0),
                            data: bytes,
                        });
                    }
                    Err(error) if error.code() == MF_E_TRANSFORM_NEED_MORE_INPUT => break,
                    Err(error) => return Err(error),
                }
            }
        }
        Ok(frames)
    }
}

/// NAL unit types found in an Annex-B buffer (5 = IDR picture, 7 = SPS, 8 = PPS).
fn nal_types(annex_b: &[u8]) -> Vec<u8> {
    let mut kinds = Vec::new();
    let mut i = 0;
    while i + 3 < annex_b.len() {
        if annex_b[i] == 0 && annex_b[i + 1] == 0 && annex_b[i + 2] == 1 {
            kinds.push(annex_b[i + 3] & 0x1f);
            i += 3;
        } else {
            i += 1;
        }
    }
    kinds
}

/// BT.709 limited-range conversion with a box/area downscale from the real
/// captured size to the encoder's target size, straightforward on purpose: a
/// real build would do this on the GPU. Each destination pixel averages the
/// block of source pixels it covers, rather than sampling (or, worse, only
/// ever reading) a single one — `src_width`/`src_height` are almost always
/// larger than `dst_width`/`dst_height` (the monitor's real resolution vs. a
/// thumbnail or capped full-view target).
fn bgra_to_nv12(bgra: &[u8], pitch: usize, src_width: usize, src_height: usize, dst_width: usize, dst_height: usize, nv12: &mut [u8]) {
    let (y_plane, uv_plane) = nv12.split_at_mut(dst_width * dst_height);

    // The source block a destination index covers, along one axis.
    let span = |index: usize, dst: usize, src: usize| -> (usize, usize) {
        let start = index * src / dst;
        let end = ((index + 1) * src / dst).max(start + 1).min(src);
        (start, end)
    };
    let block_avg = |x0: usize, x1: usize, y0: usize, y1: usize| -> (i32, i32, i32) {
        let (mut b, mut g, mut r, mut n) = (0i64, 0i64, 0i64, 0i64);
        for y in y0..y1 {
            let row = &bgra[y * pitch..];
            for x in x0..x1 {
                let i = x * 4;
                b += i64::from(row[i]);
                g += i64::from(row[i + 1]);
                r += i64::from(row[i + 2]);
                n += 1;
            }
        }
        ((b / n) as i32, (g / n) as i32, (r / n) as i32)
    };

    for dy in 0..dst_height {
        let (y0, y1) = span(dy, dst_height, src_height);
        for dx in 0..dst_width {
            let (x0, x1) = span(dx, dst_width, src_width);
            let (b, g, r) = block_avg(x0, x1, y0, y1);
            y_plane[dy * dst_width + dx] = (((47 * r + 157 * g + 16 * b + 128) >> 8) + 16) as u8;
        }
    }

    for cy in 0..dst_height / 2 {
        let (y0, _) = span(2 * cy, dst_height, src_height);
        let (_, y1) = span(2 * cy + 1, dst_height, src_height);
        for cx in 0..dst_width / 2 {
            let (x0, _) = span(2 * cx, dst_width, src_width);
            let (_, x1) = span(2 * cx + 1, dst_width, src_width);
            let (b, g, r) = block_avg(x0, x1, y0, y1);
            let u = ((-26 * r - 87 * g + 112 * b + 128) >> 8) + 128;
            let v = ((112 * r - 102 * g - 10 * b + 128) >> 8) + 128;
            let out = cy * dst_width + cx * 2;
            uv_plane[out] = u.clamp(0, 255) as u8;
            uv_plane[out + 1] = v.clamp(0, 255) as u8;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::bgra_to_nv12;

    fn solid_bgra(width: usize, height: usize, b: u8, g: u8, r: u8) -> Vec<u8> {
        let mut buf = vec![0u8; width * height * 4];
        for px in buf.chunks_mut(4) {
            px[0] = b;
            px[1] = g;
            px[2] = r;
            px[3] = 255;
        }
        buf
    }

    #[test]
    fn downscaling_a_solid_color_frame_stays_that_color() {
        let (src_w, src_h) = (1920, 1080);
        let bgra = solid_bgra(src_w, src_h, 200, 100, 50);
        let (dst_w, dst_h) = (320, 180);
        let mut nv12 = vec![0u8; dst_w * dst_h * 3 / 2];

        bgra_to_nv12(&bgra, src_w * 4, src_w, src_h, dst_w, dst_h, &mut nv12);

        // Every Y sample should match what a single BT.709 conversion of that
        // exact color gives — a solid frame has nothing for the box filter to
        // average away, so any drift means the downscale is broken, not just imprecise.
        let expected_y = (((47 * 50 + 157 * 100 + 16 * 200 + 128) >> 8) + 16) as u8;
        assert!(nv12[..dst_w * dst_h].iter().all(|&y| y == expected_y));
    }

    #[test]
    fn the_whole_source_frame_is_covered_not_just_a_corner() {
        // A crop bug (reading only the top-left dst_w x dst_h source pixels)
        // would completely miss a marker placed in the bottom-right corner.
        let (src_w, src_h) = (400, 300);
        let mut bgra = solid_bgra(src_w, src_h, 0, 0, 0);
        for y in src_h - 10..src_h {
            for x in src_w - 10..src_w {
                let i = (y * src_w + x) * 4;
                bgra[i] = 255; // a bright blue marker only a real downscale would see
            }
        }
        let (dst_w, dst_h) = (40, 30);
        let mut nv12 = vec![0u8; dst_w * dst_h * 3 / 2];

        bgra_to_nv12(&bgra, src_w * 4, src_w, src_h, dst_w, dst_h, &mut nv12);

        let bottom_right_y = nv12[dst_w * dst_h - 1];
        let top_left_y = nv12[0];
        assert_ne!(bottom_right_y, top_left_y, "the marker in the source's corner never reached the output");
    }
}
