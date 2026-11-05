"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Shield } from "lucide-react";
import { DeskHeading } from "@/components/DeskHeading";
import { LoadingMark } from "@/components/LoadingMark";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import type { SafetyStatus } from "@/lib/settings";

function statusLabel(safety: SafetyStatus): { label: string; detail: string } {
  if (safety.frozen) {
    return {
      label: "Waiting",
      detail: safety.freeze_reason ?? "X asked this account to wait.",
    };
  }
  if (safety.quiet_room) {
    return {
      label: "Holding",
      detail: safety.quiet_reason ?? "The last posts got no replies. Mackie17 is waiting for a stronger moment.",
    };
  }
  return {
    label: "Protected",
    detail: "Mackie17 is pacing this account so posting stays human.",
  };
}

export default function SafetyPage() {
  const [safety, setSafety] = useState<SafetyStatus | null>(null);

  useEffect(() => {
    api<SafetyStatus>("/safety/status").then(setSafety);
  }, []);

  const status = safety ? statusLabel(safety) : null;

  return (
    <div className="space-y-6">
      <DeskHeading icon={Shield} title="Account safety">
        <p className="text-muted-foreground">
          Mackie17 paces this account, freezes when X says stop, and waits when the room is quiet
        </p>
      </DeskHeading>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-3">
            Status
            {status && <Badge variant="secondary">{status.label}</Badge>}
          </CardTitle>
          <CardDescription>
            Mackie17 keeps posting at a human pace for this plan.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {safety && status ? (
            <>
              {safety.frozen && (
                <p className="mb-4 rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm text-red-200">
                  {status.detail}
                </p>
              )}
              {safety.quiet_room && !safety.frozen && (
                <p className="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-200">
                  {status.detail}
                </p>
              )}
              {!safety.frozen && !safety.quiet_room && (
                <p className="text-sm text-muted-foreground">{status.detail}</p>
              )}
            </>
          ) : (
            <LoadingMark />
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>How Mackie17 protects the account</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm text-muted-foreground">
          <p>Posting, replies, and likes stay inside a human daily pace for this plan.</p>
          <p>If X returns a rate limit or a lock, Mackie17 freezes this account for hours instead of retrying.</p>
          <p>If the last posts got no replies, Mackie17 waits for a louder moment.</p>
          <p>Writes to X land a few uneven seconds apart, not on a clock.</p>
          <p>Mackie17 does not follow people.</p>
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
