import { Check, Clock, Lightbulb, PenLine, Send } from "lucide-react";
import type { TikTokDesk } from "@/lib/tiktok";

const STEPS = [
  { key: "ideas", label: "Ideas", icon: Lightbulb },
  { key: "written", label: "Written", icon: PenLine },
  { key: "waiting", label: "Waiting", icon: Clock },
  { key: "confirmed", label: "Confirmed", icon: Check },
  { key: "posted", label: "Posted", icon: Send },
] as const;

export function PipelineStrip({
  pipeline,
  current,
}: {
  pipeline: TikTokDesk["pipeline"];
  current: (typeof STEPS)[number]["key"];
}) {
  return (
    <div className="relative">
      <ol role="list" aria-label="Clip pipeline" className="flex gap-2 overflow-x-auto pb-1 snap-x snap-mandatory sm:grid sm:grid-cols-5 sm:overflow-visible sm:pb-0">
        {STEPS.map((step, index) => {
          const active = step.key === current;
          const Icon = step.icon;
          return (
            <li key={step.key} className="flex min-w-[7.5rem] snap-start items-center gap-2 sm:min-w-0" aria-current={active ? "step" : undefined}>
              <span className={`flex size-7 shrink-0 items-center justify-center rounded-full border ${active ? "border-primary bg-primary text-primary-foreground" : "border-border text-muted-foreground"}`}>
                <Icon className="size-3.5" aria-hidden />
              </span>
              <span className="min-w-0">
                <span className={`block truncate text-xs ${active ? "font-medium text-foreground" : "text-muted-foreground"}`}>{step.label}</span>
                <span className="block text-sm tabular-nums">{pipeline[step.key]}</span>
              </span>
              {index < STEPS.length - 1 ? <span className="ml-auto hidden h-px w-4 bg-border sm:block" aria-hidden /> : null}
            </li>
          );
        })}
      </ol>
      <span className="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-background to-transparent sm:hidden" aria-hidden />
    </div>
  );
}