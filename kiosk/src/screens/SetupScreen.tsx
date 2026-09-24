import { useState } from "react";
import { commandErrorMessage, enrollDevice, refreshRoster, seedDemoConfig, seedDemoRoster } from "../lib/commands";

interface SetupScreenProps {
  onComplete: () => void;
  // Why automatic enrollment from a provisioning file failed, if it was tried.
  initialError?: string | null;
}

export function SetupScreen({ onComplete, initialError }: SetupScreenProps) {
  const [apiBaseUrl, setApiBaseUrl] = useState("http://127.0.0.1:8000");
  const [enrollmentCode, setEnrollmentCode] = useState("");
  const [deviceName, setDeviceName] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(initialError ?? null);

  const handleSave = async (event: React.FormEvent) => {
    event.preventDefault();
    setIsSaving(true);
    setError(null);

    try {
      await enrollDevice(apiBaseUrl, enrollmentCode, deviceName);
      await refreshRoster();
      onComplete();
    } catch (err) {
      setError(commandErrorMessage(err));
    } finally {
      setIsSaving(false);
    }
  };

  const handleSeedDemoData = async () => {
    setIsSaving(true);
    setError(null);

    try {
      await seedDemoConfig();
      await seedDemoRoster();
      onComplete();
    } catch (err) {
      setError(commandErrorMessage(err));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="screen setup-screen">
      <h1>TOH Klas — Student Agent</h1>
      <p>Enroll this computer using the one-time code supplied by an administrator.</p>

      <form onSubmit={handleSave} className="setup-form">
        <label>
          Backend URL
          <input
            type="url"
            value={apiBaseUrl}
            onChange={(e) => setApiBaseUrl(e.currentTarget.value)}
            placeholder="http://127.0.0.1:8000"
            required
          />
        </label>

        <label>
          Device name
          <input
            value={deviceName}
            onChange={(e) => setDeviceName(e.currentTarget.value)}
            placeholder="Lab PC 01"
            required
          />
        </label>

        <label>
          Enrollment code
          <input
            value={enrollmentCode}
            onChange={(e) => setEnrollmentCode(e.currentTarget.value.toUpperCase())}
            placeholder="AB12-CD34"
            required
          />
        </label>

        {error && <p className="setup-error">{error}</p>}

        <button type="submit" disabled={isSaving}>
          {isSaving ? "Enrolling…" : "Enroll Device"}
        </button>
      </form>

      {import.meta.env.DEV && (
        <button type="button" className="setup-dev-seed" onClick={handleSeedDemoData} disabled={isSaving}>
          Seed demo data (dev only)
        </button>
      )}
    </div>
  );
}
