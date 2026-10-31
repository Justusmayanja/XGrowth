"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { ListOrdered } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { LoadingMark } from "@/components/LoadingMark";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";

type Decision = {
  id?: number;
  decision: string;
  action_taken?: string | null;
  reason?: string;
  opportunity_score?: number | null;
  context?: { trend_name?: string } | null;
  created_at?: string;
};

type Opportunity = {
  name: string;
  opportunity_score?: number | null;
  figures?: string;
  suggestion: string;
  competition?: string;
};

type Settings = {
  mode: string;
  automation_level: number;
  max_silence_minutes: number;
  engine_paused?: boolean;
  auto_post_enabled?: boolean;
  auto_reply_enabled?: boolean;
  auto_engage_enabled?: boolean;
};

const actionLabel: Record<string, string> = {
  posted: "Published",
  held_low_virality: "Held",
  suggest_draft: "Draft",
  draft_prepared: "Draft",
  publish_failed: "Not published",
  draft_failed: "No draft",
  resting: "Waiting",
};

const decisionVariant: Record<string, "default" | "secondary" | "outline" | "destructive"> = {
  POST: "default",
  ENGAGE: "secondary",
  WAIT: "outline",
  IGNORE: "outline",
};

export default function GrowthAgentPage() {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [decisions, setDecisions] = useState<Decision[]>([]);
  const [opportunities, setOpportunities] = useState<Opportunity[]>([]);
  const [emptyMessage, setEmptyMessage] = useState("");
  const [evaluating, setEvaluating] = useState(false);

  const load = useCallback(async () => {
    const [s, f, o] = await Promise.all([
      api<{ settings: Settings }>("/growth-agent/settings"),
      api<{ decisions: Decision[]; empty_message?: string }>("/growth-agent/feed"),
      api<{ opportunities: Opportunity[] }>("/dashboard/opportunities"),
    ]);
    setSettings(s.settings);
    setDecisions(f.decisions);
    setEmptyMessage(f.empty_message ?? "");
    setOpportunities(o.opportunities);
  }, []);

  useEffect(() => {
    load().catch(() => toast.error("Could not load the queue"));
  }, [load]);

  async function runEvaluation() {
    setEvaluating(true);
    try {
      const res = await api<{ decision: string; latest: Decision }>("/growth-agent/evaluate", {
        method: "POST",
      });
      toast.success(`Evaluation complete: ${res.decision}`);
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Evaluation failed");
    } finally {
      setEvaluating(false);
    }
  }

  if (!settings) {
    return <LoadingMark className="min-h-[40vh]" />;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <DeskHeading icon={ListOrdered} title="Queue">
          <p className="text-muted-foreground">
            When Mackie17 drafts, waits, or publishes.{" "}
            <Link href="/settings" className="text-foreground underline underline-offset-4">
              Change that in Settings
            </Link>
          </p>
        </DeskHeading>
        <Button onClick={runEvaluation} disabled={evaluating}>
          {evaluating ? "Scoring…" : "Score the niche now"}
        </Button>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>X posting</CardTitle>
            <CardDescription>The rules saved in Settings. Scoring uses these.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <p className="font-medium">
              {settings.engine_paused
                ? "Paused"
                : settings.automation_level >= 3
                  ? "Full automation"
                  : settings.automation_level === 2
                    ? "Strong moments"
                    : "Controlled"}
            </p>
            <p className="text-muted-foreground">
              {settings.engine_paused
                ? "Nothing is posted until you turn pause off."
                : settings.automation_level >= 3 && settings.auto_post_enabled
                  ? "A strong moment can be published while you are offline. Some big accounts are skipped. A quiet timeline stays quiet. Nothing is due at a set hour."
                  : settings.automation_level === 2 && settings.auto_post_enabled
                    ? "Only a strong draft is published. The rest wait for you."
                    : "Drafts wait for your yes."}
            </p>
            <ul className="space-y-1 text-muted-foreground">
              <li>Post on its own: {settings.auto_post_enabled ? "On" : "Off"}</li>
              <li>Replies: {settings.auto_reply_enabled ? "On" : "Off"}</li>
              <li>Likes: {settings.auto_engage_enabled ? "On" : "Off"}</li>
              <li>Likes and replies only sometimes</li>
              <li>A quiet timeline stays quiet</li>
            </ul>
            <Link href="/settings?platform=x">
              <Button type="button" variant="outline" size="sm">
                Change in Settings
              </Button>
            </Link>
          </CardContent>
        </Card>

        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Top opportunities</CardTitle>
            <CardDescription>From your X account</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {opportunities.length === 0 ? (
              <div className="space-y-3 text-sm text-muted-foreground">
                <p>Nothing from X yet. Connect the account, or open Topics.</p>
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="secondary" onClick={runEvaluation} disabled={evaluating}>
                    Score the niche
                  </Button>
                  <Link href="/trends">
                    <Button size="sm" variant="outline">
                      Topics
                    </Button>
                  </Link>
                </div>
              </div>
            ) : (
              opportunities.map((o) => (
                <div key={o.name} className="rounded-lg border border-border p-3">
                  <div className="mb-1 flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{o.name}</span>
                    {o.figures ? (
                      <Badge variant="secondary">{o.figures}</Badge>
                    ) : o.opportunity_score != null ? (
                      <Badge variant={o.opportunity_score >= 82 ? "default" : "secondary"}>
                        {Math.round(o.opportunity_score)}
                      </Badge>
                    ) : null}
                  </div>
                  {o.suggestion ? <p className="text-xs text-muted-foreground">{o.suggestion}</p> : null}
                </div>
              ))
            )}
            <Link href="/trends" className="text-sm text-primary hover:underline">
              View all trends →
            </Link>
          </CardContent>
        </Card>

        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Recent activity</CardTitle>
            <CardDescription>Latest decisions</CardDescription>
          </CardHeader>
          <CardContent>
            {decisions.length === 0 ? (
              <div className="space-y-3 text-sm text-muted-foreground">
                <p>{emptyMessage || "No decisions yet. Run an evaluation to get started."}</p>
                <Button size="sm" variant="secondary" onClick={runEvaluation} disabled={evaluating}>
                  Run evaluation
                </Button>
              </div>
            ) : (
              decisions.map((d, i) => (
                <div key={d.id ?? i} className="border-t border-border py-3 first:border-t-0 first:pt-0">
                  <div className="mb-1 flex flex-wrap items-center gap-2">
                    <Badge variant={decisionVariant[d.decision] ?? "outline"}>{d.decision}</Badge>
                    {d.action_taken && actionLabel[d.action_taken] && (
                      <span className="text-xs text-muted-foreground">{actionLabel[d.action_taken]}</span>
                    )}
                    {d.opportunity_score != null && (
                      <span className="text-xs text-muted-foreground">
                        Score {Math.round(Number(d.opportunity_score))}
                      </span>
                    )}
                  </div>
                  <p className="text-sm">{d.reason}</p>
                  {d.created_at && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      {new Date(d.created_at).toLocaleString()}
                    </p>
                  )}
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
