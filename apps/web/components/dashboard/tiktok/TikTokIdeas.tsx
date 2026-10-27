"use client";

import { useEffect, useState } from "react";
import { Lightbulb } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { Card, CardContent } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { PLATFORM_GOALS } from "@/lib/profile";
import { fetchTikTokIdeas, type TikTokIdea } from "@/lib/tiktok";
import { IdeasList } from "./IdeasList";

export function TikTokIdeas() {
  const [ideas, setIdeas] = useState<TikTokIdea[] | null>(null);
  const [goals, setGoals] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      fetchTikTokIdeas(),
      api<{ setups?: { tiktok?: { goals?: string[] } } }>("/account/setup"),
    ])
      .then(([ideaPayload, setup]) => {
        if (cancelled) return;
        setIdeas(ideaPayload.data);
        setGoals(setup.setups?.tiktok?.goals ?? []);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : "Something went wrong. We're checking what happened.");
        setIdeas([]);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const chips = goals.map((value) => PLATFORM_GOALS.tiktok.find((goal) => goal.value === value)?.label ?? value);

  return (
    <div className="space-y-6">
      <DeskHeading icon={Lightbulb} title="Ideas">
        <p>These are ideas that fit your niche and goals. The next clip uses the top one.</p>
      </DeskHeading>
      {chips.length > 0 && (
        <ul className="flex flex-wrap gap-2" aria-label="Goals">
          {chips.map((label) => (
            <li key={label} className="rounded-sm border border-border px-2 py-1 text-xs text-muted-foreground">
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
        <Card>
          <CardContent className="pt-6">
            <IdeasList ideas={ideas} title="Ranked for this account" />
          </CardContent>
        </Card>
      )}
    </div>
  );
}
