"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ApiError } from "@/lib/api";
import {
  confirmClip,
  discardClip,
  formatWhen,
  postUrl,
  rescheduleClip,
  runTikTok,
  saveTimezone,
  submitResults,
  type TikTokDesk,
  type TikTokRun,
} from "@/lib/tiktok";
import { BlockNotice } from "./BlockNotice";
import { ClipCard } from "./ClipCard";
import { ConfirmBar } from "./ConfirmBar";
import { IdeasList } from "./IdeasList";
import { LearnedPanel } from "./LearnedPanel";
import { PipelineStrip } from "./PipelineStrip";
import { QueuedPanel } from "./QueuedPanel";
import { ReschedulePopover } from "./ReschedulePopover";
import { ResultsForm } from "./ResultsForm";
import { TimezoneNotice } from "./TimezoneNotice";

export type TikTokReview = {
  writing?: boolean;
  resultsOpen?: boolean;
  learned?: { note: string; next: TikTokRun | null } | null;
};

function SkeletonCard() {
  return (
    <div className="space-y-3 rounded-sm border border-border p-6" aria-busy="true" aria-live="polite">
      <p className="text-sm">Writing your clip…</p>
      <div className="h-6 w-2/3 rounded-sm bg-muted motion-safe:animate-pulse" />
      <div className="h-4 w-full rounded-sm bg-muted motion-safe:animate-pulse" />
      <div className="h-4 w-5/6 rounded-sm bg-muted motion-safe:animate-pulse" />
      <div className="h-24 w-full rounded-sm bg-muted motion-safe:animate-pulse" />
    </div>
  );
}

function SkipDialog({ busy, onKeep, onSkip }: { busy: boolean; onKeep: () => void; onSkip: () => void }) {
  return (
    <div role="dialog" aria-labelledby="skip-clip-title" className="max-w-md space-y-3 rounded-sm border border-border p-4">
      <p id="skip-clip-title" className="text-sm">
        Skip this clip? You can get a new idea right away.
      </p>
      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={onSkip}>
          {busy && <Loader2 className="motion-safe:animate-spin" aria-hidden />}
          Skip clip
        </Button>
        <Button type="button" variant="ghost" className="min-h-11" onClick={onKeep}>
          Keep it
        </Button>
      </div>
    </div>
  );
}

