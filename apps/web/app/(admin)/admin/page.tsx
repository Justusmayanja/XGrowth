"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingMark } from "@/components/LoadingMark";
import { api } from "@/lib/api";

type Customer = {
  id: number;
  name: string;
  email: string;
  subscription_plan: string;
  created_at: string;
  is_blocked?: boolean;
  account_status: "managed" | "needs_onboarding" | "needs_x_connection";
  x_username?: string | null;
  posts_count: number;
  decisions_count: number;
  account_profile?: {
    niche: string | null;
    custom_niche: string | null;
    mode: string;
    automation_level: number;
    onboarding_completed: boolean;
  } | null;
};

type Overview = {
  stats: {
    customers_total: number;
    signups_7d: number;
    managed_accounts: number;
    needs_setup: number;
    blocked: number;
    posts_total: number;
    posts_posted: number;
    decisions_24h: number;
    total_impressions: number;
  };
  plan_breakdown: Record<string, number>;
  decision_breakdown: Record<string, number>;
  recent_customers: Customer[];
  needs_attention: Customer[];
  recent_activity: Array<{
    id: number;
    decision: string;
    action_taken: string | null;
    opportunity_score: number | null;
    reason: string | null;
    created_at: string;
    user?: { name: string; email: string };
  }>;
};

function statusBadge(status: Customer["account_status"], blocked?: boolean) {
  if (blocked) {
    return <Badge variant="destructive">Blocked</Badge>;
  }
  switch (status) {
    case "managed":
      return <Badge variant="secondary">Managed</Badge>;
    case "needs_onboarding":
      return <Badge variant="outline">Needs onboarding</Badge>;
    case "needs_x_connection":
      return <Badge variant="outline">Needs X connection</Badge>;
  }
}

function StatCard({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{label}</CardTitle>
      </CardHeader>
      <CardContent className="pt-6">
        <div className="text-2xl font-bold">{value}</div>
        {sub && <p className="mt-1 text-xs text-muted-foreground">{sub}</p>}
      </CardContent>
    </Card>
  );
}

export default function AdminOverviewPage() {
  const [data, setData] = useState<Overview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await api<Overview>("/admin/overview"));
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to load overview";
      setError(message);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  if (loading && !data) {
    return <LoadingMark className="min-h-[40vh]" />;
  }

  if (!data) {
    return (
      <div className="space-y-4">
        <p className="text-destructive">{error ?? "Could not load platform overview."}</p>
        <Button variant="secondary" onClick={load}>
          Retry
        </Button>
      </div>
    );
  }

  const { stats } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Platform Overview</h1>
          <p className="text-muted-foreground">
            Customers who signed up to have X, TikTok, and YouTube handled by Mackie17
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={load} disabled={loading}>
          {loading ? "Refreshing…" : "Refresh"}
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Customers" value={stats.customers_total} sub={`${stats.signups_7d} joined this week`} />
        <StatCard label="Managed accounts" value={stats.managed_accounts} sub="Onboarded with X connected" />
        <StatCard label="Needs setup" value={stats.needs_setup} sub="Onboarding or X connection pending" />
        <StatCard label="Blocked" value={stats.blocked} sub="Suspended for policy violations" />
        <StatCard label="Engine activity (24h)" value={stats.decisions_24h} sub="Growth decisions across customers" />
        <StatCard label="Content managed" value={stats.posts_total} sub={`${stats.posts_posted} published`} />
        <StatCard label="Total impressions" value={stats.total_impressions.toLocaleString()} sub="Across customer posts" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Plans</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {Object.entries(data.plan_breakdown).length === 0 ? (
              <p className="text-sm text-muted-foreground">No customers yet.</p>
            ) : (
              Object.entries(data.plan_breakdown).map(([plan, count]) => (
                <Badge key={plan} variant="outline" className="capitalize">
                  {plan}: {count}
                </Badge>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Growth Engine (24h)</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {Object.entries(data.decision_breakdown).length === 0 ? (
              <p className="text-sm text-muted-foreground">No engine activity yet.</p>
            ) : (
              Object.entries(data.decision_breakdown).map(([decision, count]) => (
                <Badge key={decision} variant="secondary">
                  {decision}: {count}
                </Badge>
              ))
            )}
          </CardContent>
        </Card>
      </div>

      {data.needs_attention.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Needs attention</CardTitle>
            <Link href="/admin/customers" className="text-sm text-primary hover:underline">
              View all
            </Link>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.needs_attention.map((customer) => (
              <div
                key={customer.id}
                className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3 last:border-0 last:pb-0"
              >
                <div>
                  <Link
                    href={`/admin/customers/${customer.id}`}
                    className="text-sm font-medium hover:underline"
                  >
                    {customer.name}
                  </Link>
                  <p className="text-xs text-muted-foreground">{customer.email}</p>
                </div>
                <div className="flex items-center gap-2">
                  {statusBadge(customer.account_status, customer.is_blocked)}
                  <Badge variant="outline">{customer.subscription_plan}</Badge>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Recent signups</CardTitle>
            <Link href="/admin/customers" className="text-sm text-primary hover:underline">
              All customers
            </Link>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.recent_customers.map((customer) => (
              <div
                key={customer.id}
                className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3 last:border-0 last:pb-0"
              >
                <div>
                  <Link
                    href={`/admin/customers/${customer.id}`}
                    className="text-sm font-medium hover:underline"
                  >
                    {customer.name}
                  </Link>
                  <p className="text-xs text-muted-foreground">
                    {customer.x_username ? `@${customer.x_username}` : customer.email}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  {statusBadge(customer.account_status, customer.is_blocked)}
                  <Badge variant="outline">{customer.subscription_plan}</Badge>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Recent engine activity</CardTitle>
            <Link href="/admin/activity" className="text-sm text-primary hover:underline">
              Full activity log
            </Link>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.recent_activity.length === 0 ? (
              <p className="text-sm text-muted-foreground">No activity yet.</p>
            ) : (
              data.recent_activity.map((item) => (
                <div key={item.id} className="border-l-2 border-primary pl-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">{item.decision}</Badge>
                    <span className="text-xs text-muted-foreground">{item.user?.name}</span>
                  </div>
                  <p className="text-sm">{item.reason}</p>
                  <p className="text-xs text-muted-foreground">
                    {new Date(item.created_at).toLocaleString()}
                  </p>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
