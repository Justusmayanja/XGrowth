"use client";

import { useEffect, useState } from "react";
import { ProfileForm } from "@/components/settings/ProfileForm";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { api } from "@/lib/api";

export default function SettingsPage() {
  const [xMessage, setXMessage] = useState("");
  const [safety, setSafety] = useState<{
    safety_score: number;
    actions: Record<string, { count: number; daily_limit: number }>;
  } | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api<{ message: string }>("/connected-accounts/x/status"),
      api<{ safety_score: number; actions: Record<string, { count: number; daily_limit: number }> }>(
        "/safety/status"
      ),
    ])
      .then(([x, s]) => {
        setXMessage(x.message);
        setSafety(s);
      })
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Settings</h1>
        <p className="text-muted-foreground">Manage your profile, connections, and account limits</p>
      </div>

      <ProfileForm />

      <Card>
        <CardHeader>
          <CardTitle>Connected accounts</CardTitle>
          <CardDescription>Link your X account to unlock live metrics and posting</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="mb-4 text-sm text-muted-foreground">{xMessage}</p>
          <Button variant="secondary" disabled>
            Connect X (coming soon)
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Safety limits today</CardTitle>
          <CardDescription>Xpulse paces your activity to protect your account</CardDescription>
        </CardHeader>
        <CardContent>
          {loading || !safety ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <>
              <div className="mb-4 flex items-center gap-3">
                <span className="text-sm text-muted-foreground">Safety score</span>
                <Badge variant="secondary">{safety.safety_score}/100</Badge>
              </div>
              <Progress value={safety.safety_score} className="mb-4" />
              <ul className="space-y-2 text-sm text-muted-foreground">
                {Object.entries(safety.actions).map(([k, v]) => (
                  <li key={k} className="flex justify-between">
                    <span className="capitalize">{k.replace("_", " ")}</span>
                    <span>
                      {v.count} / {v.daily_limit}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
