"use client";

import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Film, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ApiError, getToken } from "@/lib/api";
import { fetchClipVideo, uploadClipVideo, type TikTokVideo } from "@/lib/tiktok";
import { byteLabel } from "./video/media";

const ACCEPTED = "MP4 or MOV, up to 100 MB and 10 minutes.";
const TOO_BIG = "This file is over 100 MB. Export a shorter clip and try again.";
const WRONG_TYPE = "Choose an MP4 or MOV.";
const CANCELLED = "Upload cancelled. The file was not stored. Choose it again when you are ready.";
const MAX_BYTES = 100 * 1024 * 1024;

function formatSize(bytes: number): string {
  return byteLabel(bytes);
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
      {video.warning && (
        <p className="rounded-sm border border-border bg-muted/40 p-3 text-muted-foreground">{video.warning}</p>
      )}
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

export type UploadFixture = {
  progress?: number | null;
  loaded?: number;
  total?: number;
  error?: string | null;
  notice?: string | null;
};

export function VideoDropzone({
  clipId,
  replacing = false,
  onUploaded,
  fixture,
}: {
  clipId: number;
  replacing?: boolean;
  onUploaded: () => void;
  fixture?: UploadFixture;
}) {
  const inputId = useId();
  const abortRef = useRef<AbortController | null>(null);
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<number | null>(fixture?.progress ?? null);
  const [loaded, setLoaded] = useState(fixture?.loaded ?? 0);
  const [total, setTotal] = useState(fixture?.total ?? 0);
  const [error, setError] = useState<string | null>(fixture?.error ?? null);
  const [notice, setNotice] = useState<string | null>(fixture?.notice ?? null);

  async function send(file: File) {
    if (fixture) return;
    const name = file.name.toLowerCase();
    const looksVideo = file.type === "video/mp4" || file.type === "video/quicktime" || name.endsWith(".mp4") || name.endsWith(".mov");
    if (!looksVideo) {
      setError(WRONG_TYPE);
      setNotice(null);
      return;
    }
    if (file.size > MAX_BYTES) {
      setError(TOO_BIG);
      setNotice(null);
      return;
    }
    const controller = new AbortController();
    abortRef.current = controller;
    setError(null);
    setNotice(null);
    setProgress(0);
    setLoaded(0);
    setTotal(file.size);
    try {
      await uploadClipVideo(clipId, file, (percent, sent, size) => {
        setProgress(percent);
        setLoaded(sent);
        setTotal(size);
      }, controller.signal);
      onUploaded();
    } catch (err) {
      if (err instanceof ApiError && err.code === "cancelled") {
        setNotice(CANCELLED);
        setError(null);
      } else {
        setError(err instanceof ApiError || err instanceof Error ? err.message : "We couldn't store this video. Try again.");
      }
    } finally {
      abortRef.current = null;
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
        className={`flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-sm border border-dashed px-4 py-6 text-center transition-colors duration-150 focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background motion-reduce:transition-none ${
          dragging ? "border-foreground bg-muted" : "border-border bg-muted/40 hover:border-foreground"
        } ${busy ? "pointer-events-none opacity-80" : ""}`}
      >
        {busy ? <Loader2 className="size-5 motion-safe:animate-spin" aria-hidden /> : <Film className="size-5" aria-hidden />}
        <span className="text-sm font-medium">{replacing ? "Replace your video" : "Add your video"}</span>
        <span className="max-w-sm text-xs text-muted-foreground">{ACCEPTED} Vertical 9:16 fits the feed best.</span>
        <input
          id={inputId}
          type="file"
          accept="video/mp4,video/quicktime,.mp4,.mov"
          className="sr-only"
          disabled={busy || Boolean(fixture)}
          onChange={(event) => {
            const file = event.target.files?.[0];
            event.target.value = "";
            if (file) void send(file);
          }}
        />
      </label>
      {busy && (
        <div className="space-y-2">
          <div className="flex items-baseline justify-between gap-3 text-xs">
            <span>Uploading</span>
            <span className="tabular-nums">
              {progress}% · {byteLabel(loaded)} of {byteLabel(total)}
            </span>
          </div>
          <div
            className="h-1 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-valuenow={progress ?? 0}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="Upload progress"
          >
            <div className="h-full bg-foreground transition-[width] duration-150 motion-reduce:transition-none" style={{ width: `${progress ?? 0}%` }} />
          </div>
          {!fixture && (
            <Button type="button" variant="ghost" className="min-h-11" onClick={() => abortRef.current?.abort()}>
              Cancel
            </Button>
          )}
        </div>
      )}
      {notice && (
        <p className="text-sm text-muted-foreground" role="status">
          {notice}
        </p>
      )}
      {error && (
        <p className="text-sm" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
