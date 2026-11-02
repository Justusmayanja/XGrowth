"use client";

import type { ReactNode } from "react";
import { Check } from "lucide-react";
import { MAX_PROFILE_SELECTIONS } from "@/lib/profile";

type Option = {
  value: string;
  label: string;
  hint?: string;
  icon?: ReactNode;
};

type MultiSelectCardsProps = {
  options: Option[];
  selected: string[];
  onChange: (selected: string[]) => void;
  onLimitReached?: () => void;
  columns?: 1 | 2;
  compact?: boolean;
};

export function MultiSelectCards({
  options,
  selected,
  onChange,
  onLimitReached,
  compact = false,
}: MultiSelectCardsProps) {
  function toggle(value: string) {
    if (selected.includes(value)) {
      onChange(selected.filter((item) => item !== value));
      return;
    }
    if (selected.length >= MAX_PROFILE_SELECTIONS) {
      onLimitReached?.();
      return;
    }
    onChange([...selected, value]);
  }

  return (
    <div className={compact ? "grid grid-cols-1 gap-2 sm:grid-cols-2" : "flex flex-col gap-2"}>
      {options.map((option) => {
        const isSelected = selected.includes(option.value);
        return (
          <button
            key={option.value}
            type="button"
            onClick={() => toggle(option.value)}
            aria-pressed={isSelected}
            className={`${compact ? "choice-chip" : "choice-row"} ${isSelected ? "is-on" : ""}`}
          >
            <span>
              <strong>
                {option.icon}
                {option.label}
              </strong>
              {option.hint ? <em>{option.hint}</em> : null}
            </span>
            {isSelected ? <Check className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" /> : null}
          </button>
        );
      })}
    </div>
  );
}

export function SelectionHint({ selectedCount }: { selectedCount: number }) {
  return (
    <p className="mb-3 text-sm text-muted-foreground">
      Up to {MAX_PROFILE_SELECTIONS}. {selectedCount} selected.
    </p>
  );
}
