use native_host::{run, Endpoint};
use std::io;
use std::process::ExitCode;

fn main() -> ExitCode {
    let Ok(endpoint) = Endpoint::load() else {
        return ExitCode::from(1);
    };

    match run(io::stdin(), io::stdout(), &endpoint) {
        Ok(()) => ExitCode::SUCCESS,
        Err(_) => ExitCode::from(1),
    }
}
