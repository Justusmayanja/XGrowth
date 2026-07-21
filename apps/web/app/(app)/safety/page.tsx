"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { api } from "@/lib/api";

export default function SafetyPage() {
  const [safety, setSafety] = useState<{
    safety_score: number;
    actions: Record<string, { count: number; daily_limit: number }>;
  } | null>(null);

  useEffect(() => {
    api<{ safety_score: number; actions: Record<string, { count: number; daily_limit: number }> }>(
      "/safety/status"
    ).then(setSafety);
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Safety Center</h1>
        <p className="text-muted-foreground">
          Account pacing and limits — Xpulse protects you from risky patterns
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-3">
            Safety score
            {safety && <Badge variant="secondary">{safety.safety_score}/100</Badge>}
          </CardTitle>
          <CardDescription>Higher is better — means your account is well within safe limits</CardDescription>
        </CardHeader>
        <CardContent>
          {safety ? (
            <>
              <Progress value={safety.safety_score} className="mb-6" />
              <div className="grid gap-3 sm:grid-cols-2">
                {Object.entries(safety.actions).map(([action, data]) => {
                  const pct = data.daily_limit > 0 ? (data.count / data.daily_limit) * 100 : 0;
                  const warn = pct >= 80;
                  return (
                    <div
                      key={action}
                      className={`rounded-lg border p-4 ${warn ? "border-amber-500/40 bg-amber-500/5" : "border-border"}`}
                    >
                      <div className="mb-2 flex items-center justify-between">
                        <span className="text-sm font-medium capitalize">{action}</span>
                        <span className="text-sm text-muted-foreground">
                          {data.count} / {data.daily_limit}
                        </span>
                      </div>
                      <Progress value={pct} className="h-2" />
                      {warn && (
                        <p className="mt-2 text-xs text-amber-400">
                          Approaching daily limit — Xpulse will slow down {action} activity
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            </>
          ) : (
            <p className="text-muted-foreground">Loading safety data…</p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>How Xpulse keeps you safe</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm text-muted-foreground">
          <p>Natural delays between posts and replies — never spammy bursts</p>
          <p>Duplicate content detection before anything goes live</p>
          <p>Daily caps on posts, replies, follows, and likes</p>
          <p>Growth Engine waits when limits are reached, even if opportunity is high</p>
          <Link href="/growth-agent">
            <Button variant="secondary" className="mt-4">
              View Growth Engine activity
            </Button>
          </Link>
        </CardContent>
      </Card>
    </div>
  );
}
