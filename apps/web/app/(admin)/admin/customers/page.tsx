"use client";

import Link from "next/link";
import { LoadingMark } from "@/components/LoadingMark";
import { useCallback, useEffect, useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { api } from "@/lib/api";

type Customer = {
  id: number;
  name: string;
  email: string;
  subscription_plan: string;
  created_at: string;
  is_blocked: boolean;
  blocked_reason?: string | null;
  account_status: "managed" | "needs_onboarding" | "needs_x_connection";
  x_username?: string | null;
  posts_count: number;
  decisions_count: number;
  account_profile?: {
    niche: string | null;
    custom_niche: string | null;
    onboarding_completed: boolean;
    mode: string;
    automation_level: number;
    goal: string | null;
  } | null;
};

type PaginatedCustomers = {
  data: Customer[];
  current_page: number;
  last_page: number;
  total: number;
};

const plans = ["creator", "pro", "agency"];

function statusBadge(status: Customer["account_status"], blocked: boolean) {
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

export default function AdminCustomersPage() {
  const [customers, setCustomers] = useState<PaginatedCustomers | null>(null);
  const [search, setSearch] = useState("");
  const [planFilter, setPlanFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ page: String(page) });
      if (search.trim()) params.set("search", search.trim());
      if (planFilter !== "all") params.set("plan", planFilter);
      if (statusFilter !== "all") params.set("status", statusFilter);
      const res = await api<{ customers: PaginatedCustomers }>(`/admin/customers?${params}`);
      setCustomers(res.customers);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to load customers";
      setError(message);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  }, [page, search, planFilter, statusFilter]);

  useEffect(() => {
    load();
  }, [load]);

  async function updatePlan(id: number, subscription_plan: string) {
    try {
      await api(`/admin/customers/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ subscription_plan }),
      });
      toast.success("Plan updated");
      load();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Update failed");
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Customers</h1>
        <p className="text-muted-foreground">
          People who use Mackie17 to publish on X, TikTok, and YouTube
        </p>
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Input
          placeholder="Search name or email…"
          value={search}
          onChange={(e) => {
            setPage(1);
            setSearch(e.target.value);
          }}
          className="w-full sm:max-w-sm"
        />
        <Select
          value={planFilter}
          onValueChange={(v) => {
            if (v) {
              setPage(1);
              setPlanFilter(v);
            }
          }}
        >
          <SelectTrigger className="w-full sm:w-36">
            <SelectValue placeholder="All plans" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All plans</SelectItem>
            {plans.map((plan) => (
              <SelectItem key={plan} value={plan}>
                {plan}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={statusFilter}
          onValueChange={(v) => {
            if (v) {
              setPage(1);
              setStatusFilter(v);
            }
          }}
        >
          <SelectTrigger className="w-full sm:w-44">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="managed">Managed</SelectItem>
            <SelectItem value="needs_onboarding">Needs onboarding</SelectItem>
            <SelectItem value="needs_x">Needs X connection</SelectItem>
            <SelectItem value="blocked">Blocked</SelectItem>
          </SelectContent>
        </Select>
        <Button variant="secondary" onClick={load} className="w-full sm:w-auto">
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

      {!loading && !error && customers && (
        <>
          <p className="text-sm text-muted-foreground">{customers.total} customer(s)</p>

          {/* Mobile card layout */}
          <div className="space-y-3 md:hidden">
            {customers.data.length === 0 ? (
              <div className="rounded-xl border border-border px-4 py-10 text-center text-muted-foreground">
                No customers match your filters.
              </div>
            ) : (
              customers.data.map((customer) => (
                <div key={customer.id} className="space-y-3 rounded-xl border border-border p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <Link
                        href={`/admin/customers/${customer.id}`}
                        className="font-medium hover:underline"
                      >
                        {customer.name}
                      </Link>
                      <p className="truncate text-xs text-muted-foreground">{customer.email}</p>
                      <p className="text-xs text-muted-foreground">
                        Joined {new Date(customer.created_at).toLocaleDateString()}
                      </p>
                    </div>
                    {statusBadge(customer.account_status, customer.is_blocked)}
                  </div>

                  <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                      <dt className="text-xs text-muted-foreground">X account</dt>
                      <dd className="truncate">
                        {customer.x_username ? `@${customer.x_username}` : "Not connected"}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">Niche</dt>
                      <dd className="capitalize truncate">
                        {customer.account_profile?.custom_niche ??
                          customer.account_profile?.niche ??
                          "—"}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">Automation</dt>
                      <dd>
                        {customer.account_profile?.automation_level != null
                          ? `Level ${customer.account_profile.automation_level}`
                          : "—"}
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">Activity</dt>
                      <dd>
                        {customer.posts_count} posts · {customer.decisions_count} decisions
                      </dd>
                    </div>
                  </dl>

                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Select
                      value={customer.subscription_plan}
                      onValueChange={(v) => v && updatePlan(customer.id, v)}
                    >
                      <SelectTrigger className="w-full sm:w-32">
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
                    <Link href={`/admin/customers/${customer.id}`} className="sm:ml-auto">
                      <Button variant="outline" size="sm" className="w-full sm:w-auto">
                        Manage
                      </Button>
                    </Link>
                  </div>
                </div>
              ))
            )}
          </div>

          {/* Desktop table */}
          <div className="hidden overflow-x-auto rounded-xl border border-border md:block">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead className="border-b border-border bg-surface-2">
                <tr>
                  <th className="px-4 py-3 font-medium">Customer</th>
                  <th className="px-4 py-3 font-medium">X account</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Niche</th>
                  <th className="px-4 py-3 font-medium">Automation</th>
                  <th className="px-4 py-3 font-medium">Activity</th>
                  <th className="px-4 py-3 font-medium">Plan</th>
                  <th className="px-4 py-3 font-medium"></th>
                </tr>
              </thead>
              <tbody>
                {customers.data.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-10 text-center text-muted-foreground">
                      No customers match your filters.
                    </td>
                  </tr>
                ) : (
                  customers.data.map((customer) => (
                    <tr key={customer.id} className="border-b border-border last:border-0">
                      <td className="px-4 py-3">
                        <Link href={`/admin/customers/${customer.id}`} className="hover:underline">
                          <p className="font-medium">{customer.name}</p>
                        </Link>
                        <p className="text-xs text-muted-foreground">{customer.email}</p>
                        <p className="text-xs text-muted-foreground">
                          Joined {new Date(customer.created_at).toLocaleDateString()}
                        </p>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {customer.x_username ? `@${customer.x_username}` : "Not connected"}
                      </td>
                      <td className="px-4 py-3">{statusBadge(customer.account_status, customer.is_blocked)}</td>
                      <td className="px-4 py-3 capitalize text-muted-foreground">
                        {customer.account_profile?.custom_niche ??
                          customer.account_profile?.niche ??
                          "—"}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {customer.account_profile?.automation_level != null
                          ? `Level ${customer.account_profile.automation_level}`
                          : "—"}
                        {customer.account_profile?.mode && (
                          <span className="block text-xs capitalize">{customer.account_profile.mode}</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">
                        {customer.posts_count} posts
                        <span className="block text-xs">{customer.decisions_count} decisions</span>
                      </td>
                      <td className="px-4 py-3">
                        <Select
                          value={customer.subscription_plan}
                          onValueChange={(v) => v && updatePlan(customer.id, v)}
                        >
                          <SelectTrigger className="w-32">
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
                      </td>
                      <td className="px-4 py-3">
                        <Link href={`/admin/customers/${customer.id}`}>
                          <Button variant="outline" size="sm">
                            Manage
                          </Button>
                        </Link>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          <div className="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <span className="text-sm text-muted-foreground">
              Page {customers.current_page} of {customers.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= customers.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
