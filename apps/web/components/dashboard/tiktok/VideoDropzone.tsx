"use client";

import { useEffect, useId, useState, type ReactNode } from "react";
import { Film, Loader2 } from "lucide-react";
import { ApiError, getToken } from "@/lib/api";
import { fetchClipVideo, uploadClipVideo, type TikTokVideo } from "@/lib/tiktok";

function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatDuration(seconds: number | null): string {
  if (!seconds || seconds < 1) return "under a minute";
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  if (minutes < 1) return `${rest}s`;
  return `${minutes}:${String(rest).padStart(2, "0")}`;
}

export function VideoSummary({ video }: { video: TikTokVideo }) {
  return (
    <div className="space-y-1 text-sm">
      <p>
        Video file: ready
        <span className="mt-1 block text-muted-foreground">
          {video.filename} · {formatDuration(video.duration_seconds)} · {formatSize(video.size)}
        </span>
      </p>
      {video.warning && <p>{video.warning}</p>}
    </div>
  );
}

export function VideoPreview({ clipId }: { clipId: number }) {
  const [url, setUrl] = useState<string | null>(null);
  const [missing, setMissing] = useState(false);

  useEffect(() => {
    if (!getToken()) return;
    let objectUrl = "";
    let cancelled = false;
    fetchClipVideo(clipId)
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => {
        if (!cancelled) setMissing(true);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [clipId]);

  if (missing) return <p className="text-sm text-muted-foreground">Preview isn't available.</p>;
  if (!url) return <p className="text-sm text-muted-foreground">Loading preview…</p>;

  return (
    <video
      controls
      playsInline
      preload="metadata"
      src={url}
      className="aspect-[9/16] w-full max-w-[220px] rounded-sm bg-black sm:max-w-xs"
    />
  );
}

export function VideoPending({ detail, action }: { detail: string; action?: ReactNode }) {
  return (
    <div className="flex min-h-36 flex-col items-center justify-center gap-2 rounded-sm border border-dashed border-border bg-muted/40 px-4 py-6 text-center">
      <Film className="size-5" aria-hidden />
      <span className="text-sm font-medium">Add your video</span>
      <span className="max-w-sm text-xs text-muted-foreground">{detail}</span>
      {action}
    </div>
  );
}

export function VideoDropzone({
  clipId,
  replacing = false,
  onUploaded,
}: {
  clipId: number;
  replacing?: boolean;
  onUploaded: () => void;
}) {
  const inputId = useId();
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function send(file: File) {
    const name = file.name.toLowerCase();
    const looksVideo = file.type === "video/mp4" || file.type === "video/quicktime" || name.endsWith(".mp4") || name.endsWith(".mov");
    if (!looksVideo) {
      setError("Choose an MP4 or MOV.");
      return;
    }
    setError(null);
    setProgress(0);
    try {
      await uploadClipVideo(clipId, file, setProgress);
      onUploaded();
    } catch (err) {
      setError(err instanceof ApiError || err instanceof Error ? err.message : "We couldn't store this video. Try again.");
    } finally {
      setProgress(null);
    }
  }

  const busy = progress !== null;

  return (
    <div className="space-y-3">
      <label
        htmlFor={inputId}
        onDragEnter={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        onDragOver={(event) => event.preventDefault()}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          const file = event.dataTransfer.files?.[0];
          if (file) void send(file);
        }}
        className={`flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-sm border border-dashed px-4 py-6 text-center transition-colors ${
          dragging ? "border-foreground bg-muted" : "border-border bg-muted/40 hover:border-foreground"
        } ${busy ? "pointer-events-none opacity-80" : ""}`}
      >
        {busy ? <Loader2 className="size-5 motion-safe:animate-spin" aria-hidden /> : <Film className="size-5" aria-hidden />}
        <span className="text-sm font-medium">{replacing ? "Replace your video" : "Add your video"}</span>
        <span className="max-w-sm text-xs text-muted-foreground">
          MP4 or MOV, up to 100 MB. Vertical 9:16 fits the feed best. On a phone, tap to choose the file.
        </span>
        <input
          id={inputId}
          type="file"
          accept="video/mp4,video/quicktime,.mp4,.mov"
          className="sr-only"
          disabled={busy}
          onChange={(event) => {
            const file = event.target.files?.[0];
            event.target.value = "";
            if (file) void send(file);
          }}
        />
      </label>
      {busy && (
        <div>
          <div className="flex items-baseline justify-between text-xs">
            <span>Uploading</span>
            <span className="tabular-nums">{progress}%</span>
          </div>
          <div
            className="mt-2 h-1 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-valuenow={progress ?? 0}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="Upload progress"
          >
            <div className="h-full bg-foreground transition-[width]" style={{ width: `${progress ?? 0}%` }} />
          </div>
        </div>
      )}
      {error && (
        <p className="text-sm" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
