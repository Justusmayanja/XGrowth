"use client";

import { useEffect, useState } from "react";
import { BarChart3 } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { fetchTikTokClips, type TikTokClip, type TikTokHistory } from "@/lib/tiktok";

function percent(value: number | null): number {
  if (value == null || Number.isNaN(value)) return 0;
  return Math.max(0, Math.min(100, value));
}

function ResultRow({ clip }: { clip: TikTokClip }) {
  const watched = percent(clip.completion_percent);

  return (
    <li className="space-y-3 border-t border-border py-4 first:border-t-0 first:pt-0">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">{clip.kind_label || "clip"}</p>
          <p className="mt-1 text-base font-medium break-words">{clip.hook || clip.topic || "Clip"}</p>
        </div>
        <p className="text-sm tabular-nums text-muted-foreground">{(clip.views ?? 0).toLocaleString()} views</p>
      </div>
      <div>
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>Watched</span>
          <span className="tabular-nums">{watched}%</span>
        </div>
        <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted" aria-hidden>
          <div className="h-full bg-foreground/70" style={{ width: `${watched}%` }} />
        </div>
      </div>
      <p className="text-sm text-muted-foreground">{(clip.follows ?? 0).toLocaleString()} follows</p>
      {clip.learning_note && <p className="text-sm break-words">{clip.learning_note}</p>}
    </li>
  );
}

export function TikTokLanded() {
  const [page, setPage] = useState(1);
  const [payload, setPayload] = useState<TikTokHistory | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setPayload(null);
    fetchTikTokClips(page)
      .then((next) => {
        if (!cancelled) setPayload(next);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : "Something went wrong. We're checking what happened.");
        setPayload({ data: [], summary: { learned: 0, average_completion: null, views: 0, follows: 0 } });
      });
    return () => {
      cancelled = true;
    };
  }, [page]);

  const summary = payload?.summary;
  const lastPage = payload?.meta?.last_page ?? 1;

  return (
    <div className="space-y-6">
      <DeskHeading icon={BarChart3} title="What landed">
        <p>Numbers you entered after posting. They are not pulled from TikTok.</p>
      </DeskHeading>

      {payload === null ? (
        <LoadingMark />
      ) : error ? (
        <p className="text-sm" role="status">
          {error}
        </p>
      ) : summary && summary.learned === 0 ? (
        <Card>
          <CardContent className="pt-6">
            <p className="text-sm">No results yet. After you post a clip and add how it did, it shows up here.</p>
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              ["Clips learned", String(summary?.learned ?? 0)],
              ["Average watched", summary?.average_completion == null ? "—" : `${summary.average_completion}%`],
              ["Views", (summary?.views ?? 0).toLocaleString()],
              ["Follows", (summary?.follows ?? 0).toLocaleString()],
            ].map(([label, value]) => (
              <Card key={label}>
                <CardContent className="pt-6">
                  <p className="text-xs text-muted-foreground">{label}</p>
                  <p className="mt-2 font-mono text-xl tabular-nums">{value}</p>
                </CardContent>
              </Card>
            ))}
          </div>
          <Card>
            <CardContent className="pt-6">
              <ul>
                {payload.data.map((clip) => (
                  <ResultRow key={clip.id} clip={clip} />
                ))}
              </ul>
              {lastPage > 1 && (
                <div className="mt-4 flex items-center justify-between gap-3">
                  <Button type="button" variant="outline" className="min-h-11" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>
                    Previous
                  </Button>
                  <p className="text-xs text-muted-foreground">
                    Page {page} of {lastPage}
                  </p>
                  <Button type="button" variant="outline" className="min-h-11" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)}>
                    Next
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
