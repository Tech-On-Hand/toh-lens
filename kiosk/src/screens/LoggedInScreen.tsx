import { useState } from "react";
import { SyncStatusBadge } from "../components/SyncStatusBadge";
import { commandErrorMessage, recordLogout } from "../lib/commands";
import type { LoginSessionRecord } from "../types";

interface LoggedInScreenProps {
  session: LoginSessionRecord;
  onLogout: () => void;
}

export function LoggedInScreen({ session, onLogout }: LoggedInScreenProps) {
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleLogout = async () => {
    setIsLoggingOut(true);
    setError(null);

    try {
      await recordLogout(session.session_uuid);
      onLogout();
    } catch (err) {
      setError(commandErrorMessage(err));
      setIsLoggingOut(false);
    }
  };

  return (
    <div className="screen loggedin-screen">
      <SyncStatusBadge />

      <h1>Welcome, {session.full_name}</h1>
      <p>You're logged in on this computer. Have a great lesson!</p>

      {error && <p className="setup-error">{error}</p>}

      <button type="button" className="logout-button" onClick={handleLogout} disabled={isLoggingOut}>
        {isLoggingOut ? "Logging out…" : "Log Out"}
      </button>
    </div>
  );
}
