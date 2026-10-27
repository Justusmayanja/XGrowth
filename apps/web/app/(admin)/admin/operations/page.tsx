"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";
import { PlanPricingCard } from "@/components/admin/PlanPricingCard";
import { TikTokOperations } from "@/components/admin/TikTokOperations";

type OperationsInfo = {
  environment: string;
  queue_driver: string;
  database_driver: string;
  pending_jobs: number;
  failed_jobs: number;
  counts: {
    customers: number;
    managed: number;
    trend_catalog: number;
    posts: number;
    decisions: number;
  };
  last_trend_sync_at: string | null;
  last_engine_run_at: string | null;
  last_signup_at: string | null;
};

export default function AdminOperationsPage() {
  const [info, setInfo] = useState<OperationsInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [evaluating, setEvaluating] = useState(false);
  const [seeding, setSeeding] = useState(false);
  const [seedingDemo, setSeedingDemo] = useState(false);
  const [resetting, setResetting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setInfo(await api<OperationsInfo>("/admin/operations"));
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to load operations";
      setError(message);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function evaluateAll() {
    setEvaluating(true);
    try {
      const res = await api<{ count: number; message: string }>("/admin/evaluate-all", { method: "POST" });
      toast.success(res.message);
      load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Evaluation failed");
    } finally {
      setEvaluating(false);
    }
  }

  async function seedTrendCatalog() {
    setSeeding(true);
    try {
      const res = await api<{ count: number }>("/admin/trends/seed", { method: "POST" });
      toast.success(`Trend catalog refreshed (${res.count} trends)`);
      load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Refresh failed");
    } finally {
      setSeeding(false);
    }
  }

  async function seedDemo(force = false) {
    setSeedingDemo(true);
    try {
      const res = await api<{ users: number; trends: number; posts: number; decisions: number }>(
        `/admin/seed-demo${force ? "?force=1" : ""}`,
        { method: "POST" }
      );
      toast.success(`Seeded ${res.users} demo customers`);
      load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Demo seed failed");
    } finally {
      setSeedingDemo(false);
    }
  }

  async function resetSafety() {
    setResetting(true);
    try {
      const res = await api<{ deleted: number; message: string }>("/admin/reset-safety", { method: "POST" });
      toast.success(res.message);
      load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Reset failed");
    } finally {
      setResetting(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Operations</h1>
          <p className="text-muted-foreground">
            Platform infrastructure and Growth Engine maintenance
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={load}>
          Refresh
        </Button>
      </div>

      {loading && <LoadingMark />}
      {!loading && error && (
        <div className="space-y-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <p className="text-sm text-destructive">{error}</p>
          <Button variant="secondary" size="sm" onClick={load}>
            Retry
          </Button>
        </div>
      )}

      {!loading && !error && info && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Customers</CardTitle>
              </CardHeader>
              <CardContent className="pt-6">
                <div className="text-2xl font-bold">{info.counts.customers}</div>
                <p className="mt-1 text-xs text-muted-foreground">{info.counts.managed} managed</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Trend catalog</CardTitle>
              </CardHeader>
              <CardContent className="pt-6">
                <div className="text-2xl font-bold">{info.counts.trend_catalog}</div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {info.last_trend_sync_at
                    ? `Synced ${new Date(info.last_trend_sync_at).toLocaleString()}`
                    : "Not synced yet"}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Queue</CardTitle>
              </CardHeader>
              <CardContent className="pt-6">
                <div className="text-2xl font-bold">{info.pending_jobs}</div>
                <p className="mt-1 text-xs text-muted-foreground">{info.failed_jobs} failed jobs</p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Last engine run</CardTitle>
              </CardHeader>
              <CardContent className="pt-6">
                <div className="text-sm font-medium">
                  {info.last_engine_run_at
                    ? new Date(info.last_engine_run_at).toLocaleString()
                    : "Never"}
                </div>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Runtime</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 sm:grid-cols-3 text-sm">
              <div>
                <p className="text-muted-foreground">Environment</p>
                <p className="font-medium capitalize">{info.environment}</p>
              </div>
              <div>
                <p className="text-muted-foreground">Queue</p>
                <p className="font-medium">{info.queue_driver}</p>
              </div>
              <div>
                <p className="text-muted-foreground">Database</p>
                <p className="font-medium">{info.database_driver}</p>
              </div>
            </CardContent>
          </Card>
        </>
      )}

      {!loading && !error && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Run Growth Engine</CardTitle>
              <CardDescription>Evaluate opportunities for all onboarded customer accounts</CardDescription>
            </CardHeader>
            <CardContent>
              <Button onClick={evaluateAll} disabled={evaluating}>
                {evaluating ? "Running…" : "Evaluate all customers"}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Refresh trend catalog</CardTitle>
              <CardDescription>Update the shared trend data the engine uses for all customers</CardDescription>
            </CardHeader>
            <CardContent>
              <Button variant="secondary" onClick={seedTrendCatalog} disabled={seeding}>
                {seeding ? "Refreshing…" : "Refresh catalog"}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Reset safety budgets</CardTitle>
              <CardDescription>Clear expired daily rate-limit rows for customer accounts</CardDescription>
            </CardHeader>
            <CardContent>
              <Button variant="secondary" onClick={resetSafety} disabled={resetting}>
                {resetting ? "Resetting…" : "Reset safety budgets"}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Seed demo customers</CardTitle>
              <CardDescription>For local development only</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
              <Button variant="secondary" onClick={() => seedDemo(false)} disabled={seedingDemo}>
                {seedingDemo ? "Seeding…" : "Seed demo data"}
              </Button>
            </CardContent>
          </Card>
        </div>
      )}

      <TikTokOperations />

      <PlanPricingCard />
    </div>
  );
}
