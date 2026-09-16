import { useState } from "react";
import { NumericKeypad } from "../components/NumericKeypad";
import { SyncStatusBadge } from "../components/SyncStatusBadge";
import { commandErrorMessage, recordLogin } from "../lib/commands";
import type { LoginSessionRecord } from "../types";

interface KeypadScreenProps {
  onLogin: (session: LoginSessionRecord) => void;
}

export function KeypadScreen({ onLogin }: KeypadScreenProps) {
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleChange = (next: string) => {
    setError(null);
    setValue(next);
  };

  const handleSubmit = async () => {
    if (value.length === 0 || isSubmitting) return;

    setIsSubmitting(true);
    try {
      const session = await recordLogin(value);
      onLogin(session);
    } catch (err) {
      setError(commandErrorMessage(err));
      setValue("");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="screen keypad-screen">
      <SyncStatusBadge />

      <h1>Enter your admission number</h1>

      <NumericKeypad value={value} onChange={handleChange} onSubmit={handleSubmit} />

      <div className={`keypad-message ${error ? "keypad-message--error" : ""}`} aria-live="polite">
        {error ?? " "}
      </div>
    </div>
  );
}
