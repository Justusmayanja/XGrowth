"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";

type CustomerDetail = {
  id: number;
  name: string;
  email: string;
  subscription_plan: string;
  created_at: string;
  is_blocked: boolean;
  blocked_at?: string | null;
  blocked_reason?: string | null;
  blocked_by?: { id: number; name: string; email: string } | null;
  admin_notes?: string | null;
  account_status: "managed" | "needs_onboarding" | "needs_x_connection";
  x_username?: string | null;
  posts_count: number;
  decisions_count: number;
  account_profile?: {
    goal: string | null;
    niche: string | null;
    custom_niche: string | null;
    description: string | null;
    audience: string | null;
    tone: string | null;
    mode: string;
    automation_level: number;
    onboarding_completed: boolean;
  } | null;
  recent_posts: Array<{
    id: number;
    content: string;
    status: string;
    impressions: number;
    created_at: string;
  }>;
  recent_decisions: Array<{
    id: number;
    decision: string;
    action_taken: string | null;
    opportunity_score: number | null;
    reason: string | null;
    created_at: string;
  }>;
  post_stats: Record<string, number>;
  decision_stats: Record<string, number>;
};

const plans = ["creator", "pro", "agency"];

function statusBadge(status: CustomerDetail["account_status"], blocked: boolean) {
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

export default function AdminCustomerDetailPage() {
  const params = useParams();
  const customerId = params.id as string;

  const [customer, setCustomer] = useState<CustomerDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [adminNotes, setAdminNotes] = useState("");
  const [blockReason, setBlockReason] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api<{ customer: CustomerDetail }>(`/admin/customers/${customerId}`);
      setCustomer(res.customer);
      setAdminNotes(res.customer.admin_notes ?? "");
      setBlockReason(res.customer.blocked_reason ?? "");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to load customer");
    } finally {
      setLoading(false);
    }
  }, [customerId]);

  useEffect(() => {
    load();
  }, [load]);

  async function patch(body: Record<string, unknown>) {
    setSaving(true);
    try {
      const res = await api<{ customer: CustomerDetail; message: string }>(
        `/admin/customers/${customerId}`,
        { method: "PATCH", body: JSON.stringify(body) }
      );
      toast.success(res.message);
      await load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Update failed");
    } finally {
      setSaving(false);
    }
  }

  async function saveNotes() {
    await patch({ admin_notes: adminNotes });
  }

  async function updatePlan(subscription_plan: string) {
    await patch({ subscription_plan });
  }

  async function blockCustomer() {
    if (!blockReason.trim()) {
      toast.error("Please provide a reason for blocking this customer.");
      return;
    }
    if (!confirm(`Block ${customer?.name}? They will be signed out immediately.`)) {
      return;
    }
    await patch({ is_blocked: true, blocked_reason: blockReason.trim() });
  }

  async function unblockCustomer() {
    if (!confirm(`Restore access for ${customer?.name}?`)) {
      return;
    }
    await patch({ is_blocked: false });
    setBlockReason("");
  }

  if (loading) {
    return <p className="text-muted-foreground">Loading customer…</p>;
  }

  if (!customer) {
    return (
      <div className="space-y-4">
        <p className="text-muted-foreground">Customer not found.</p>
        <Link href="/admin/customers">
          <Button variant="secondary">Back to customers</Button>
        </Link>
      </div>
    );
  }

  const profile = customer.account_profile;
  const niche = profile?.custom_niche ?? profile?.niche ?? "—";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link
            href="/admin/customers"
            className="mb-2 inline-block text-sm text-primary hover:underline"
          >
            ← Back to customers
          </Link>
          <h1 className="text-2xl font-bold">{customer.name}</h1>
          <p className="text-muted-foreground">{customer.email}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {statusBadge(customer.account_status, customer.is_blocked)}
            <Badge variant="outline" className="capitalize">
              {customer.subscription_plan}
            </Badge>
            {customer.x_username && (
              <Badge variant="outline">@{customer.x_username}</Badge>
            )}
          </div>
        </div>
        <Button variant="outline" size="sm" onClick={load} disabled={loading}>
          Refresh
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Joined", value: new Date(customer.created_at).toLocaleDateString() },
          { label: "Posts", value: customer.posts_count },
          { label: "Engine decisions", value: customer.decisions_count },
          {
            label: "Automation",
            value: profile?.automation_level != null ? `Level ${profile.automation_level}` : "—",
          },
        ].map((stat) => (
          <Card key={stat.label}>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                {stat.label}
              </CardTitle>
            </CardHeader>
            <CardContent className="pt-6">
              <div className="text-2xl font-bold">{stat.value}</div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Account profile</CardTitle>
            <CardDescription>What this customer told Mackie17 during onboarding</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <div>
              <p className="text-muted-foreground">Goal</p>
              <p className="capitalize">{profile?.goal?.replace("_", " ") ?? "—"}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Niche</p>
              <p className="capitalize">{niche}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Description</p>
              <p>{profile?.description ?? "—"}</p>
            </div>
            <div>
              <p className="text-muted-foreground">Audience</p>
              <p>{profile?.audience ?? "—"}</p>
            </div>
            <div className="flex flex-wrap gap-4">
              <div>
                <p className="text-muted-foreground">Tone</p>
                <p className="capitalize">{profile?.tone ?? "—"}</p>
              </div>
              <div>
                <p className="text-muted-foreground">Mode</p>
                <p className="capitalize">{profile?.mode ?? "—"}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className={customer.is_blocked ? "border-destructive/40" : ""}>
          <CardHeader>
            <CardTitle>Moderation</CardTitle>
            <CardDescription>
              Block customers who violate terms, spam, or abuse the platform
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <Label htmlFor="plan">Subscription plan</Label>
              <Select
                value={customer.subscription_plan}
                onValueChange={(v) => v && updatePlan(v)}
                disabled={saving}
              >
                <SelectTrigger id="plan" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {plans.map((plan) => (
                    <SelectItem key={plan} value={plan}>
                      {plan}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div>
              <Label htmlFor="admin_notes">Internal notes</Label>
              <Textarea
                id="admin_notes"
                rows={3}
                value={adminNotes}
                onChange={(e) => setAdminNotes(e.target.value)}
                placeholder="Notes visible only to admins — e.g. prior warnings, support context"
                className="mt-1"
              />
              <Button
                variant="secondary"
                size="sm"
                className="mt-2"
                onClick={saveNotes}
                disabled={saving}
              >
                Save notes
              </Button>
            </div>

            {customer.is_blocked ? (
              <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm">
                <p className="font-semibold text-destructive">Account blocked</p>
                {customer.blocked_reason && (
                  <p className="mt-1 text-muted-foreground">{customer.blocked_reason}</p>
                )}
                {customer.blocked_at && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    Blocked {new Date(customer.blocked_at).toLocaleString()}
                    {customer.blocked_by && ` by ${customer.blocked_by.name}`}
                  </p>
                )}
                <Button
                  variant="secondary"
                  size="sm"
                  className="mt-3"
                  onClick={unblockCustomer}
                  disabled={saving}
                >
                  Restore access
                </Button>
              </div>
            ) : (
              <div>
                <Label htmlFor="block_reason">Block reason</Label>
                <Textarea
                  id="block_reason"
                  rows={2}
                  value={blockReason}
                  onChange={(e) => setBlockReason(e.target.value)}
                  placeholder="Required — e.g. spam posting, ToS violation, coordinated inauthentic behavior"
                  className="mt-1"
                />
                <Button
                  variant="destructive"
                  size="sm"
                  className="mt-2"
                  onClick={blockCustomer}
                  disabled={saving}
                >
                  Block customer
                </Button>
                <p className="mt-2 text-xs text-muted-foreground">
                  Blocks login, revokes all sessions, and stops the Growth Engine for this account.
                </p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Recent posts</CardTitle>
            <CardDescription>Content created or managed for this customer</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {Object.entries(customer.post_stats).map(([status, count]) => (
                <Badge key={status} variant="outline" className="capitalize">
                  {status}: {count}
                </Badge>
              ))}
            </div>
            {customer.recent_posts.length === 0 ? (
              <p className="text-sm text-muted-foreground">No posts yet.</p>
            ) : (
              customer.recent_posts.map((post) => (
                <div key={post.id} className="border-b border-border pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center justify-between gap-2">
                    <Badge variant="outline" className="capitalize">
                      {post.status}
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {new Date(post.created_at).toLocaleString()}
                    </span>
                  </div>
                  <p className="mt-1 line-clamp-2 text-sm">{post.content}</p>
                  {post.impressions > 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      {post.impressions.toLocaleString()} impressions
                    </p>
                  )}
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Growth Engine activity</CardTitle>
            <CardDescription>Autonomous growth decisions for this account</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {Object.entries(customer.decision_stats).map(([decision, count]) => (
                <Badge key={decision} variant="secondary">
                  {decision}: {count}
                </Badge>
              ))}
            </div>
            {customer.recent_decisions.length === 0 ? (
              <p className="text-sm text-muted-foreground">No engine decisions yet.</p>
            ) : (
              customer.recent_decisions.map((decision) => (
                <div
                  key={decision.id}
                  className="border-b border-border pb-3 last:border-0 last:pb-0"
                >
                  <div className="flex items-center justify-between gap-2">
                    <Badge variant="secondary">{decision.decision}</Badge>
                    <span className="text-xs text-muted-foreground">
                      {new Date(decision.created_at).toLocaleString()}
                    </span>
                  </div>
                  {decision.reason && (
                    <p className="mt-1 text-sm text-muted-foreground">{decision.reason}</p>
                  )}
                  {decision.opportunity_score != null && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Score {Math.round(decision.opportunity_score)}/100
                    </p>
                  )}
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
