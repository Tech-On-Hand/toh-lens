import { useEffect, useState } from "react";
import "./App.css";
import { commandErrorMessage, getConfig, getOpenSession } from "./lib/commands";
import { installKioskGuards } from "./lib/kioskGuards";
import { KeypadScreen } from "./screens/KeypadScreen";
import { LoggedInScreen } from "./screens/LoggedInScreen";
import { SetupScreen } from "./screens/SetupScreen";
import type { LoginSessionRecord } from "./types";

type Screen =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | { kind: "setup" }
  | { kind: "keypad" }
  | { kind: "loggedin"; session: LoginSessionRecord };

async function determineInitialScreen(): Promise<Screen> {
  const config = await getConfig();
  if (!config) return { kind: "setup" };

  const openSession = await getOpenSession();
  if (openSession) return { kind: "loggedin", session: openSession };

  return { kind: "keypad" };
}

function App() {
  const [screen, setScreen] = useState<Screen>({ kind: "loading" });

  useEffect(() => installKioskGuards(), []);

  useEffect(() => {
    determineInitialScreen()
      .then(setScreen)
      .catch((err) => setScreen({ kind: "error", message: commandErrorMessage(err) }));
  }, []);

  if (screen.kind === "loading") {
    return <div className="screen">Loading…</div>;
  }

  if (screen.kind === "error") {
    return (
      <div className="screen">
        <h1>Something went wrong</h1>
        <p>{screen.message}</p>
      </div>
    );
  }

  if (screen.kind === "setup") {
    return <SetupScreen onComplete={() => setScreen({ kind: "keypad" })} />;
  }

  if (screen.kind === "keypad") {
    return <KeypadScreen onLogin={(session) => setScreen({ kind: "loggedin", session })} />;
  }

  return <LoggedInScreen session={screen.session} onLogout={() => setScreen({ kind: "keypad" })} />;
}

export default App;
