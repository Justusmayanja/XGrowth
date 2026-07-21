"use client";

import { MAX_PROFILE_SELECTIONS } from "@/lib/profile";

type Option = {
  value: string;
  label: string;
  hint?: string;
};

type MultiSelectCardsProps = {
  options: Option[];
  selected: string[];
  onChange: (selected: string[]) => void;
  onLimitReached?: () => void;
  columns?: 1 | 2;
};

export function MultiSelectCards({
  options,
  selected,
  onChange,
  onLimitReached,
  columns = 2,
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
    <div className={columns === 2 ? "grid gap-3 sm:grid-cols-2" : "grid gap-3"}>
      {options.map((option) => {
        const isSelected = selected.includes(option.value);
        return (
          <button
            key={option.value}
            type="button"
            onClick={() => toggle(option.value)}
            className={`rounded-xl border p-4 text-left transition ${
              isSelected
                ? "border-accent bg-accent/10"
                : "border-border hover:border-accent/40"
            }`}
          >
            <div className="flex items-start justify-between gap-2">
              <div className="font-semibold">{option.label}</div>
              {isSelected && (
                <span className="rounded-full bg-accent px-2 py-0.5 text-xs text-white">
                  ✓
                </span>
              )}
            </div>
            {option.hint && <div className="mt-1 text-sm text-muted">{option.hint}</div>}
          </button>
        );
      })}
    </div>
  );
}

export function SelectionHint({ selectedCount }: { selectedCount: number }) {
  return (
    <p className="mb-4 text-sm text-muted">
      Select up to {MAX_PROFILE_SELECTIONS} ({selectedCount}/{MAX_PROFILE_SELECTIONS} chosen)
    </p>
  );
}
