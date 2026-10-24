"use client";

import Link from "next/link";
import { Hash } from "lucide-react";
import { useEffect, useState } from "react";
import { DeskHeading } from "@/components/DeskHeading";
import { TimelineColumn, TimelineHeader } from "@/components/x/timeline";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import { usePlatform } from "@/lib/platform";

type Trend = {
  name: string;
  score?: number | string;
  opportunity_score?: number | string;
  growth_rate?: number | string;
  competition?: string;
  suggestion?: string;
};

export default function TrendsPage() {
  const platform = usePlatform();
  const [trends, setTrends] = useState<Trend[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ trends: Trend[] }>("/trends")
      .then((r) => setTrends(r.trends))
      .finally(() => setLoading(false));
  }, []);

  if (platform === "x") {
    return (
      <TimelineColumn>
        <TimelineHeader title="Topics" />
        {loading && <p className="px-4 py-8 text-[15px] text-[#71767b]">Loading topics…</p>}
        {!loading && trends.length === 0 && (
          <div className="px-8 py-12">
            <h2 className="text-[28px] font-extrabold leading-tight text-[#e7e9ea]">Nothing moving yet</h2>
            <p className="mt-2 text-[15px] text-[#71767b]">Subjects in your niche show up here when they are worth a post.</p>
          </div>
        )}
        {trends.map((trend, index) => (
          <Link
            key={`${trend.name}-${index}`}
            href={`/content-studio?platform=x&format=tweet&topic=${encodeURIComponent(trend.name)}`}
            className="block border-b border-[#2f3336] px-4 py-3 hover:bg-white/[0.03]"
          >
            <p className="text-[13px] text-[#71767b]">
              In your niche
              {trend.competition ? ` · ${trend.competition} competition` : ""}
            </p>
            <p className="mt-0.5 text-[15px] font-bold leading-5 text-[#e7e9ea]">{trend.name}</p>
            {(trend.growth_rate != null || trend.opportunity_score != null) && (
              <p className="mt-0.5 text-[13px] text-[#71767b]">
                {[
                  trend.growth_rate != null
                    ? `${Number(trend.growth_rate) > 0 ? "Up" : Number(trend.growth_rate) < 0 ? "Down" : "Flat"} ${Math.abs(Number(trend.growth_rate)).toFixed(1)}%`
                    : null,
                  trend.opportunity_score != null ? `${Math.round(Number(trend.opportunity_score))} fit` : null,
                ]
                  .filter(Boolean)
                  .join(" · ")}
              </p>
            )}
            {trend.suggestion && <p className="mt-1 text-[15px] leading-5 text-[#e7e9ea]">{trend.suggestion}</p>}
          </Link>
        ))}
      </TimelineColumn>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <DeskHeading icon={Hash} title="Topics">
          <p className="text-muted-foreground">Subjects in your niche, scored for this account.</p>
        </DeskHeading>
        <Link href="/growth-agent">
          <Button variant="secondary">Open the queue</Button>
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            Topics
            <Badge variant="secondary">Scored for your niche</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {loading ? (
            <p className="text-muted-foreground">Loading trends…</p>
          ) : (
            trends.map((t, i) => (
              <div key={i} className="mb-4 rounded-lg border border-border p-4 last:mb-0">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium">{t.name}</span>
                  <div className="flex gap-2">
                    {t.opportunity_score != null && (
                      <Badge>{Math.round(Number(t.opportunity_score))} opp</Badge>
                    )}
                    <Badge variant="outline">Trend {Math.round(Number(t.score ?? 0))}</Badge>
                  </div>
                </div>
                <div className="text-sm text-muted-foreground">
                  Growth {Number(t.growth_rate ?? 0).toFixed(1)}% · {t.competition ?? "n/a"} competition
                </div>
                {t.suggestion && (
                  <p className="mt-2 text-sm text-muted-foreground">{t.suggestion}</p>
                )}
              </div>
            ))
          )}
        </CardContent>
      </Card>
    </div>
  );
}
