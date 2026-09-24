import { useEffect, useState } from "react";
import "./App.css";
import { AnnouncementOverlay } from "./components/AnnouncementOverlay";
import { BroadcastViewer } from "./components/BroadcastViewer";
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

  // Mounted once here, not inside each screen: a broadcast can be running
  // whether or not a student is logged in, and it must survive keypad <->
  // logged-in transitions rather than tearing down its connection on every one.
  return (
    <>
      {screen.kind === "keypad" ? (
        <KeypadScreen onLogin={(session) => setScreen({ kind: "loggedin", session })} />
      ) : (
        <LoggedInScreen session={screen.session} onLogout={() => setScreen({ kind: "keypad" })} />
      )}
      <BroadcastViewer />
      <AnnouncementOverlay />
    </>
  );
}

export default App;
