"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shield } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { LoadingMark } from "@/components/LoadingMark";
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
      <DeskHeading icon={Shield} title="Limits">
        <p className="text-muted-foreground">
          Daily caps so posting, replying, and following stay at a human pace
        </p>
      </DeskHeading>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-3">
            Safety score
            {safety && <Badge variant="secondary">{safety.safety_score}/100</Badge>}
          </CardTitle>
          <CardDescription>A higher score means today’s actions are still inside the caps</CardDescription>
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
                          Approaching daily limit. Mackie17 will slow down {action} activity
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            </>
          ) : (
            <LoadingMark />
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>What the caps do</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm text-muted-foreground">
          <p>Gaps between posts and replies are uneven, not a fixed minute.</p>
          <p>A draft that repeats something you already published is held.</p>
          <p>Posts, replies, follows, and likes each have a daily cap.</p>
          <p>The queue waits when a cap is reached, even if the score is high.</p>
          <Link href="/growth-agent">
            <Button variant="secondary" className="mt-4">
              Open the queue
            </Button>
          </Link>
        </CardContent>
      </Card>
    </div>
  );
}
