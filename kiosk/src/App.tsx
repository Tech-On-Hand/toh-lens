import { useEffect, useState } from "react";
import "./App.css";
import { AnnouncementOverlay } from "./components/AnnouncementOverlay";
import { BroadcastViewer } from "./components/BroadcastViewer";
import { commandErrorMessage, getConfig, reclaimStaleSession, refreshRoster, tryAutoEnroll } from "./lib/commands";
import { installKioskGuards } from "./lib/kioskGuards";
import { KeypadScreen } from "./screens/KeypadScreen";
import { LoggedInScreen } from "./screens/LoggedInScreen";
import { SetupScreen } from "./screens/SetupScreen";
import type { LoginSessionRecord } from "./types";

type Screen =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | { kind: "setup"; error?: string | null }
  | { kind: "keypad"; notice?: string }
  | { kind: "loggedin"; session: LoginSessionRecord };

const RESTART_NOTICE = "This computer restarted, or the last session wasn't signed out. Please sign in again.";

async function determineInitialScreen(): Promise<Screen> {
  let config = await getConfig();
  let setupError: string | null = null;

  if (!config) {
    // A rollout script may have left a provisioning file: enroll from it before
    // asking anyone to type a server address and a code.
    try {
      config = await tryAutoEnroll();
      if (config) await refreshRoster().catch(() => {});
    } catch (err) {
      setupError = commandErrorMessage(err);
    }
  }
  if (!config) return { kind: "setup", error: setupError };

  // FR-1 identity risk: a crash, forced power-off, restart, or waking from
  // sleep all skip the Log Out click, so never resume straight into whatever
  // session was left open — a different student may now be at the keyboard.
  // Always land on the keypad; only say why when there was something to reclaim.
  const reclaimed = await reclaimStaleSession().catch(() => false);
  return { kind: "keypad", notice: reclaimed ? RESTART_NOTICE : undefined };
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
    return <SetupScreen initialError={screen.error} onComplete={() => setScreen({ kind: "keypad" })} />;
  }

  // Mounted once here, not inside each screen: a broadcast can be running
  // whether or not a student is logged in, and it must survive keypad <->
  // logged-in transitions rather than tearing down its connection on every one.
  return (
    <>
      {screen.kind === "keypad" ? (
        <KeypadScreen notice={screen.notice} onLogin={(session) => setScreen({ kind: "loggedin", session })} />
      ) : (
        <LoggedInScreen session={screen.session} onLogout={(notice) => setScreen({ kind: "keypad", notice })} />
      )}
      <BroadcastViewer />
      <AnnouncementOverlay />
    </>
  );
}

export default App;
