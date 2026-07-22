"use client";

type SettingsToggleProps = {
  id: string;
  label: string;
  description?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
};

export function SettingsToggle({
  id,
  label,
  description,
  checked,
  onChange,
  disabled,
}: SettingsToggleProps) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-lg border border-border p-4">
      <div>
        <label htmlFor={id} className="mb-0 cursor-pointer font-medium text-foreground">
          {label}
        </label>
        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
      </div>
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        disabled={disabled}
        className="mt-1 h-5 w-5 shrink-0 accent-[var(--primary)]"
      />
    </div>
  );
}
