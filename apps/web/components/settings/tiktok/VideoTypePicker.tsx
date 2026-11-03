"use client";

import { useRef } from "react";
import { Badge } from "@/components/ui/badge";

const LEGEND = "Style";
const SOON = "Coming soon";

const TYPES = [
  { id: "motion", label: "Motion graphics", available: true },
  { id: "cartoon", label: "Cartoon", available: false },
  { id: "avatar", label: "Real person", available: false },
  { id: "stock", label: "Stock", available: false },
] as const;

function MotionMark() {
  return (
    <svg viewBox="0 0 72 128" className="h-16 w-9" aria-hidden>
      <rect x="1" y="1" width="70" height="126" rx="8" fill="none" stroke="currentColor" strokeWidth="2" />
      <rect x="14" y="28" width="44" height="8" rx="2" fill="currentColor" />
      <rect x="18" y="44" width="36" height="6" rx="2" fill="currentColor" opacity="0.55" />
      <rect x="16" y="92" width="40" height="10" rx="2" fill="currentColor" />
    </svg>
  );
}

export function VideoTypePicker({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const group = useRef<HTMLDivElement>(null);

  function move(direction: 1 | -1) {
    const enabled = TYPES.filter((type) => type.available);
    const index = Math.max(0, enabled.findIndex((type) => type.id === value));
    const next = enabled[(index + direction + enabled.length) % enabled.length];
    onChange(next.id);
    group.current?.querySelector<HTMLButtonElement>(`[data-type="${next.id}"]`)?.focus();
  }

  return (
    <fieldset className="space-y-3">
      <legend className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{LEGEND}</legend>
      <div
        ref={group}
        role="radiogroup"
        aria-label={LEGEND}
        className="grid gap-3 sm:grid-cols-2"
        onKeyDown={(event) => {
          if (event.key === "ArrowRight" || event.key === "ArrowDown") {
            event.preventDefault();
            move(1);
          }
          if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
            event.preventDefault();
            move(-1);
          }
        }}
      >
        {TYPES.map((type) => {
          const selected = value === type.id;
          return (
            <button
              key={type.id}
              type="button"
              role="radio"
              data-type={type.id}
              aria-checked={selected}
              aria-disabled={!type.available}
              disabled={!type.available}
              tabIndex={type.available && selected ? 0 : -1}
              onClick={() => type.available && onChange(type.id)}
              className={`flex min-h-11 items-center gap-3 rounded-sm border p-3 text-left transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none ${
                selected ? "border-foreground bg-muted/50" : "border-border bg-background"
              }`}
            >
              {type.id === "motion" ? <MotionMark /> : <span className="h-16 w-9 rounded-sm border border-dashed border-border" aria-hidden />}
              <span className="min-w-0">
                <span className="block text-sm font-medium">{type.label}</span>
                {!type.available && (
                  <Badge variant="outline" className="mt-2">
                    {SOON}
                  </Badge>
                )}
              </span>
            </button>
          );
        })}
      </div>
    </fieldset>
  );
}
