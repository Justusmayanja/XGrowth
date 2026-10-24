"use client";

import Link from "next/link";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import type { SafetyStatus } from "@/lib/settings";

type Props = {
  safety: SafetyStatus | null;
  loading: boolean;
};

export function SafetySummaryCard({ safety, loading }: Props) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Safety limits</CardTitle>
        <CardDescription>Daily caps for this account, on every platform you connect</CardDescription>
      </CardHeader>
      <CardContent>
        {loading || !safety ? (
          <LoadingMark />
        ) : (
          <>
            <div className="mb-4 flex items-center gap-3">
              <span className="text-sm text-muted-foreground">Safety score</span>
              <Badge variant="secondary">{safety.safety_score}/100</Badge>
            </div>
            <Progress value={safety.safety_score} className="mb-4" />
            <ul className="mb-4 space-y-2 text-sm text-muted-foreground">
              {Object.entries(safety.actions).map(([action, data]) => (
                <li key={action} className="flex justify-between capitalize">
                  <span>{action.replace("_", " ")}</span>
                  <span>
                    {data.count} / {data.daily_limit}
                  </span>
                </li>
              ))}
            </ul>
            <Link href="/safety">
              <Button variant="outline" size="sm">
                Open limits
              </Button>
            </Link>
          </>
        )}
      </CardContent>
    </Card>
  );
}
