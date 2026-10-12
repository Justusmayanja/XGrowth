"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api";
import {
  fetchBillingStatus,
  formatCents,
  formatDate,
  type BillingSubscriptionView,
  type NextPlan,
} from "@/lib/billing";

const STATUS_LABELS: Record<string, string> = {
  trialing: "Free trial",
  trial_ended: "Trial ended",
  past_due: "Payment due",
  ended: "Ended",
};

export function BillingSettingsCard() {
  const [subscription, setSubscription] = useState<BillingSubscriptionView | null>(null);
  const [nextPlan, setNextPlan] = useState<NextPlan | null>(null);
  const [loading, setLoading] = useState(true);
  const [canceling, setCanceling] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    fetchBillingStatus()
      .then((status) => {
        setSubscription(status.subscription);
        setNextPlan(status.next_plan);
      })
      .catch(() => setSubscription(null))
      .finally(() => setLoading(false));
  }, []);

  async function cancelRenewal() {
    if (!window.confirm("Stop renewing? You keep your platforms until the current period ends.")) return;

    setError("");
    setCanceling(true);
    try {
      const res = await api<{ subscription: BillingSubscriptionView }>("/billing/cancel", { method: "POST" });
      setSubscription(res.subscription);
      setNextPlan(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't stop renewal.");
    } finally {
      setCanceling(false);
    }
  }

  const paidPlan = subscription && (subscription.status === "active" || subscription.status === "past_due");
  const needsAction =
    subscription?.status === "trial_ended" || subscription?.status === "past_due" || Boolean(nextPlan?.due);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Billing & platforms</CardTitle>
        <CardDescription>
          Each platform keeps the tier you paid for. Daily drafts follow the highest tier on the subscription.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading billing…</p>
        ) : !subscription || subscription.status === "canceled" || subscription.status === "failed" ? (
          <div className="flex flex-wrap items-center justify-between gap-4">
            <p className="text-sm text-muted-foreground">No active platform subscription yet.</p>
            <Link href="/pricing" className="btn btn-secondary text-sm">
              Choose platforms
            </Link>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border p-4">
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-semibold">
                    {formatCents(subscription.total_cents)}
                    {subscription.period}
                  </span>
                  <Badge variant="secondary" className="capitalize">
                    {STATUS_LABELS[subscription.status] ?? subscription.status}
                  </Badge>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {subscription.status === "trialing" && subscription.trial_ends_at
                    ? `${subscription.trial_days}-day free trial, ends ${formatDate(subscription.trial_ends_at)}. Price shown is after the trial.`
                    : subscription.status === "trial_ended"
                      ? "Your free trial has ended."
                      : subscription.status === "past_due"
                        ? subscription.failure_reason ?? "The last renewal didn't go through."
                        : subscription.status === "ended"
                          ? `Ended ${formatDate(subscription.current_period_ends_at)}.`
                          : subscription.current_period_ends_at
                            ? subscription.renews
                              ? `Renews ${formatDate(subscription.current_period_ends_at)}`
                              : `Ends ${formatDate(subscription.current_period_ends_at)}. It won't renew.`
                            : null}
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                {paidPlan && subscription.renews && (
                  <button
                    type="button"
                    className="btn btn-secondary text-sm"
                    disabled={canceling}
                    onClick={() => void cancelRenewal()}
                  >
                    {canceling ? "Stopping…" : "Cancel renewal"}
                  </button>
                )}
                <Link href="/pricing" className={`btn text-sm ${needsAction ? "btn-primary" : "btn-secondary"}`}>
                  {nextPlan?.due
                    ? "Confirm new plan"
                    : subscription.status === "past_due"
                      ? "Pay now"
                      : subscription.status === "trialing" || subscription.status === "trial_ended"
                        ? "Upgrade"
                        : subscription.status === "ended"
                          ? "Restart"
                          : "Change mix"}
                </Link>
              </div>
            </div>
            {error && (
              <p className="text-sm text-red-300" role="alert">
                {error}
              </p>
            )}
            {nextPlan && (
              <div className="rounded-lg border border-border p-4 text-sm">
                <p className="font-medium">
                  {nextPlan.due ? "Ready to start" : `Starts ${formatDate(nextPlan.starts_at)}`}:{" "}
                  {nextPlan.lines.map((line) => `${line.platform_name} ${line.tier_name}`).join(", ")}, billed{" "}
                  {nextPlan.interval}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {nextPlan.due
                    ? "Confirm the payment on the pricing page to start it."
                    : "Nothing is charged until then. We'll ask you to confirm the payment."}
                </p>
              </div>
            )}
            <ul className="space-y-2 text-sm">
              {subscription.lines.map((line) => (
                <li key={line.platform} className="flex justify-between gap-4">
                  <span>
                    {line.platform_name} · {line.tier_name}
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                      {line.monthly_allowance} {line.allowance_unit} a month · {line.accounts_included}{" "}
                      {line.accounts_included === 1 ? "account" : "accounts"}
                    </span>
                  </span>
                  <span>
                    {formatCents(line.charged_cents)}
                    {subscription.period}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
