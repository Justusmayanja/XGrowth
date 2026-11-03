import { Check, Circle } from "lucide-react";
import type { TikTokClip } from "@/lib/tiktok";

const WRITTEN = "Written";
const VIDEO = "Video ready";
const YOURS = "Your video";
const CONFIRMED = "Confirmed";
const DRAFTS = "In your TikTok drafts";
const POSTED = "Posted";
const RESULTS = "Results in";

const LABELS = [WRITTEN, VIDEO, CONFIRMED, DRAFTS, POSTED, RESULTS];

function currentIndex(clip: TikTokClip): number {
  if (clip.status === "learned") return 5;
  if (clip.status === "posted") return 4;
  if (clip.status === "in_drafts") return 3;
  if (clip.status === "queued" || clip.status === "uploading" || clip.status === "upload_failed") return 2;
  const ready = Boolean(clip.video) || clip.render?.status === "ready";
  return ready ? 1 : 0;
}

function labelAt(clip: TikTokClip, index: number): string {
  if (index !== 1) return LABELS[index];
  if (clip.render?.provider === "user") return YOURS;
  if (clip.video && clip.render?.provider !== "ffmpeg") return YOURS;
  return VIDEO;
}

export function ClipTimeline({ clip }: { clip: TikTokClip }) {
  const current = currentIndex(clip);

  return (
    <ol role="list" className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 snap-x snap-mandatory">
      {LABELS.map((fallback, index) => {
        const label = labelAt(clip, index);
        const done = index < current;
        const now = index === current;
        const Icon = done ? Check : Circle;
        return (
          <li
            key={fallback}
            aria-current={now ? "step" : undefined}
            className={`flex min-h-11 shrink-0 snap-start items-center gap-1.5 rounded-sm border px-2.5 text-xs ${
              now ? "border-foreground text-foreground" : done ? "border-border text-foreground" : "border-border text-muted-foreground"
            }`}
          >
            <Icon className="size-3.5 shrink-0" aria-hidden />
            <span className="whitespace-nowrap">{label}</span>
          </li>
        );
      })}
    </ol>
  );
}
