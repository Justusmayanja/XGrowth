"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { BarChart3 } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";

type DayPoint = {
  date: string;
  followers: number;
  impressions: number;
  engagement_rate: number;
};

type TopPost = {
  id: string;
  content: string;
  impressions: number;
  likes: number;
  reposts: number;
  replies: number;
  posted_at: string;
};

type Insight = { title: string; detail: string; type: string };

type AnalyticsData = {
  preview_metrics: boolean;
  summary: {
    followers: number;
    followers_change_7d: number;
    impressions_7d: number;
    avg_engagement_rate: number;
    profile_visits_7d: number;
  };
  series: DayPoint[];
  top_posts: TopPost[];
  insights: Insight[];
  note?: string | null;
};

function MiniChart({ series, field }: { series: DayPoint[]; field: keyof DayPoint }) {
  const values = series.map((d) => Number(d[field]));
  const max = Math.max(...values, 1);
  const recent = series.slice(-14);

  return (
    <div className="flex h-24 items-end gap-1">
      {recent.map((point) => {
        const value = Number(point[field]);
        const height = Math.max(8, (value / max) * 100);
        return (
          <div
            key={point.date}
            className="flex-1 rounded-sm bg-primary/70 transition hover:bg-primary"
            style={{ height: `${height}%` }}
            title={`${point.date}: ${value.toLocaleString()}`}
          />
        );
      })}
    </div>
  );
}

export default function AnalyticsPage() {
  const [data, setData] = useState<AnalyticsData | null>(null);

  useEffect(() => {
    api<AnalyticsData>("/analytics/growth").then(setData);
  }, []);

  if (!data) {
    return <p className="text-muted-foreground">Loading results…</p>;
  }

  const { summary } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <DeskHeading icon={BarChart3} title="Results">
          <p className="text-muted-foreground">
            Followers, impressions, and which posts held attention
          </p>
        </DeskHeading>
        {data.preview_metrics && (
          <Badge variant="outline" className="border-amber-500/40 text-amber-300">
            Sample numbers. Connect X for live totals.
          </Badge>
        )}
      </div>

      {data.note && <p className="text-sm text-muted-foreground">{data.note}</p>}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          {
            label: "Followers",
            value: summary.followers.toLocaleString(),
            sub: `${summary.followers_change_7d >= 0 ? "+" : ""}${summary.followers_change_7d} last 7 days`,
          },
          {
            label: "Impressions (7d)",
            value: summary.impressions_7d.toLocaleString(),
            sub: "Total reach this week",
          },
          {
            label: "Engagement rate",
            value: `${summary.avg_engagement_rate}%`,
            sub: "7-day average",
          },
          {
            label: "Profile visits (7d)",
            value: summary.profile_visits_7d.toLocaleString(),
            sub: "From posts and replies",
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

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Follower growth</CardTitle>
            <CardDescription>Last 14 days</CardDescription>
          </CardHeader>
          <CardContent>
            <MiniChart series={data.series} field="followers" />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Impressions</CardTitle>
            <CardDescription>Last 14 days</CardDescription>
          </CardHeader>
          <CardContent>
            <MiniChart series={data.series} field="impressions" />
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Top performing posts</CardTitle>
            <CardDescription>Ranked by impressions</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {data.top_posts.map((post, i) => (
              <div key={post.id} className="rounded-lg border border-border p-4">
                <div className="mb-2 flex items-center justify-between gap-2">
                  <Badge variant="secondary">#{i + 1}</Badge>
                  <span className="text-xs text-muted-foreground">
                    {new Date(post.posted_at).toLocaleDateString()}
                  </span>
                </div>
                <p className="mb-2 text-sm leading-relaxed">{post.content}</p>
                <p className="text-xs text-muted-foreground">
                  {post.impressions.toLocaleString()} impressions · {post.likes} likes · {post.reposts}{" "}
                  reposts · {post.replies} replies
                </p>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Growth insights</CardTitle>
            <CardDescription>Patterns from your activity</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {data.insights.map((insight) => (
              <div key={insight.title} className="border-t border-border pt-3 first:border-t-0 first:pt-0">
                <p className="font-medium">{insight.title}</p>
                <p className="text-sm text-muted-foreground">{insight.detail}</p>
              </div>
            ))}
            <Link href="/engagement">
              <Button variant="secondary" className="mt-2">
                Find engagement opportunities
              </Button>
            </Link>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
