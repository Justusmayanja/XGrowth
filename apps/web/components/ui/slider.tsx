import { cn } from "@/lib/utils";

export function Slider({
  value,
  min = 0,
  max = 100,
  onChange,
  labelledBy,
  valueText,
  className,
}: {
  value: number;
  min?: number;
  max?: number;
  onChange: (value: number) => void;
  labelledBy: string;
  valueText: string;
  className?: string;
}) {
  return (
    <input
      type="range"
      min={min}
      max={max}
      step={1}
      value={value}
      aria-labelledby={labelledBy}
      aria-valuemin={min}
      aria-valuemax={max}
      aria-valuenow={value}
      aria-valuetext={valueText}
      onChange={(event) => onChange(Number(event.target.value))}
      className={cn("h-11 w-full cursor-pointer accent-foreground", className)}
    />
  );
}
