//! A thin wrapper over Windows' built-in H.264 encoder (a Media Foundation transform)
//! that produces the raw Annex-B bitstream WebRTC needs, not an MP4 file.

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

    /// Ask for a keyframe next: needed when a new viewer connects or quality changes.
    pub fn force_keyframe(&self) -> windows::core::Result<()> {
        unsafe { self.codec.SetValue(&CODECAPI_AVEncVideoForceKeyFrame, &variant_u32(1)) }
    }

    /// Changes the target bitrate while streaming.
    pub fn set_bitrate(&self, bits_per_second: u32) -> windows::core::Result<()> {
        unsafe { self.codec.SetValue(&CODECAPI_AVEncCommonMeanBitRate, &variant_u32(bits_per_second)) }
    }

    /// Encodes one BGRA frame and returns whatever the encoder has ready.
    pub fn encode(&mut self, bgra: &[u8], pitch: usize, timestamp_100ns: i64, duration_100ns: i64) -> windows::core::Result<Vec<EncodedFrame>> {
        bgra_to_nv12(bgra, pitch, self.width, self.height, &mut self.nv12);

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
pub fn nal_types(annex_b: &[u8]) -> Vec<u8> {
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

/// BT.709 limited-range conversion, straightforward on purpose: a real build would
/// do this on the GPU.
fn bgra_to_nv12(bgra: &[u8], pitch: usize, width: usize, height: usize, nv12: &mut [u8]) {
    let (y_plane, uv_plane) = nv12.split_at_mut(width * height);
    for y in 0..height {
        let row = &bgra[y * pitch..y * pitch + width * 4];
        for x in 0..width {
            let (b, g, r) = (row[x * 4] as i32, row[x * 4 + 1] as i32, row[x * 4 + 2] as i32);
            y_plane[y * width + x] = (((47 * r + 157 * g + 16 * b + 128) >> 8) + 16) as u8;
        }
    }
    for y in (0..height).step_by(2) {
        for x in (0..width).step_by(2) {
            let i = y * pitch + x * 4;
            let (b, g, r) = (bgra[i] as i32, bgra[i + 1] as i32, bgra[i + 2] as i32);
            let u = ((-26 * r - 87 * g + 112 * b + 128) >> 8) + 128;
            let v = ((112 * r - 102 * g - 10 * b + 128) >> 8) + 128;
            let out = (y / 2) * width + x;
            uv_plane[out] = u.clamp(0, 255) as u8;
            uv_plane[out + 1] = v.clamp(0, 255) as u8;
        }
    }
}
