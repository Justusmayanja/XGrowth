"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Lightbulb } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { Card, CardContent } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { PLATFORM_GOALS } from "@/lib/profile";
import { fetchTikTokIdeas, type TikTokClip, type TikTokDesk, type TikTokIdea } from "@/lib/tiktok";
import { VideoDropzone, VideoPending, VideoPreview, VideoSummary } from "./VideoDropzone";

const ACCEPTS_FILE = new Set(["waiting", "queued"]);

export function TikTokIdeas() {
  const [ideas, setIdeas] = useState<TikTokIdea[] | null>(null);
  const [goals, setGoals] = useState<string[]>([]);
  const [clip, setClip] = useState<TikTokClip | null>(null);
  const [clipReady, setClipReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [picked, setPicked] = useState(0);

  const loadClip = useCallback(async () => {
    const desk = await api<{ tiktok?: TikTokDesk }>("/dashboard/platform/tiktok");
    setClip(desk.tiktok?.clip ?? null);
    setClipReady(true);
  }, []);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      fetchTikTokIdeas(),
      api<{ setups?: { tiktok?: { goals?: string[] } } }>("/account/setup"),
      api<{ tiktok?: TikTokDesk }>("/dashboard/platform/tiktok").catch(() => null),
    ])
      .then(([ideaPayload, setup, desk]) => {
        if (cancelled) return;
        setIdeas(ideaPayload.data);
        setGoals(setup.setups?.tiktok?.goals ?? []);
        setClip(desk?.tiktok?.clip ?? null);
        setClipReady(true);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : "Something went wrong. We're checking what happened.");
        setIdeas([]);
        setClipReady(true);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const chips = goals.map((value) => PLATFORM_GOALS.tiktok.find((goal) => goal.value === value)?.label ?? value);
  const active = ideas?.[picked] ?? ideas?.[0] ?? null;
  const canUpload = clip != null && ACCEPTS_FILE.has(clip.status);

  return (
    <div className="space-y-6">
      <DeskHeading icon={Lightbulb} title="Ideas">
        <p>These are ideas that fit your niche and goals. The next clip uses the top one. The video file is added on that clip.</p>
      </DeskHeading>
      {chips.length > 0 && (
        <ul className="flex flex-wrap gap-2" aria-label="Goals">
          {chips.map((label) => (
            <li key={label} className="rounded-full border border-border bg-surface px-3 py-1 text-xs text-muted-foreground">
              {label}
            </li>
          ))}
        </ul>
      )}
      {ideas === null ? (
        <LoadingMark />
      ) : error ? (
        <p className="text-sm" role="status">
          {error}
        </p>
      ) : (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(17rem,0.85fr)]">
          <Card>
            <CardContent className="pt-6">
              <h2 className="text-sm font-medium">Ranked for this account</h2>
              {ideas.length === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">Ideas for this account show up here.</p>
              ) : (
                <ul className="mt-3 divide-y divide-border">
                  {ideas.map((idea, index) => {
                    const on = active?.topic === idea.topic && active?.kind === idea.kind;
                    return (
                      <li key={`${idea.kind}-${idea.topic}`}>
                        <button
                          type="button"
                          onClick={() => setPicked(index)}
                          aria-pressed={on}
                          className={`flex w-full flex-col gap-1 rounded-md px-3 py-3 text-left ${on ? "bg-muted" : "hover:bg-muted/60"}`}
                        >
                          <span className="flex items-center gap-2 text-[11px] uppercase tracking-wide text-muted-foreground">
                            {idea.kind_label}
                            {index === 0 && (
                              <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] normal-case tracking-normal text-foreground">
                                Next clip
                              </span>
                            )}
                          </span>
                          <span className="font-medium">{idea.topic}</span>
                          <span className="text-sm leading-snug text-muted-foreground">{idea.angle || idea.reason}</span>
                        </button>
                      </li>
                    );
                  })}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="space-y-4 pt-6">
              <div>
                <h2 className="text-sm font-medium">Your video</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  MP4 or MOV, up to 100 MB. A vertical 9:16 file fits the feed best. Confirming sends it to your TikTok drafts. It does not post.
                </p>
              </div>
              {!clipReady ? (
                <p className="text-sm text-muted-foreground">Loading the clip…</p>
              ) : canUpload && clip ? (
                <div className="space-y-4">
                  {clip.topic && <p className="text-sm">For this clip: {clip.topic}</p>}
                  {clip.video && (
                    <div className="space-y-3">
                      <VideoSummary video={clip.video} />
                      <VideoPreview clipId={clip.id} />
                    </div>
                  )}
                  <VideoDropzone clipId={clip.id} replacing={Boolean(clip.video)} onUploaded={() => void loadClip()} />
                </div>
              ) : (
                <VideoPending
                  detail={
                    clip
                      ? `This clip is ${clip.status_label.toLowerCase()}. The file is added while a clip is waiting or confirmed.`
                      : "Today writes the clip first. The file is added on that clip, then you confirm it."
                  }
                  action={
                    <Link href="/dashboard/tiktok#video" className="text-sm underline underline-offset-4">
                      Open the video on Today
                    </Link>
                  }
                />
              )}
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
}