export function TikTokBody({
  tiktok,
  onReload,
  review,
}: {
  tiktok: TikTokDesk;
  onReload?: () => Promise<void>;
  review?: TikTokReview;
}) {
  const router = useRouter();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const [writing, setWriting] = useState(Boolean(review?.writing));
  const [busy, setBusy] = useState<string | null>(null);
  const [skipping, setSkipping] = useState(false);
  const [resultsOpen, setResultsOpen] = useState(Boolean(review?.resultsOpen));
  const [learned, setLearned] = useState(review?.learned ?? null);
  const [inline, setInline] = useState<string | null>(null);
  const [timeError, setTimeError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [focusConfirmed, setFocusConfirmed] = useState(false);
  const [live, setLive] = useState("");

  const clip = tiktok.clip;
  const zone = clip?.timezone || tiktok.timezone.value || "UTC";

  useEffect(() => {
    if (focusConfirmed && clip?.status === "queued") {
      headingRef.current?.focus();
      setFocusConfirmed(false);
    }
  }, [focusConfirmed, clip?.status, clip?.id]);

  function fail(err: unknown, where: "confirm" | "time" | "form" | "run") {
    const error = err instanceof ApiError ? err : new ApiError("Something went wrong. We're checking what happened.", 0);
    if (error.status === 401) {
      router.replace("/login");
      return;
    }
    if (error.status === 409 || error.code === "invalid_state" || error.code === "already_learned") {
      toast("This clip changed. Here's where it is now.");
      return;
    }
    if (error.status === 404) {
      toast("That clip isn't available any more.");
      return;
    }
    if (where === "time" || error.code === "invalid_time") {
      setTimeError(error.message);
      return;
    }
    if (where === "form" && Object.keys(error.fields).length > 0) {
      setFieldErrors(error.fields);
      return;
    }
    if (error.code === "no_plan" || error.status === 402) {
      setInline(error.message);
      return;
    }
    setInline(error.message);
  }

  async function reload() {
    if (onReload) await onReload();
  }

  async function findIdea() {
    if (!onReload) return;
    setWriting(true);
    setInline(null);
    try {
      const result = await runTikTok();
      if (result.outcome !== "created") setInline(result.message);
      await reload();
    } catch (err) {
      fail(err, "run");
      await reload();
    } finally {
      setWriting(false);
    }
  }

  async function confirm() {
    if (!clip || !onReload) return;
    setBusy("confirm");
    setInline(null);
    try {
      await confirmClip(clip.id);
      setLive("Confirmed. Not posted yet.");
      setFocusConfirmed(true);
      await reload();
    } catch (err) {
      fail(err, "confirm");
      await reload();
    } finally {
      setBusy(null);
    }
  }

  async function skip() {
    if (!clip || !onReload) return;
    setBusy("skip");
    try {
      await discardClip(clip.id);
      setSkipping(false);
      await reload();
    } catch (err) {
      fail(err, "confirm");
      await reload();
    } finally {
      setBusy(null);
    }
  }

  async function reschedule(iso: string) {
    if (!clip || !onReload) return;
    setBusy("time");
    setTimeError(null);
    try {
      await rescheduleClip(clip.id, iso);
      await reload();
    } catch (err) {
      fail(err, "time");
    } finally {
      setBusy(null);
    }
  }

  async function useZone(zoneName: string) {
    if (!onReload) return;
    setBusy("zone");
    try {
      await saveTimezone(zoneName);
      await reload();
    } catch (err) {
      fail(err, "confirm");
    } finally {
      setBusy(null);
    }
  }

  async function saveResults(values: { views: number; completion_percent: number; follows: number }) {
    if (!clip || !onReload) return;
    setBusy("results");
    setFieldErrors({});
    try {
      const result = await submitResults(clip.id, values);
      setLearned({ note: result.data.learning_note || "Results saved.", next: result.next });
      setLive(result.data.learning_note || "Results saved.");
      await reload();
    } catch (err) {
      fail(err, "form");
      await reload();
    } finally {
      setBusy(null);
    }
  }

  const allowanceSpent = tiktok.allowance.plan_found && tiktok.allowance.remaining <= 0;
  const capReached = tiktok.daily_cap.reached;
  let confirmReason = inline;
  if (!confirmReason && capReached) {
    confirmReason = `You've reached today's limit. You can confirm again at ${formatWhen(tiktok.daily_cap.available_at, zone)}.`;
  } else if (!confirmReason && allowanceSpent) {
    const renews = tiktok.allowance.period_end?.slice(0, 10);
    confirmReason = `You've used all ${tiktok.allowance.limit} clips for this month.${renews ? ` Your allowance renews on ${renews}.` : ""}`;
  } else if (!confirmReason && !tiktok.allowance.plan_found) {
    confirmReason = "TikTok is not on this account.";
  }

  const showResults = clip?.status === "queued" || clip?.status === "posted";

  return (
    <div className="space-y-6">
      <div aria-live="polite" className="sr-only">
        {live}
      </div>
      <PipelineStrip pipeline={tiktok.pipeline} />

      {writing ? (
        <SkeletonCard />
      ) : learned ? (
        <LearnedPanel note={learned.note} next={learned.next} onContinue={() => setLearned(null)} />
      ) : !clip && tiktok.block ? (
        <BlockNotice block={tiktok.block} />
      ) : !clip ? (
        <Card>
          <CardContent className="space-y-5 pt-6">
            <div>
              <h2 className="text-base font-medium">No clip yet</h2>
              <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                We'll look for an idea that fits your niche and write the clip. Nothing goes out without your yes.
              </p>
            </div>
            <Button type="button" className="min-h-11" disabled={!onReload} aria-busy={false} onClick={() => void findIdea()}>
              Find an idea
            </Button>
            {inline && (
              <div className="space-y-2">
                <p className="text-sm" role="status">
                  {inline}
                </p>
                {(tiktok.block?.action === "billing" || inline === "TikTok is not on this account.") && (
                  <Link href="/pricing" className="inline-block text-sm underline underline-offset-4">
                    See plans
                  </Link>
                )}
              </div>
            )}
            <IdeasList ideas={tiktok.ideas} />
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6">
          {clip.status === "queued" && <QueuedPanel blockers={clip.blockers} headingRef={headingRef} />}
          {clip.status === "posted" && (
            <Card>
              <CardContent className="pt-6">
                <h2 className="text-base font-medium">Posted</h2>
                <p className="mt-2 text-sm text-muted-foreground">Posted {formatWhen(clip.posted_at, zone)}.</p>
                {postUrl(clip.platform_post_id) && (
                  <a className="mt-2 inline-block text-sm underline underline-offset-4" href={postUrl(clip.platform_post_id)!}>
                    Open the post
                  </a>
                )}
              </CardContent>
            </Card>
          )}
          {clip.status === "waiting" && (
            <p className="text-xs text-muted-foreground">Waiting for your yes</p>
          )}
          <ClipCard clip={clip} collapsed={clip.status === "queued" || clip.status === "posted"} />
          {clip.status === "waiting" && (clip.timezone_assumed || tiktok.timezone.assumed) && (
            <TimezoneNotice busy={busy === "zone"} onUse={(name) => void useZone(name)} />
          )}
          {clip.status === "waiting" && (
            <p className="text-sm">Suggested time: {formatWhen(clip.suggested_for, zone)}.</p>
          )}
          {clip.status === "waiting" && (
            <ConfirmBar
              time={formatWhen(clip.suggested_for, zone)}
              busy={busy === "confirm"}
              disabled={capReached || allowanceSpent || !tiktok.allowance.plan_found}
              reason={confirmReason}
              onConfirm={() => void confirm()}
            />
          )}
          {confirmReason && !tiktok.allowance.plan_found && clip.status === "waiting" && (
            <Link href="/pricing" className="inline-block text-sm underline underline-offset-4">
              See plans
            </Link>
          )}
          {clip.status === "waiting" && (
            <div className="flex flex-col items-start gap-4 pt-6">
              <ReschedulePopover
                profileZone={tiktok.timezone.assumed ? null : tiktok.timezone.value}
                assumed={tiktok.timezone.assumed || clip.timezone_assumed}
                error={timeError}
                busy={busy === "time"}
                onSave={(iso) => void reschedule(iso)}
              />
              {skipping ? (
                <SkipDialog busy={busy === "skip"} onKeep={() => setSkipping(false)} onSkip={() => void skip()} />
              ) : (
                <Button type="button" variant="ghost" className="min-h-11" onClick={() => setSkipping(true)}>
                  Skip this idea
                </Button>
              )}
            </div>
          )}
          {clip.status === "queued" && (
            <div className="space-y-3">
              <p className="text-sm text-muted-foreground">Skipping frees this clip's slot for the month.</p>
              {skipping ? (
                <SkipDialog busy={busy === "skip"} onKeep={() => setSkipping(false)} onSkip={() => void skip()} />
              ) : (
                <Button type="button" variant="outline" className="min-h-11" onClick={() => setSkipping(true)}>
                  Skip this clip
                </Button>
              )}
            </div>
          )}
          {showResults && (
            <ResultsForm
              busy={busy === "results"}
              open={resultsOpen}
              fieldErrors={fieldErrors}
              onOpen={() => setResultsOpen(true)}
              onSubmit={(values) => void saveResults(values)}
            />
          )}
        </div>
      )}

      {!learned && tiktok.recent.length > 0 && (
        <section aria-label="Recent results">
          <h2 className="text-sm font-medium">Recent results</h2>
          <ul className="mt-3 space-y-3">
            {tiktok.recent.map((item) => (
              <li key={item.id} className="text-sm break-words text-muted-foreground">
                <span className="text-foreground">{item.topic || item.hook}</span>
                {item.learning_note ? ` — ${item.learning_note}` : ""}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
