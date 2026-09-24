import { useState } from "react";
import { HelpButton } from "../components/HelpButton";
import { NumericKeypad } from "../components/NumericKeypad";
import { RosterFreshnessNote } from "../components/RosterFreshnessNote";
import { ScreenWatchIndicator } from "../components/ScreenWatchIndicator";
import { SyncStatusBadge } from "../components/SyncStatusBadge";
import { commandErrorMessage, recordLogin } from "../lib/commands";
import type { LoginSessionRecord } from "../types";

interface KeypadScreenProps {
  onLogin: (session: LoginSessionRecord) => void;
  /** Why the keypad is showing instead of a lesson already in progress (a restart or a sleep-forced sign-out), if there is a reason to give. */
  notice?: string;
}

export function KeypadScreen({ onLogin, notice }: KeypadScreenProps) {
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
      <ScreenWatchIndicator />
      <SyncStatusBadge />

      <h1>Enter your admission number</h1>

      <NumericKeypad value={value} onChange={handleChange} onSubmit={handleSubmit} />

      <div className={`keypad-message ${error ? "keypad-message--error" : notice ? "keypad-message--notice" : ""}`} aria-live="polite">
        {error ?? notice ?? " "}
      </div>

      <RosterFreshnessNote />
      <HelpButton />
    </div>
  );
}
