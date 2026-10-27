"use client";

import { useState, type FormEvent } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Slider } from "@/components/ui/slider";

export function ResultsForm({
  busy,
  open,
  fieldErrors,
  onOpen,
  onSubmit,
}: {
  busy: boolean;
  open: boolean;
  fieldErrors: Record<string, string>;
  onOpen: () => void;
  onSubmit: (values: { views: number; completion_percent: number; follows: number }) => void;
}) {
  const [views, setViews] = useState("");
  const [percent, setPercent] = useState(40);
  const [follows, setFollows] = useState("");
  const [local, setLocal] = useState<Record<string, string>>({});

  function submit(event: FormEvent) {
    event.preventDefault();
    const next: Record<string, string> = {};
    if (!/^\d+$/.test(views)) next.views = "Enter views as a whole number.";
    if (!/^\d+$/.test(follows)) next.follows = "Enter new followers as a whole number.";
    if (percent < 0 || percent > 100) next.completion_percent = "Percent watched is between 0 and 100.";
    setLocal(next);
    if (Object.keys(next).length > 0) return;
    onSubmit({ views: Number(views), completion_percent: percent, follows: Number(follows) });
  }

  if (!open) {
    return (
      <Button type="button" variant="outline" className="min-h-11" onClick={onOpen}>
        Posted it yourself? Add how it did.
      </Button>
    );
  }

  const errors = { ...local, ...fieldErrors };

  return (
    <form className="space-y-4 rounded-sm border border-border p-4" onSubmit={submit}>
      <h3 className="text-sm font-medium">Posted it yourself? Add how it did.</h3>
      <p className="text-sm text-muted-foreground">Find these in TikTok's analytics for this video.</p>
      <label className="block text-sm" htmlFor="tiktok-views">
        Views
        <Input
          id="tiktok-views"
          className="mt-1"
          inputMode="numeric"
          value={views}
          aria-invalid={Boolean(errors.views)}
          onChange={(event) => setViews(event.target.value)}
        />
      </label>
      {errors.views && <p className="text-sm">{errors.views}</p>}
      <div>
        <div className="flex items-center justify-between gap-3">
          <label id="tiktok-percent-label" className="text-sm" htmlFor="tiktok-percent">
            Percent watched
          </label>
          <Input
            id="tiktok-percent"
            className="h-11 w-24"
            inputMode="numeric"
            value={String(percent)}
            aria-invalid={Boolean(errors.completion_percent)}
            onChange={(event) => {
              const next = Number(event.target.value);
              if (event.target.value === "") setPercent(0);
              else if (!Number.isNaN(next)) setPercent(Math.min(100, Math.max(0, next)));
            }}
          />
        </div>
        <Slider
          className="mt-2"
          value={percent}
          labelledBy="tiktok-percent-label"
          valueText={`${percent} percent watched`}
          onChange={setPercent}
        />
      </div>
      {errors.completion_percent && <p className="text-sm">{errors.completion_percent}</p>}
      <label className="block text-sm" htmlFor="tiktok-follows">
        New followers
        <Input
          id="tiktok-follows"
          className="mt-1"
          inputMode="numeric"
          value={follows}
          aria-invalid={Boolean(errors.follows)}
          onChange={(event) => setFollows(event.target.value)}
        />
      </label>
      {errors.follows && <p className="text-sm">{errors.follows}</p>}
      <Button type="submit" className="min-h-11" disabled={busy} aria-busy={busy}>
        {busy && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
        Save and get my next idea
      </Button>
    </form>
  );
}
