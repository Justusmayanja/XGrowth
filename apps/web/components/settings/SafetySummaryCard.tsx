"use client";

import Link from "next/link";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { SafetyStatus } from "@/lib/settings";

type Props = {
  safety: SafetyStatus | null;
  loading: boolean;
};

function statusLabel(safety: SafetyStatus): { label: string; detail: string } {
  if (safety.frozen) {
    return { label: "Waiting", detail: safety.freeze_reason ?? "X asked this account to wait." };
  }
  if (safety.quiet_room) {
    return {
      label: "Holding",
      detail: safety.quiet_reason ?? "Mackie17 is waiting for a stronger moment.",
    };
  }
  return { label: "Protected", detail: "Mackie17 is pacing this account so posting stays human." };
}

export function SafetySummaryCard({ safety, loading }: Props) {
  const status = safety ? statusLabel(safety) : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Account safety</CardTitle>
        <CardDescription>Pace, freeze, and holds for this account</CardDescription>
      </CardHeader>
      <CardContent>
        {loading || !safety || !status ? (
          <LoadingMark />
        ) : (
          <>
            <div className="mb-3 flex items-center gap-3">
              <span className="text-sm text-muted-foreground">Status</span>
              <Badge variant="secondary">{status.label}</Badge>
            </div>
            {safety.frozen && (
              <p className="mb-3 text-sm text-red-300">{status.detail}</p>
            )}
            {safety.quiet_room && !safety.frozen && (
              <p className="mb-3 text-sm text-amber-300">{status.detail}</p>
            )}
            {!safety.frozen && !safety.quiet_room && (
              <p className="mb-4 text-sm text-muted-foreground">{status.detail}</p>
            )}
            <Link href="/safety">
              <Button variant="outline" size="sm">
                Open account safety
              </Button>
            </Link>
          </>
        )}
      </CardContent>
    </Card>
  );
}
