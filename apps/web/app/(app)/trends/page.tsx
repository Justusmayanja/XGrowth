"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";

type Trend = {
  name: string;
  score?: number | string;
  opportunity_score?: number | string;
  growth_rate?: number | string;
  competition?: string;
  suggestion?: string;
};

export default function TrendsPage() {
  const [trends, setTrends] = useState<Trend[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ trends: Trend[] }>("/trends")
      .then((r) => setTrends(r.trends))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Trend Explorer</h1>
          <p className="text-muted-foreground">
            Topics ranked by opportunity score for your niche
          </p>
        </div>
        <Link href="/growth-agent">
          <Button variant="secondary">Open Growth Engine</Button>
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            Live trends
            <Badge variant="secondary">Personalized scores</Badge>
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
