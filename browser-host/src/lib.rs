//! Native messaging host for the TOH Klas browser extension.
//!
//! Chrome/Edge start this process and speak length-prefixed JSON over stdio.
//! The host does no work of its own: it authenticates to the Student Agent's
//! loopback bridge with the secret the agent published, then relays messages in
//! both directions. Nothing but framed messages may ever be written to stdout.

use serde_json::Value;
use std::io::{self, BufRead, BufReader, Read, Write};
use std::net::{Shutdown, TcpStream};
use std::path::PathBuf;
use std::thread;

/// Chrome caps host-to-browser messages at 1 MiB; the agent's line limit matches.
pub const MAX_MESSAGE_BYTES: usize = 1024 * 1024;

pub struct Endpoint {
    pub port: u16,
    pub token: String,
}

impl Endpoint {
    pub fn from_json(text: &str) -> io::Result<Self> {
        let invalid = || io::Error::new(io::ErrorKind::InvalidData, "invalid bridge file");
        let value: Value = serde_json::from_str(text).map_err(|_| invalid())?;
        Ok(Self {
            port: value["port"].as_u64().and_then(|p| u16::try_from(p).ok()).ok_or_else(invalid)?,
            token: value["token"].as_str().ok_or_else(invalid)?.to_string(),
        })
    }

    pub fn bridge_file() -> PathBuf {
        let dir = std::env::var_os("TOH_KLAS_BRIDGE_DIR")
            .map(PathBuf::from)
            .unwrap_or_else(|| {
                std::env::var_os("LOCALAPPDATA")
                    .map(PathBuf::from)
                    .unwrap_or_else(std::env::temp_dir)
                    .join("TOH Klas")
            });
        dir.join("bridge.json")
    }

    pub fn load() -> io::Result<Self> {
        Self::from_json(&std::fs::read_to_string(Self::bridge_file())?)
    }
}

/// Reads one native messaging frame: a 4-byte little-endian length, then that
/// many bytes of UTF-8 JSON. `Ok(None)` means the browser closed the pipe.
pub fn read_frame<R: Read>(input: &mut R) -> io::Result<Option<Value>> {
    let mut length = [0u8; 4];
    let mut filled = 0;
    while filled < length.len() {
        match input.read(&mut length[filled..])? {
            0 if filled == 0 => return Ok(None),
            0 => return Err(io::ErrorKind::UnexpectedEof.into()),
            read => filled += read,
        }
    }

    let length = u32::from_le_bytes(length) as usize;
    if length == 0 || length > MAX_MESSAGE_BYTES {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "message size out of range"));
    }

    let mut body = vec![0u8; length];
    input.read_exact(&mut body)?;
    serde_json::from_slice(&body)
        .map(Some)
        .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, e))
}

pub fn write_frame<W: Write>(output: &mut W, message: &Value) -> io::Result<()> {
    let body = serde_json::to_vec(message)?;
    if body.len() > MAX_MESSAGE_BYTES {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "message too large"));
    }
    output.write_all(&(body.len() as u32).to_le_bytes())?;
    output.write_all(&body)?;
    output.flush()
}

