import { useState } from "react";
import { commandErrorMessage, refreshRoster, saveConfig, seedDemoConfig, seedDemoRoster } from "../lib/commands";

interface SetupScreenProps {
  onComplete: () => void;
}

export function SetupScreen({ onComplete }: SetupScreenProps) {
  const [apiBaseUrl, setApiBaseUrl] = useState("http://127.0.0.1:8000");
  const [apiToken, setApiToken] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSave = async (event: React.FormEvent) => {
    event.preventDefault();
    setIsSaving(true);
    setError(null);

    try {
      await saveConfig(apiBaseUrl, apiToken);
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
      <h1>TOH Lens — Kiosk Setup</h1>
      <p>Enter this computer's connection details. This only needs to be done once.</p>

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
          Computer API token
          <input
            type="password"
            value={apiToken}
            onChange={(e) => setApiToken(e.currentTarget.value)}
            placeholder="Issued by TOH technical staff"
            required
          />
        </label>

        {error && <p className="setup-error">{error}</p>}

        <button type="submit" disabled={isSaving}>
          {isSaving ? "Connecting…" : "Save & Continue"}
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
