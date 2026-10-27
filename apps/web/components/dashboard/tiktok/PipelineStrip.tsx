import { Card, CardContent } from "@/components/ui/card";
import type { TikTokDesk } from "@/lib/tiktok";

const STEPS = [
  { key: "ideas", label: "Ideas found", hint: "Suited to your brand" },
  { key: "written", label: "Written", hint: "Hooks and captions" },
  { key: "waiting", label: "Waiting for you", hint: "Each with a suggested time" },
  { key: "confirmed", label: "Confirmed", hint: "Yes, and not posted yet" },
  { key: "posted", label: "Posted", hint: "After the file and the connection" },
] as const;

export function PipelineStrip({ pipeline }: { pipeline: TikTokDesk["pipeline"] }) {
  return (
    <Card>
      <CardContent className="grid grid-cols-2 gap-0 p-0 sm:grid-cols-3 lg:grid-cols-5">
        {STEPS.map((step, index) => (
          <div
            key={step.key}
            className={`min-w-0 p-4 ${index > 0 ? "border-border max-lg:border-t lg:border-l" : ""} ${index === 1 ? "max-sm:border-l max-sm:border-t-0" : ""}`}
          >
            <p className="text-xs text-muted-foreground">
              {index + 1}. {step.label}
            </p>
            <p className="mt-2 font-mono text-2xl tabular-nums">{pipeline[step.key]}</p>
            <p className="mt-1 text-xs text-muted-foreground">{step.hint}</p>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}