/// Relays until either side closes. Messages are re-serialized compactly, so an
/// embedded newline can never split one message into two agent lines.
pub fn run<R, W>(input: R, mut output: W, endpoint: &Endpoint) -> io::Result<()>
where
    R: Read + Send + 'static,
    W: Write,
{
    let mut socket = TcpStream::connect(("127.0.0.1", endpoint.port))?;
    let auth = serde_json::json!({ "type": "bridge.auth", "token": endpoint.token });
    socket.write_all(format!("{auth}\n").as_bytes())?;

    let mut upstream_socket = socket.try_clone()?;
    let closer = socket.try_clone()?;
    thread::spawn(move || {
        let mut input = input;
        while let Ok(Some(message)) = read_frame(&mut input) {
            let mut line = message.to_string();
            line.push('\n');
            if upstream_socket.write_all(line.as_bytes()).is_err() {
                break;
            }
        }
        let _ = closer.shutdown(Shutdown::Both);
    });

    for line in BufReader::new(socket).lines() {
        let Ok(line) = line else { break };
        if line.len() > MAX_MESSAGE_BYTES {
            break;
        }
        if let Ok(message) = serde_json::from_str::<Value>(&line) {
            if write_frame(&mut output, &message).is_err() {
                break;
            }
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    use std::io::Cursor;
    use std::net::TcpListener;
    use std::sync::mpsc;

    fn frame(message: &Value) -> Vec<u8> {
        let mut bytes = Vec::new();
        write_frame(&mut bytes, message).unwrap();
        bytes
    }

    #[test]
    fn frames_round_trip_with_a_little_endian_length_prefix() {
        let message = json!({ "type": "browser.hello", "payload": { "browser": "chrome" } });
        let bytes = frame(&message);
        let body = serde_json::to_vec(&message).unwrap();

        assert_eq!(&bytes[..4], &(body.len() as u32).to_le_bytes());
        assert_eq!(read_frame(&mut Cursor::new(bytes)).unwrap(), Some(message));
    }

    #[test]
    fn a_closed_pipe_is_a_clean_end_but_a_truncated_frame_is_an_error() {
        assert_eq!(read_frame(&mut Cursor::new(Vec::new())).unwrap(), None);
        assert!(read_frame(&mut Cursor::new(vec![9, 0])).is_err());
        assert!(read_frame(&mut Cursor::new(vec![9, 0, 0, 0, b'{'])).is_err());
    }

    #[test]
    fn oversized_empty_and_malformed_frames_are_rejected() {
        let too_big = (MAX_MESSAGE_BYTES as u32 + 1).to_le_bytes().to_vec();
        assert!(read_frame(&mut Cursor::new(too_big)).is_err());
        assert!(read_frame(&mut Cursor::new(0u32.to_le_bytes().to_vec())).is_err());

        let mut garbage = 3u32.to_le_bytes().to_vec();
        garbage.extend_from_slice(b"{{{");
        assert!(read_frame(&mut Cursor::new(garbage)).is_err());
    }

    #[test]
    fn writing_an_oversized_message_fails_instead_of_corrupting_the_stream() {
        let huge = json!({ "blob": "x".repeat(MAX_MESSAGE_BYTES) });
        assert!(write_frame(&mut Vec::new(), &huge).is_err());
    }

    #[test]
    fn the_bridge_file_is_parsed_strictly() {
        let endpoint = Endpoint::from_json(r#"{"port":4711,"token":"abc","pid":1}"#).unwrap();
        assert_eq!((endpoint.port, endpoint.token.as_str()), (4711, "abc"));
        assert!(Endpoint::from_json(r#"{"port":70000,"token":"abc"}"#).is_err());
        assert!(Endpoint::from_json(r#"{"port":1}"#).is_err());
        assert!(Endpoint::from_json("nope").is_err());
    }

    /// Wraps a channel so the test can feed browser-side bytes to `run` and
    /// close the pipe by dropping the sender.
    struct ChannelReader {
        receiver: mpsc::Receiver<Vec<u8>>,
        pending: Vec<u8>,
    }

    impl Read for ChannelReader {
        fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
            if self.pending.is_empty() {
                match self.receiver.recv() {
                    Ok(bytes) => self.pending = bytes,
                    Err(_) => return Ok(0),
                }
            }
            let count = buffer.len().min(self.pending.len());
            buffer[..count].copy_from_slice(&self.pending[..count]);
            self.pending.drain(..count);
            Ok(count)
        }
    }

    struct SharedWriter(std::sync::Arc<std::sync::Mutex<Vec<u8>>>);

    impl Write for SharedWriter {
        fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
            self.0.lock().unwrap().extend_from_slice(bytes);
            Ok(bytes.len())
        }

        fn flush(&mut self) -> io::Result<()> {
            Ok(())
        }
    }

    #[test]
    fn the_host_authenticates_then_relays_in_both_directions() {
        let listener = TcpListener::bind(("127.0.0.1", 0)).unwrap();
        let endpoint = Endpoint { port: listener.local_addr().unwrap().port(), token: "secret".into() };

        let agent = thread::spawn(move || {
            let (stream, _) = listener.accept().unwrap();
            let mut writer = stream.try_clone().unwrap();
            let mut lines = BufReader::new(stream).lines();

            let auth: Value = serde_json::from_str(&lines.next().unwrap().unwrap()).unwrap();
            assert_eq!(auth, json!({ "type": "bridge.auth", "token": "secret" }));

            let from_browser: Value = serde_json::from_str(&lines.next().unwrap().unwrap()).unwrap();
            assert_eq!(from_browser["type"], "browser.hello");

            let command = json!({ "version": 1, "id": "c1", "type": "browser.open_url", "payload": { "url": "https://example.com" } });
            writer.write_all(format!("{command}\n").as_bytes()).unwrap();
            writer.write_all(b"not json, must be skipped\n").unwrap();
        });

        let (sender, receiver) = mpsc::channel();
        let captured = std::sync::Arc::new(std::sync::Mutex::new(Vec::new()));
        sender.send(frame(&json!({ "type": "browser.hello", "payload": { "browser": "chrome" } }))).unwrap();

        run(ChannelReader { receiver, pending: Vec::new() }, SharedWriter(captured.clone()), &endpoint).unwrap();
        agent.join().unwrap();
        drop(sender);

        let output = captured.lock().unwrap().clone();
        let relayed = read_frame(&mut Cursor::new(output.clone())).unwrap().unwrap();
        assert_eq!(relayed["type"], "browser.open_url");
        assert_eq!(relayed["payload"]["url"], "https://example.com");
        let first_len = u32::from_le_bytes(output[..4].try_into().unwrap()) as usize;
        assert_eq!(output.len(), 4 + first_len, "the garbage line must not produce a frame");
    }

    #[test]
    fn the_host_exits_when_the_agent_is_not_listening() {
        let listener = TcpListener::bind(("127.0.0.1", 0)).unwrap();
        let port = listener.local_addr().unwrap().port();
        drop(listener);

        let endpoint = Endpoint { port, token: "x".into() };
        assert!(run(Cursor::new(Vec::new()), Vec::new(), &endpoint).is_err());
    }
}
