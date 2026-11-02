import { Check, Circle, Loader2 } from "lucide-react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import type { TikTokDesk, TikTokVideo } from "@/lib/tiktok";
import type { Ref } from "react";
import { VideoPreview, VideoSummary } from "./VideoDropzone";

const VIDEO = { id: "video_file", waiting: "Video file: not ready yet" } as const;

export function QueuedPanel({
  clipId,
  status = "queued",
  blockers,
  connection,
  video,
  uploadError,
  uploadProgress,
  headingRef,
  onRetry,
  retrying = false,
}: {
  clipId?: number;
  status?: string;
  blockers: string[];
  connection?: TikTokDesk["connection"];
  video?: TikTokVideo | null;
  uploadError?: string | null;
  uploadProgress?: number | null;
  headingRef?: Ref<HTMLHeadingElement>;
  onRetry?: () => void;
  retrying?: boolean;
}) {
  const fileReady = !blockers.includes(VIDEO.id);
  const linked = !blockers.includes("tiktok_connection");
  const needsPermission = blockers.includes("tiktok_upload_permission") || Boolean(connection?.needs_upload_permission);
  const configured = Boolean(connection?.configured);
  const uploadConfigured = Boolean(connection?.upload_configured);
  const name = connection?.display_name;
  const sending = status === "uploading";
  const drafted = status === "in_drafts";
  const failed = status === "upload_failed";

  const heading = sending
    ? "Sending to TikTok…"
    : drafted
      ? "In your TikTok drafts."
      : failed
        ? "Couldn't send"
        : "Confirmed. Not posted yet.";

  const lede = sending
    ? "This does not post the clip. It goes to your drafts, and you finish it in TikTok."
    : drafted
      ? "In your TikTok drafts. Open TikTok to finish and post it."
      : failed
        ? uploadError || "The send stopped. You can try again."
        : "This sends the file to your TikTok drafts. It does not post it.";

  return (
    <Card>
      <CardContent className="space-y-4 pt-6">
        <div>
          <h2 ref={headingRef} tabIndex={-1} className="text-base font-medium outline-none">
            {heading}
          </h2>
          <p className="mt-2 text-sm text-muted-foreground">{lede}</p>
        </div>
        {sending && (
          <div>
            <div
              className="h-1 overflow-hidden rounded-full bg-muted"
              role="progressbar"
              aria-valuenow={uploadProgress ?? 10}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="Send progress"
            >
              <div className="h-full bg-foreground motion-safe:animate-pulse" style={{ width: `${uploadProgress ?? 15}%` }} />
            </div>
          </div>
        )}
        {failed && onRetry && (
          <Button type="button" className="min-h-11 w-full sm:w-auto" disabled={retrying} aria-busy={retrying} onClick={onRetry}>
            {retrying && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
            Try again
          </Button>
        )}
        <ul className="space-y-3">
          <li className="flex items-start gap-2 text-sm">
            {fileReady ? <Check className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Circle className="mt-0.5 size-4 shrink-0" aria-hidden />}
            <div className="min-w-0">
              {fileReady && video ? <VideoSummary video={video} /> : <span>{VIDEO.waiting}</span>}
            </div>
          </li>
          <li className="flex items-start gap-2 text-sm">
            {linked && !needsPermission && uploadConfigured ? (
              <Check className="mt-0.5 size-4 shrink-0" aria-hidden />
            ) : (
              <Circle className="mt-0.5 size-4 shrink-0" aria-hidden />
            )}
            <span>
              {!linked ? (
                configured ? (
                  <>
                    TikTok connection: not connected yet.{" "}
                    <Link href="/settings" className="underline underline-offset-4">
                      Settings
                    </Link>
                  </>
                ) : (
                  "TikTok connection: waiting for TikTok approval"
                )
              ) : needsPermission ? (
                <>
                  TikTok needs one more permission. Reconnect to continue.{" "}
                  <Link href="/settings" className="underline underline-offset-4">
                    Settings
                  </Link>
                </>
              ) : !uploadConfigured ? (
                "Upload permission: waiting for TikTok approval"
              ) : (
                `TikTok can receive this clip${name ? ` as ${name}` : ""}.`
              )}
            </span>
          </li>
        </ul>
        {fileReady && video && clipId && <VideoPreview clipId={clipId} />}
      </CardContent>
    </Card>
  );
}
