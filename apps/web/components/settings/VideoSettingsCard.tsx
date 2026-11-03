"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api, apiForm } from "@/lib/api";

type VideoSettings = {
  type: string;
  voice: string;
  captions: { enabled: boolean; style: string };
  length: string;
  music: { enabled: boolean };
  generate: string;
  brand: { use_logo: boolean; use_colors: boolean; primary: string; accent: string };
  context: { mention_location: boolean; show_contact: boolean };
};

const TYPES = [
  { id: "motion", label: "Motion", available: true },
  { id: "cartoon", label: "Cartoon", available: false },
  { id: "avatar", label: "Real person", available: false },
  { id: "stock", label: "Stock", available: false },
];

export function VideoSettingsCard() {
  const [video, setVideo] = useState<VideoSettings | null>(null);
  const [musicAvailable, setMusicAvailable] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ data: { video: VideoSettings; music_available: boolean } }>("/tiktok/video-settings")
      .then((body) => {
        setVideo(body.data.video);
        setMusicAvailable(body.data.music_available);
      })
      .catch(() => setVideo(null));
  }, []);

  if (!video) return null;

  async function save() {
    if (!video) return;
    setBusy(true);
    try {
      const saved = await api<{ data: { video: VideoSettings } }>("/tiktok/video-settings", {
        method: "PUT",
        body: JSON.stringify(video),
      });
      setVideo(saved.data.video);
      toast.success("Video settings saved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save video settings");
    } finally {
      setBusy(false);
    }
  }

  async function uploadLogo(file: File) {
    const body = new FormData();
    body.append("logo", file);
    try {
      await apiForm("/tiktok/video-settings/logo", body);
      toast.success("Logo saved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save the logo");
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Video</CardTitle>
        <CardDescription>How Mackie17 makes the vertical clip before you confirm it.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Type</legend>
          <div className="flex flex-wrap gap-2">
            {TYPES.map((type) => (
              <Button
                key={type.id}
                type="button"
                variant={video.type === type.id ? "default" : "outline"}
                className="min-h-11"
                disabled={!type.available}
                onClick={() => setVideo({ ...video, type: type.id })}
              >
                {type.label}
                {!type.available ? " · Coming soon" : ""}
              </Button>
            ))}
          </div>
        </fieldset>
        <label className="block space-y-1 text-sm">
          <span>Voice</span>
          <select
            className="min-h-11 w-full rounded-sm border border-border bg-background px-3"
            value={video.voice}
            onChange={(event) => setVideo({ ...video, voice: event.target.value })}
          >
            <option value="female">Female</option>
            <option value="male">Male</option>
          </select>
        </label>
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={video.captions.enabled}
            onChange={(event) => setVideo({ ...video, captions: { ...video.captions, enabled: event.target.checked } })}
          />
          Captions
        </label>
        <label className="block space-y-1 text-sm">
          <span>Length</span>
          <select
            className="min-h-11 w-full rounded-sm border border-border bg-background px-3"
            value={video.length}
            onChange={(event) => setVideo({ ...video, length: event.target.value })}
          >
            <option value="short">Short, about 20 seconds</option>
            <option value="standard">Standard, about 35 seconds</option>
          </select>
        </label>
        <p className="text-sm text-muted-foreground">
          {musicAvailable
            ? "A quiet music bed can sit under the voice."
            : "Music stays off. No licensed tracks are installed."}
        </p>
        <label className="block space-y-1 text-sm">
          <span>When to make it</span>
          <select
            className="min-h-11 w-full rounded-sm border border-border bg-background px-3"
            value={video.generate}
            onChange={(event) => setVideo({ ...video, generate: event.target.value })}
          >
            <option value="ahead">When the clip is written</option>
            <option value="on_confirm">When I confirm</option>
          </select>
        </label>
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={video.context.mention_location}
            onChange={(event) => setVideo({ ...video, context: { ...video.context, mention_location: event.target.checked } })}
          />
          Mention the saved location
        </label>
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={video.context.show_contact}
            onChange={(event) => setVideo({ ...video, context: { ...video.context, show_contact: event.target.checked } })}
          />
          Show contact details
        </label>
        <label className="block space-y-1 text-sm">
          <span>Logo, PNG JPG or WebP, under 2 MB</span>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="block w-full text-sm"
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) void uploadLogo(file);
            }}
          />
        </label>
        <Button type="button" className="min-h-11" disabled={busy} onClick={() => void save()}>
          Save
        </Button>
      </CardContent>
    </Card>
  );
}
