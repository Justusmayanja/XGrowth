"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { api } from "@/lib/api";
import type { TikTokClip } from "@/lib/tiktok";
import { RenderStatusCard } from "./video/RenderStatusCard";

type PreviewBody = {
  data: {
    preview_url: string;
    poster_url: string | null;
    preview_bytes: number | null;
    duration_seconds: number | null;
  };
};

export function VideoPanel({
  clip,
  onChanged,
  onUseOwn,
  dropzone,
  elapsedSeconds,
  stalled = false,
  safeAreaDefault = false,
  skipFetch = false,
}: {
  clip: TikTokClip;
  onChanged: () => void;
  onUseOwn: () => void;
  dropzone?: ReactNode;
  elapsedSeconds?: number;
  stalled?: boolean;
  safeAreaDefault?: boolean;
  skipFetch?: boolean;
}) {
  const render = clip.render;
  const [preview, setPreview] = useState<string | null>(null);
  const [poster, setPoster] = useState<string | null>(null);
  const [size, setSize] = useState<number | null>(clip.video?.size ?? null);
  const [duration, setDuration] = useState<number | null>(clip.video?.duration_seconds ?? null);
  const [busy, setBusy] = useState(false);
  const [held, setHeld] = useState(stalled);
  const [elapsed, setElapsed] = useState(elapsedSeconds ?? 0);
  const [announcement, setAnnouncement] = useState("");
  const announced = useRef(render?.status ?? "");
  const changed = useRef(onChanged);
  changed.current = onChanged;

  const active = render?.status === "queued" || render?.status === "rendering";

  useEffect(() => {
    if (render?.status && announced.current !== render.status) {
      announced.current = render.status;
      setAnnouncement(render.label || render.status);
    }
  }, [render?.status, render?.label]);

  useEffect(() => {
    if (!active || stalled) return;
    const started = Date.now() - (elapsedSeconds ?? 0) * 1000;
    let delay = 5000;
    let timer = 0;
    const tick = () => {
      const seconds = Math.floor((Date.now() - started) / 1000);
      setElapsed(seconds);
      if (seconds >= 10 * 60) {
        setHeld(true);
        setAnnouncement("This is taking longer than usual. We'll keep working on it.");
        return;
      }
      if (document.visibilityState === "visible") changed.current();
      delay = Math.min(30000, Math.round(delay * 1.5));
      timer = window.setTimeout(tick, delay);
    };
    timer = window.setTimeout(tick, document.visibilityState === "visible" ? delay : 1000);
    return () => window.clearTimeout(timer);
  }, [active, stalled, clip.id, elapsedSeconds]);

  async function loadPreview() {
    const body = await api<PreviewBody>(`/tiktok/clips/${clip.id}/video/preview`);
    setPreview(body.data.preview_url);
    setPoster(body.data.poster_url);
    setSize(body.data.preview_bytes);
    setDuration(body.data.duration_seconds);
    return { src: body.data.preview_url, poster: body.data.poster_url };
  }

  useEffect(() => {
    if (skipFetch || !render?.ready || render.provider === "user") return;
    let cancelled = false;
    loadPreview().catch(() => {
      if (!cancelled) setPreview(null);
    });
    return () => {
      cancelled = true;
    };
    // The preview URL is kept in component state only. It is not written to storage or the address bar.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clip.id, render?.ready, render?.provider, skipFetch]);

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

  return (
    <div className="space-y-3">
      <div aria-live="polite" className="sr-only">
        {announcement}
      </div>
      <RenderStatusCard
        clip={clip}
        src={preview}
        poster={poster}
        bytes={size}
        durationSeconds={duration}
        busy={busy}
        stalled={held}
        elapsedSeconds={elapsed}
        safeAreaDefault={safeAreaDefault}
        dropzone={dropzone}
        onAgain={() => void again()}
        onUseOwn={onUseOwn}
        onRefresh={async () => {
          if (skipFetch) return null;
          try {
            return await loadPreview();
          } catch {
            return null;
          }
        }}
      />
    </div>
  );
}
