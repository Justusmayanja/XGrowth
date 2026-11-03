import Link from "next/link";
import { AlertCircle, Loader2, Sparkles } from "lucide-react";
import type { ReactNode } from "react";
import type { TikTokClip } from "@/lib/tiktok";
import { elapsedLabel } from "./media";
import { PhonePreview } from "./PhonePreview";
import { VideoActionRow } from "./VideoActionRow";

const MAKING = "Making your video…";
const STALLED = "This is taking longer than usual. We'll keep working on it.";
const HONESTY = "Nothing is sent to TikTok until you confirm.";
const DISCLOSURE = "Made with Mackie17. Check it before you post.";
const AI = "AI-made";
const FAILED = "We couldn't make the video. Try again, or add your own.";
const SKIPPED = "A video was not made for this clip.";
const TRY = "Try again";

export function RenderStatusCard({
  clip,
  src,
  poster,
  bytes,
  durationSeconds,
  busy,
  stalled,
  elapsedSeconds,
  onAgain,
  onUseOwn,
  onRefresh,
  dropzone,
  safeAreaDefault = false,
}: {
  clip: TikTokClip;
  src: string | null;
  poster: string | null;
  bytes: number | null;
  durationSeconds: number | null;
  busy: boolean;
  stalled: boolean;
  elapsedSeconds: number;
  onAgain: () => void;
  onUseOwn: () => void;
  onRefresh: () => Promise<{ src: string | null; poster: string | null } | null>;
  dropzone?: ReactNode;
  safeAreaDefault?: boolean;
}) {
  const render = clip.render;
  if (!render || render.provider === "user" || render.status === "none") return null;

  const active = render.status === "queued" || render.status === "rendering";
  if (active) {
    return (
      <div className="mx-auto w-full max-w-[300px] space-y-3 lg:max-w-[320px]" aria-busy="true">
        <div className="aspect-[9/16] rounded-[1.25rem] border border-border bg-muted motion-safe:animate-pulse" />
        <p className="flex items-center gap-2 text-sm">
          <Loader2 className="size-4 motion-safe:animate-spin" aria-hidden />
          {stalled ? STALLED : MAKING}
        </p>
        {!stalled && elapsedSeconds >= 30 && <p className="text-sm tabular-nums text-muted-foreground">{elapsedLabel(elapsedSeconds)}</p>}
      </div>
    );
  }

  if (render.status === "ready") {
    return (
      <div className="space-y-3">
        {render.ai_generated && (
          <p className="inline-flex min-h-7 items-center gap-1.5 rounded-sm border border-border px-2 text-xs">
            <Sparkles className="size-3.5" aria-hidden />
            {AI}
          </p>
        )}
        <PhonePreview
          src={src}
          poster={poster}
          hook={clip.hook || clip.topic || "Clip preview"}
          bytes={bytes}
          durationSeconds={durationSeconds}
          onRefresh={onRefresh}
          safeAreaDefault={safeAreaDefault}
        />
        <p className="text-sm">{HONESTY}</p>
        <p className="text-sm text-muted-foreground">{DISCLOSURE}</p>
        <VideoActionRow canRegenerate={render.can_regenerate} busy={busy} onAgain={onAgain} onUseOwn={onUseOwn} />
      </div>
    );
  }

  if (render.status === "failed") {
    return (
      <div className="space-y-3" role="alert">
        <p className="flex items-start gap-2 text-sm">
          <AlertCircle className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
          <span>{render.note || FAILED}</span>
        </p>
        <VideoActionRow canRegenerate={render.can_regenerate} busy={busy} againLabel={TRY} onAgain={onAgain} onUseOwn={onUseOwn} />
        {dropzone}
      </div>
    );
  }

  if (render.status === "skipped") {
    return (
      <div className="space-y-3">
        <p className="text-sm text-muted-foreground">{render.note || SKIPPED}</p>
        <Link href="/settings#video" className="inline-flex min-h-11 items-center text-sm underline underline-offset-4">
          Video settings
        </Link>
        {dropzone}
      </div>
    );
  }

  return null;
}
