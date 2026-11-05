"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { loadYouTubeMedia, type YouTubeItem, type YouTubeMedia } from "@/lib/youtube";

export function YouTubeRenderPanel({
  item,
  busy,
  onRender,
}: {
  item: YouTubeItem;
  busy: boolean;
  onRender: () => void;
}) {
  const render = item.package?.render;
  const transcription = item.package?.transcription;
  const canMake = item.status === "waiting" || item.status === "queued" || item.status === "awaiting_edit";
  const [media, setMedia] = useState<YouTubeMedia | null>(null);

  useEffect(() => {
    if (!render?.ready) {
      setMedia(null);
      return;
    }
    let cancel = false;
    loadYouTubeMedia(item.id)
      .then((next) => {
        if (!cancel) setMedia(next);
      })
      .catch(() => {
        if (!cancel) setMedia(null);
      });
    return () => {
      cancel = true;
    };
  }, [item.id, render?.ready, render?.path]);

  return (
    <div className="space-y-3 rounded-md border border-border p-3">
      <p className="text-sm font-medium">Local file</p>
      <p className="text-sm text-muted-foreground">{render?.note || "No local file yet. Nothing is uploaded."}</p>
      {transcription?.note && <p className="text-sm text-muted-foreground">{transcription.note}</p>}
      {render?.ready && media && (
        <div className="space-y-2">
          <video
            className="aspect-video w-full max-w-xl rounded-md bg-black"
            controls
            poster={media.poster_url ?? undefined}
            src={media.preview_url}
          />
          <p className="text-xs text-muted-foreground">{media.note}</p>
          <a className="text-sm underline" href={media.download_url}>
            Download the local file
          </a>
        </div>
      )}
      {render?.ready && !media && (
        <p className="text-sm text-muted-foreground">The preview could not be loaded. The file is still local and has not been uploaded.</p>
      )}
      {canMake && (
        <div className="space-y-2">
          <Button type="button" size="sm" variant="outline" disabled={busy} onClick={onRender}>
            {busy ? "Making the local file…" : render?.ready ? "Make the local file again" : "Make the local file"}
          </Button>
          <p className="text-xs text-muted-foreground">
            This makes a file on this server for preview and download. It does not upload or post. Ship it still only approves the package.
          </p>
        </div>
      )}
    </div>
  );
}
