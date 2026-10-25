"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { BarChart3 } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
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
  views_known?: boolean;
  summary: {
    followers: number | null;
    followers_change_7d: number | null;
    impressions_7d: number | null;
    avg_engagement_rate: number | null;
    profile_visits_7d: number | null;
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

function figure(value: number | null, suffix = "") {
  return value == null ? "—" : `${value.toLocaleString()}${suffix}`;
}

export default function AnalyticsPage() {
  const [data, setData] = useState<AnalyticsData | null>(null);

  useEffect(() => {
    api<AnalyticsData>("/analytics/growth").then(setData);
  }, []);

  if (!data) {
    return <LoadingMark className="min-h-[40vh]" />;
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
            Connect X for live totals.
          </Badge>
        )}
      </div>

      {data.note && <p className="text-sm text-muted-foreground">{data.note}</p>}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          {
            label: "Followers",
            value: figure(summary.followers),
            sub: "Today's total. X does not send the last 7 days.",
          },
          {
            label: "Impressions (7d)",
            value: figure(summary.impressions_7d),
            sub: data.views_known ? "Total reach this week" : "Views did not come back from X",
          },
          {
            label: "Engagement rate",
            value: summary.avg_engagement_rate == null ? "—" : `${summary.avg_engagement_rate}%`,
            sub: data.views_known ? "7-day average" : "Views did not come back from X",
          },
          {
            label: "Profile visits (7d)",
            value: figure(summary.profile_visits_7d),
            sub: data.views_known ? "From posts and replies" : "Views did not come back from X",
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
            <CardDescription>Today's total, repeated. X does not send a daily history.</CardDescription>
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
            {data.views_known === false ? (
              <p className="text-sm text-muted-foreground">Views did not come back from X.</p>
            ) : (
              <MiniChart series={data.series} field="impressions" />
            )}
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
            {data.top_posts.length === 0 && (
              <p className="text-sm text-muted-foreground">No posts from X yet.</p>
            )}
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
                  {data.views_known === false
                    ? `${post.likes} likes · ${post.reposts} reposts · ${post.replies} replies`
                    : `${post.impressions.toLocaleString()} impressions · ${post.likes} likes · ${post.reposts} reposts · ${post.replies} replies`}
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
