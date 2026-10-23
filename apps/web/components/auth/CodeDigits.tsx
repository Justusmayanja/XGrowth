"use client";

import { ClipboardEvent, KeyboardEvent, useRef, useState } from "react";

const LENGTH = 6;

type Props = {
  onChange?: (code: string) => void;
  onComplete: (code: string) => void;
  disabled?: boolean;
};

export function CodeDigits({ onChange, onComplete, disabled = false }: Props) {
  const [digits, setDigits] = useState<string[]>(() => Array(LENGTH).fill(""));
  const digitsRef = useRef(digits);
  const inputs = useRef<Array<HTMLInputElement | null>>([]);

  function commit(next: string[], focusIndex: number | null) {
    digitsRef.current = next;
    setDigits(next);
    const code = next.join("");
    onChange?.(code);
    if (next.every((digit) => digit !== "")) {
      onComplete(code);
      return;
    }
    if (focusIndex === null) return;
    requestAnimationFrame(() => {
      const field = inputs.current[focusIndex];
      field?.focus();
      field?.select();
    });
  }

  function writeFrom(index: number, text: string) {
    const incoming = text.replace(/\D/g, "");
    if (!incoming) {
      commit([...digitsRef.current], index);
      return;
    }
    const next = [...digitsRef.current];
    let cursor = index;
    for (const char of incoming) {
      if (cursor >= LENGTH) break;
      next[cursor] = char;
      cursor += 1;
    }
    const complete = next.every((digit) => digit !== "");
    commit(next, complete ? null : Math.min(cursor, LENGTH - 1));
  }

  function onKeyDown(index: number, event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Backspace") {
      event.preventDefault();
      const next = [...digitsRef.current];
      if (next[index]) {
        next[index] = "";
        commit(next, index);
        return;
      }
      if (index === 0) return;
      next[index - 1] = "";
      commit(next, index - 1);
      return;
    }

    if (event.key === "Delete") {
      event.preventDefault();
      const next = [...digitsRef.current];
      next[index] = "";
      commit(next, index);
      return;
    }

    if (event.key === "ArrowLeft" && index > 0) {
      event.preventDefault();
      inputs.current[index - 1]?.focus();
    }

    if (event.key === "ArrowRight" && index < LENGTH - 1) {
      event.preventDefault();
      inputs.current[index + 1]?.focus();
    }
  }

  function onPaste(index: number, event: ClipboardEvent<HTMLInputElement>) {
    const text = event.clipboardData.getData("text");
    if (!/\d/.test(text)) return;
    event.preventDefault();
    writeFrom(index, text);
  }

  return (
    <div className="code-digits" role="group" aria-labelledby="code-label">
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={(node) => {
            inputs.current[index] = node;
          }}
          id={index === 0 ? "code-digit-0" : undefined}
          className="code-digit"
          inputMode="numeric"
          autoComplete={index === 0 ? "one-time-code" : "off"}
          autoCorrect="off"
          autoCapitalize="off"
          spellCheck={false}
          aria-label={`Digit ${index + 1} of ${LENGTH}`}
          value={digit}
          disabled={disabled}
          autoFocus={index === 0}
          onChange={(event) => writeFrom(index, event.target.value)}
          onKeyDown={(event) => onKeyDown(index, event)}
          onPaste={(event) => onPaste(index, event)}
          onFocus={(event) => event.currentTarget.select()}
        />
      ))}
    </div>
  );
}
