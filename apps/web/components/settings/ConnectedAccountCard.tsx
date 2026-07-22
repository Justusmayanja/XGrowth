"use client";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import type { XConnectStatus } from "@/lib/settings";

type Props = {
  status: XConnectStatus | null;
};

export function ConnectedAccountCard({ status }: Props) {
  function connect() {
    if (status?.authorize_url) {
      window.location.href = status.authorize_url;
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Connected X account</CardTitle>
        <CardDescription>
          Link your X account so Xpulse can read metrics and publish when you approve
        </CardDescription>
      </CardHeader>
      <CardContent>
        {status?.connected ? (
          <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-4">
            <div className="flex items-center gap-2">
              <Badge variant="secondary">Connected</Badge>
              {status.username && <span className="font-medium">@{status.username}</span>}
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{status.message}</p>
            <Button variant="outline" size="sm" className="mt-3" disabled>
              Disconnect (coming soon)
            </Button>
          </div>
        ) : (
          <>
            <p className="mb-4 text-sm text-muted-foreground">{status?.message ?? "Checking…"}</p>
            <ul className="mb-4 space-y-1 text-sm text-muted-foreground">
              <li>Read profile and public metrics</li>
              <li>Draft replies and posts for your review</li>
              <li>Publish only when you approve or enable automation</li>
            </ul>
            <Button
              variant="secondary"
              onClick={connect}
              disabled={!status?.authorize_url}
            >
              {status?.authorize_url ? "Connect X account" : "Connect X (coming soon)"}
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}
