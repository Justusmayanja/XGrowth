"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
  opportunity_score: number;
  suggestion: string;
  competition?: string;
};

type Settings = {
  mode: string;
  automation_level: number;
  max_silence_minutes: number;
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
  const [mode, setMode] = useState("reactive");
  const [automationLevel, setAutomationLevel] = useState("1");
  const [maxSilence, setMaxSilence] = useState("60");

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
    setMode(s.settings.mode);
    setAutomationLevel(String(s.settings.automation_level));
    setMaxSilence(String(s.settings.max_silence_minutes));
  }, []);

  useEffect(() => {
    load().catch(() => toast.error("Could not load Growth Engine"));
  }, [load]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    try {
      const updated = await api<{ settings: Settings }>("/growth-agent/settings", {
        method: "PUT",
        body: JSON.stringify({
          mode,
          automation_level: Number(automationLevel),
          max_silence_minutes: Number(maxSilence),
        }),
      });
      setSettings(updated.settings);
      toast.success("Preferences saved");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Could not save");
    }
  }

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
    return <p className="text-muted-foreground">Loading Growth Engine…</p>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Growth Engine</h1>
          <p className="text-muted-foreground">How Xpulse scores moments and decides when to act</p>
        </div>
        <Button onClick={runEvaluation} disabled={evaluating}>
          {evaluating ? "Evaluating…" : "Run evaluation now"}
        </Button>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Your preferences</CardTitle>
            <CardDescription>Controls how aggressively Xpulse acts</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={onSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label>Mode</Label>
                <Select value={mode} onValueChange={(v) => v && setMode(v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="reactive">Reactive — news & events first</SelectItem>
                    <SelectItem value="proactive">Proactive — original content when quiet</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Hands-on level</Label>
                <Select value={automationLevel} onValueChange={(v) => v && setAutomationLevel(v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="0">0 — Suggest only</SelectItem>
                    <SelectItem value="1">1 — Semi-auto (drafts for you)</SelectItem>
                    <SelectItem value="2">2 — Mostly auto</SelectItem>
                    <SelectItem value="3">3 — Full auto</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="max_silence">Max silence (minutes)</Label>
                <Select value={maxSilence} onValueChange={(v) => v && setMaxSilence(v)}>
                  <SelectTrigger id="max_silence">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="30">30</SelectItem>
                    <SelectItem value="60">60</SelectItem>
                    <SelectItem value="120">120</SelectItem>
                    <SelectItem value="240">240</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Button type="submit">Save preferences</Button>
            </form>
          </CardContent>
        </Card>

        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle>Top opportunities</CardTitle>
            <CardDescription>Scored for your niche right now</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {opportunities.length === 0 ? (
              <div className="space-y-3 text-sm text-muted-foreground">
                <p>No opportunities yet. Complete onboarding or run an evaluation.</p>
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="secondary" onClick={runEvaluation} disabled={evaluating}>
                    Run evaluation
                  </Button>
                  <Link href="/trends">
                    <Button size="sm" variant="outline">
                      Trend Explorer
                    </Button>
                  </Link>
                </div>
              </div>
            ) : (
              opportunities.map((o) => (
                <div key={o.name} className="rounded-lg border border-border p-3">
                  <div className="mb-1 flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{o.name}</span>
                    <Badge variant={o.opportunity_score >= 82 ? "default" : "secondary"}>
                      {Math.round(o.opportunity_score)}
                    </Badge>
                  </div>
                  <p className="text-xs text-muted-foreground">{o.suggestion}</p>
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
            <CardDescription>Latest decisions from the Growth Engine</CardDescription>
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
                <div key={d.id ?? i} className="mb-4 border-l-2 border-primary pl-3 last:mb-0">
                  <div className="mb-1 flex flex-wrap items-center gap-2">
                    <Badge variant={decisionVariant[d.decision] ?? "outline"}>{d.decision}</Badge>
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
