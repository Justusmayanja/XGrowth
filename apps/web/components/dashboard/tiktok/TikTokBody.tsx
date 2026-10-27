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

function currentStep(tiktok: TikTokDesk): "ideas" | "written" | "waiting" | "confirmed" | "posted" {
  const status = tiktok.clip?.status;
  if (status === "posted") return "posted";
  if (status === "queued") return "confirmed";
  if (status === "waiting") return "waiting";
  if (tiktok.pipeline.written > 0) return "written";
  return "ideas";
}

function timeHasPassed(iso: string | null | undefined): boolean {
  if (!iso) return false;
  const date = new Date(iso);
  return !Number.isNaN(date.getTime()) && date.getTime() < Date.now();
}

function Meter({ label, used, limit, detail }: { label: string; used: number; limit: number; detail: string }) {
  const width = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3 text-xs">
        <span className="text-muted-foreground">{label}</span>
        <span className="tabular-nums">
          {used} of {limit}
        </span>
      </div>
      <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted" aria-hidden>
        <div className="h-full bg-foreground/70" style={{ width: `${width}%` }} />
      </div>
      <p className="mt-2 text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}

function DeskRail({ tiktok }: { tiktok: TikTokDesk }) {
  const zone = tiktok.timezone.value || "UTC";
  const renews = tiktok.allowance.period_end ? formatWhen(tiktok.allowance.period_end, zone) : "when the period ends";
  const capDetail = tiktok.daily_cap.reached
    ? `You can confirm again at ${formatWhen(tiktok.daily_cap.available_at, zone)}.`
    : "Confirm up to this many clips in a rolling day.";

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="space-y-5 pt-6">
          <Meter
            label="This month"
            used={tiktok.allowance.used}
            limit={tiktok.allowance.limit}
            detail={tiktok.allowance.plan_found ? `Renews ${renews}.` : "TikTok is not on this account."}
          />
          <Meter label="Today" used={tiktok.daily_cap.used} limit={tiktok.daily_cap.cap} detail={capDetail} />
          <p className="text-xs text-muted-foreground">
            Times use {tiktok.timezone.assumed ? "UTC until you set a timezone" : zone}.
          </p>
        </CardContent>
      </Card>
      <Card>
        <CardContent className="pt-6">
          <IdeasList ideas={tiktok.ideas} />
        </CardContent>
      </Card>
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
  const timePassed = clip?.status === "waiting" && timeHasPassed(clip.suggested_for);

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
    <div className="space-y-6 pb-28 md:pb-0">
      <div aria-live="polite" className="sr-only">
        {live}
      </div>
      <PipelineStrip pipeline={tiktok.pipeline} current={currentStep(tiktok)} />
      <div className="grid items-start gap-6 lg:grid-cols-3">
      <div className="min-w-0 space-y-6 lg:col-span-2">

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
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6">
          {clip.status === "queued" && (
            <QueuedPanel blockers={clip.blockers} connection={tiktok.connection} headingRef={headingRef} />
          )}
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
          {clip.status === "waiting" && timePassed && (
            <p className="text-sm">This time has passed. Pick a new one.</p>
          )}
          <ClipCard clip={clip} collapsed={clip.status === "queued" || clip.status === "posted"} />
          {clip.status === "waiting" && (clip.timezone_assumed || tiktok.timezone.assumed) && (
            <TimezoneNotice busy={busy === "zone"} onUse={(name) => void useZone(name)} />
          )}
          {clip.status === "waiting" && !timePassed && (
            <p className="text-sm">Suggested time: {formatWhen(clip.suggested_for, zone)}.</p>
          )}
          {clip.status === "waiting" && (
            <ConfirmBar
              time={formatWhen(clip.suggested_for, zone)}
              label={timePassed ? "Yes, go ahead" : undefined}
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
                initiallyOpen={timePassed}
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
      <DeskRail tiktok={tiktok} />
      </div>
    </div>
  );
}
