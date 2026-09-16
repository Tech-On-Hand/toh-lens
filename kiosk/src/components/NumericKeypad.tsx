interface NumericKeypadProps {
  value: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  maxLength?: number;
}

const DIGIT_ROWS = [
  ["1", "2", "3"],
  ["4", "5", "6"],
  ["7", "8", "9"],
];

export function NumericKeypad({ value, onChange, onSubmit, maxLength = 12 }: NumericKeypadProps) {
  const appendDigit = (digit: string) => {
    if (value.length >= maxLength) return;
    onChange(value + digit);
  };

  const backspace = () => onChange(value.slice(0, -1));

  return (
    <div className="numeric-keypad">
      <div className="numeric-keypad-display" aria-live="polite">
        {value.length > 0 ? value : " "}
      </div>

      <div className="numeric-keypad-grid">
        {DIGIT_ROWS.flat().map((digit) => (
          <button
            key={digit}
            type="button"
            className="numeric-keypad-key"
            onClick={() => appendDigit(digit)}
          >
            {digit}
          </button>
        ))}

        <button type="button" className="numeric-keypad-key numeric-keypad-key--wide" onClick={backspace}>
          ⌫
        </button>
        <button type="button" className="numeric-keypad-key" onClick={() => appendDigit("0")}>
          0
        </button>
        <button
          type="button"
          className="numeric-keypad-key numeric-keypad-key--wide numeric-keypad-key--enter"
          onClick={onSubmit}
          disabled={value.length === 0}
        >
          ✓
        </button>
      </div>
    </div>
  );
}
