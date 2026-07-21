"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";

type Stats = {
  followers: { total: number; today: number; this_week: number };
  impressions: { total: number; change_percent: number };
  engagement_rate: number;
  profile_visits: number;
  growth_score: number;
  posts_posted: number;
  posts_draft: number;
  connected_accounts: number;
  preview_metrics?: boolean;
  top_opportunity?: {
    trend_name: string;
    score: number;
    suggestion: string;
  } | null;
};

type Trend = {
  name: string;
  score?: number | string;
  opportunity_score?: number | string;
  growth_rate?: number | string;
  competition?: string;
  suggestion?: string;
};

export default function DashboardPage() {
  const router = useRouter();
  const [stats, setStats] = useState<Stats | null>(null);
  const [trends, setTrends] = useState<Trend[]>([]);
  const [note, setNote] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    async function load() {
      try {
        const me = await api<{ user: { account_profile?: { onboarding_completed?: boolean } } }>(
          "/auth/me"
        );
        if (!me.user.account_profile?.onboarding_completed) {
          router.replace("/onboarding");
          return;
        }
        const statsRes = await api<{ stats: Stats; note?: string }>("/dashboard/stats");
        const trendsRes = await api<{ trends: Trend[] }>("/trends");
        setStats(statsRes.stats);
        setNote(statsRes.note ?? "");
        setTrends(trendsRes.trends);
      } catch {
        setError("Could not load dashboard");
        router.replace("/login");
      }
    }
    load();
  }, [router]);

  if (error) {
    return <p className="text-muted-foreground">{error}</p>;
  }

  if (!stats) {
    return <p className="text-muted-foreground">Loading dashboard…</p>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Dashboard</h1>
          <p className="text-muted-foreground">Your growth overview on X</p>
        </div>
        {stats.preview_metrics && (
          <Badge variant="outline" className="border-amber-500/40 text-amber-300">
            Preview data. Connect X for live metrics
          </Badge>
        )}
      </div>

      {note && (
        <p className="text-sm text-muted-foreground">{note}</p>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          {
            label: "Followers",
            value: stats.followers.total.toLocaleString(),
            sub: `+${stats.followers.today} today · +${stats.followers.this_week} this week`,
          },
          {
            label: "Impressions",
            value: stats.impressions.total.toLocaleString(),
            sub: `${stats.impressions.change_percent}% vs last week`,
          },
          {
            label: "Growth score",
            value: `${stats.growth_score}/100`,
            sub: `${stats.engagement_rate}% engagement rate`,
          },
          {
            label: "Content",
            value: `${stats.posts_draft + stats.posts_posted}`,
            sub: `${stats.posts_draft} drafts · ${stats.posts_posted} posted`,
          },
        ].map((s) => (
          <Card key={s.label}>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">{s.label}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{s.value}</div>
              <p className="mt-1 text-xs text-muted-foreground">{s.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {stats.top_opportunity && (
        <Card className="border-primary/30 bg-primary/5">
          <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
            <div>
              <p className="text-sm font-medium text-primary">Top opportunity right now</p>
              <p className="text-lg font-semibold">{stats.top_opportunity.trend_name}</p>
              <p className="text-sm text-muted-foreground">{stats.top_opportunity.suggestion}</p>
            </div>
            <Badge className="text-base">{Math.round(stats.top_opportunity.score)}/100</Badge>
          </CardContent>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Trend opportunities</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {trends.length === 0 ? (
              <p className="text-sm text-muted-foreground">No trends yet for your niche.</p>
            ) : (
              trends.map((t, i) => (
                <div key={i} className="border-l-2 border-primary pl-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{t.name}</span>
                    {t.opportunity_score != null && (
                      <Badge variant="secondary">{Math.round(Number(t.opportunity_score))}</Badge>
                    )}
                  </div>
                  <div className="text-sm text-muted-foreground">
                    Trend {Math.round(Number(t.score ?? 0))} · Growth {Number(t.growth_rate ?? 0).toFixed(1)}% ·{" "}
                    {t.competition ?? "n/a"} competition
                  </div>
                  {t.suggestion && (
                    <p className="mt-1 text-xs text-muted-foreground">{t.suggestion}</p>
                  )}
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Quick actions</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <p className="text-sm text-muted-foreground">
              {stats.connected_accounts > 0
                ? "Your X account is connected. Xpulse is monitoring your niche."
                : "Connect X in Settings to unlock live posting and real metrics."}
            </p>
            <div className="flex flex-wrap gap-2">
              <Link href="/content-studio">
                <Button>Create content</Button>
              </Link>
              <Link href="/drafts">
                <Button variant="secondary">View drafts</Button>
              </Link>
              <Link href="/growth-agent">
                <Button variant="outline">Growth Engine</Button>
              </Link>
              <Link href="/analytics">
                <Button variant="outline">Analytics</Button>
              </Link>
              <Link href="/engagement">
                <Button variant="outline">Engagement</Button>
              </Link>
            </div>
            {!stats.connected_accounts && (
              <Link href="/settings" className="inline-block text-sm text-primary hover:underline">
                Connect X in Settings →
              </Link>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
