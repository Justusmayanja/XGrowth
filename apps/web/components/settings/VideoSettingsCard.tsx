"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ApiError, api, apiForm } from "@/lib/api";
import { BrandKit } from "./tiktok/BrandKit";
import { BusinessDetails } from "./tiktok/BusinessDetails";
import { captionColour, isHex } from "./tiktok/contrast";
import { DEFAULT_VIDEO, type VideoSettings } from "./tiktok/types";
import { VideoTypePicker } from "./tiktok/VideoTypePicker";
import { VoiceCaptionControls } from "./tiktok/VoiceCaptionControls";

const SAVED = "Saved";
const LEAVE = "You have unsaved video settings. Leave without saving?";
const SECTIONS = [
  { id: "style", label: "Style" },
  { id: "voice", label: "Voice and captions" },
  { id: "brand", label: "Brand" },
  { id: "business", label: "Business details" },
] as const;

export type VideoSettingsReview = {
  video: VideoSettings;
  musicAvailable?: boolean;
  logo?: boolean;
  fieldErrors?: Record<string, string>;
  section?: (typeof SECTIONS)[number]["id"];
  contrastPair?: { background: string; foreground: string } | null;
};

function snapshot(video: VideoSettings): string {
  return JSON.stringify(video);
}

export function VideoSettingsCard({ review }: { review?: VideoSettingsReview }) {
  const [video, setVideo] = useState<VideoSettings | null>(review?.video ?? null);
  const [saved, setSaved] = useState(review ? snapshot(review.video) : "");
  const [musicAvailable, setMusicAvailable] = useState(review?.musicAvailable ?? false);
  const [logo, setLogo] = useState(review?.logo ?? false);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState("");
  const [fields, setFields] = useState<Record<string, string>>(review?.fieldErrors ?? {});
  const [section, setSection] = useState<(typeof SECTIONS)[number]["id"]>(review?.section ?? "style");
  const [missing, setMissing] = useState<string | null>(null);

  useEffect(() => {
    if (review) return;
    api<{ data: { video: VideoSettings; music_available: boolean; logo: boolean } }>("/tiktok/video-settings")
      .then((body) => {
        setVideo(body.data.video);
        setSaved(snapshot(body.data.video));
        setMusicAvailable(body.data.music_available);
        setLogo(body.data.logo);
      })
      .catch((err) => {
        setVideo(null);
        setMissing(err instanceof Error ? err.message : "Finish TikTok setup first.");
      });
  }, [review]);

  const dirty = video ? snapshot(video) !== saved : false;

  useEffect(() => {
    if (!dirty || review) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    const click = (event: MouseEvent) => {
      const anchor = (event.target as Element | null)?.closest("a");
      if (!anchor || anchor.target === "_blank") return;
      if (anchor.origin !== window.location.origin) return;
      if (!window.confirm(LEAVE)) event.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    document.addEventListener("click", click, true);
    return () => {
      window.removeEventListener("beforeunload", warn);
      document.removeEventListener("click", click, true);
    };
  }, [dirty, review]);

  if (!video) {
    if (missing) {
      return (
        <Card id={review ? undefined : "video"}>
          <CardHeader>
            <CardTitle>Video</CardTitle>
            <CardDescription>{missing}</CardDescription>
          </CardHeader>
        </Card>
      );
    }
    return (
      <Card id="video" aria-busy="true">
        <CardHeader>
          <CardTitle>Video</CardTitle>
          <CardDescription>Loading video settings…</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="h-24 rounded-sm bg-muted motion-safe:animate-pulse" />
        </CardContent>
      </Card>
    );
  }

  const background = video.brand.use_colors && isHex(video.brand.primary) ? video.brand.primary : "#101113";
  const foreground = captionColour(background);

  async function save() {
    if (!video || review) return;
    setBusy(true);
    setFields({});
    try {
      const body = await api<{ data: { video: VideoSettings } }>("/tiktok/video-settings", {
        method: "PUT",
        body: JSON.stringify(video),
      });
      setVideo(body.data.video);
      setSaved(snapshot(body.data.video));
      setStatus(SAVED);
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fields).length > 0) setFields(err.fields);
      toast.error(err instanceof Error ? err.message : "Could not save video settings");
    } finally {
      setBusy(false);
    }
  }

  async function uploadLogo(file: File) {
    if (review) return;
    const body = new FormData();
    body.append("logo", file);
    try {
      await apiForm("/tiktok/video-settings/logo", body);
      setLogo(true);
      setStatus(SAVED);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save the logo");
    }
  }

  async function removeLogo() {
    if (review) {
      setLogo(false);
      return;
    }
    try {
      await api("/tiktok/video-settings/logo", { method: "DELETE" });
      setLogo(false);
      setStatus(SAVED);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not remove the logo");
    }
  }

  return (
    <Card id="video">
      <CardHeader>
        <CardTitle className="text-balance">Video</CardTitle>
        <CardDescription>How Mackie17 makes the vertical clip before you confirm it.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-5 pb-24 md:pb-6">
        <div role="tablist" aria-label="Video settings" className="flex gap-2 overflow-x-auto">
          {SECTIONS.map((item) => {
            const selected = section === item.id;
            return (
              <button
                key={item.id}
                type="button"
                role="tab"
                id={`video-tab-${item.id}`}
                aria-selected={selected}
                aria-controls={`video-panel-${item.id}`}
                tabIndex={selected ? 0 : -1}
                onClick={() => setSection(item.id)}
                className={`min-h-11 shrink-0 rounded-sm border px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${
                  selected ? "border-foreground bg-muted" : "border-border"
                }`}
              >
                {item.label}
              </button>
            );
          })}
        </div>
        <div role="tabpanel" id={`video-panel-${section}`} aria-labelledby={`video-tab-${section}`} className="space-y-4">
          {section === "style" && <VideoTypePicker value={video.type} onChange={(type) => setVideo({ ...video, type })} />}
          {section === "voice" && (
            <VoiceCaptionControls video={video} musicAvailable={musicAvailable} background={background} foreground={foreground} onChange={setVideo} />
          )}
          {section === "brand" && (
            <BrandKit
              video={video}
              logoSaved={logo}
              fieldErrors={fields}
              contrastPair={review?.contrastPair}
              onChange={setVideo}
              onLogo={(file) => void uploadLogo(file)}
              onRemoveLogo={() => void removeLogo()}
            />
          )}
          {section === "business" && <BusinessDetails video={video} fieldErrors={fields} onChange={setVideo} />}
        </div>
        <div className="fixed inset-x-0 bottom-0 z-30 flex flex-wrap items-center gap-3 border-t border-border bg-background px-4 py-3 md:static md:z-auto md:border-0 md:bg-transparent md:px-0 md:py-0">
          <Button type="button" className="min-h-11" disabled={busy || Boolean(review)} onClick={() => void save()}>
            Save
          </Button>
          <p aria-live="polite" className="text-sm text-muted-foreground">
            {status || (dirty ? "Unsaved changes" : "")}
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

export { DEFAULT_VIDEO };
