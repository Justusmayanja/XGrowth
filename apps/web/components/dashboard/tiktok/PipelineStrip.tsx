import type { TikTokDesk } from "@/lib/tiktok";

const STEPS = [
  { key: "ideas", label: "Ideas" },
  { key: "written", label: "Written" },
  { key: "waiting", label: "Waiting" },
  { key: "confirmed", label: "Confirmed" },
  { key: "posted", label: "Posted" },
] as const;

export function PipelineStrip({
  pipeline,
  current,
}: {
  pipeline: TikTokDesk["pipeline"];
  current: (typeof STEPS)[number]["key"];
}) {
  return (
    <ol className="grid grid-cols-5 gap-1.5" aria-label="Clip pipeline">
      {STEPS.map((step) => {
        const active = step.key === current;
        return (
          <li
            key={step.key}
            aria-current={active ? "step" : undefined}
            className={`min-w-0 rounded-sm border px-1 py-2 text-center ${active ? "border-foreground bg-card" : "border-border bg-background"}`}
          >
            <p className="truncate text-[10px] uppercase tracking-wide text-muted-foreground sm:text-xs">{step.label}</p>
            <p className="mt-1 font-mono text-sm tabular-nums sm:text-base">{pipeline[step.key]}</p>
          </li>
        );
      })}
    </ol>
  );
}
