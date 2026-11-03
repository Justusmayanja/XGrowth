"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import type { TikTokClip } from "@/lib/tiktok";

function formatBytes(bytes: number | null): string {
  if (!bytes || bytes < 1) return "a short video";
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function VideoPanel({
  clip,
  onChanged,
  onUseOwn,
}: {
  clip: TikTokClip;
  onChanged: () => void;
  onUseOwn: () => void;
}) {
  const render = clip.render;
  const [preview, setPreview] = useState<string | null>(null);
  const [size, setSize] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const active = render?.status === "queued" || render?.status === "rendering";

  useEffect(() => {
    if (!active) return;
    const started = Date.now();
    const timer = window.setInterval(() => {
      if (document.visibilityState !== "visible") return;
      if (Date.now() - started > 10 * 60 * 1000) {
        window.clearInterval(timer);
        return;
      }
      void onChanged();
    }, 5000);
    return () => window.clearInterval(timer);
  }, [active, clip.id, onChanged]);

  useEffect(() => {
    if (!render?.ready || render.provider === "user") return;
    let cancelled = false;
    api<{ data: { preview_url: string; preview_bytes: number | null } }>(`/tiktok/clips/${clip.id}/video/preview`)
      .then((body) => {
        if (cancelled) return;
        setPreview(body.data.preview_url);
        setSize(body.data.preview_bytes);
      })
      .catch(() => {
        if (!cancelled) setPreview(null);
      });
    return () => {
      cancelled = true;
    };
  }, [clip.id, render?.ready, render?.provider]);

  if (!render || render.provider === "user" || render.status === "none") return null;

  async function again() {
    setBusy(true);
    try {
      await api(`/tiktok/clips/${clip.id}/render`, { method: "POST" });
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  if (active) {
    return (
      <div className="mx-auto aspect-[9/16] w-full max-w-[220px] animate-pulse rounded-sm bg-muted" role="status">
        <p className="flex h-full items-end p-3 text-sm text-muted-foreground">Making your video…</p>
      </div>
    );
  }

  if (render.status === "ready" && render.provider !== "user") {
    return (
      <div className="space-y-3">
        {preview ? (
          <video
            className="mx-auto aspect-[9/16] w-full max-w-[220px] rounded-sm bg-black"
            controls
            playsInline
            src={preview}
          />
        ) : (
          <div className="mx-auto aspect-[9/16] w-full max-w-[220px] rounded-sm bg-muted" />
        )}
        <p className="text-sm">About {formatBytes(size)} to watch. Nothing is sent to TikTok until you confirm.</p>
        <p className="text-sm text-muted-foreground">Made with Mackie17. Check it before you post.</p>
        <div className="flex flex-wrap gap-2">
          {render.can_regenerate && (
            <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => void again()}>
              Make it again
            </Button>
          )}
          <Button type="button" variant="outline" className="min-h-11" onClick={onUseOwn}>
            Use my own video instead
          </Button>
        </div>
      </div>
    );
  }

  if (render.status === "failed") {
    return (
      <div className="space-y-3" role="alert">
        <p className="text-sm text-destructive">{render.note || "We couldn't make the video. Try again, or add your own."}</p>
        {render.can_regenerate && (
          <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => void again()}>
            Make it again
          </Button>
        )}
      </div>
    );
  }

  if (render.status === "skipped") {
    return (
      <div className="space-y-2">
        <p className="text-sm text-muted-foreground">{render.note || "A video was not made for this clip."}</p>
        <Link href="/settings" className="text-sm underline underline-offset-4">
          Video settings
        </Link>
      </div>
    );
  }

  return null;
}
