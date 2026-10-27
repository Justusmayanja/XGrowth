"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { type BillingSubscriptionView, type NextPlan, fetchBillingStatus, formatDate } from "@/lib/billing";

export function TrialBanner() {
  const [subscription, setSubscription] = useState<BillingSubscriptionView | null>(null);
  const [nextPlan, setNextPlan] = useState<NextPlan | null>(null);

  useEffect(() => {
    fetchBillingStatus()
      .then((status) => {
        setSubscription(status.subscription);
        setNextPlan(status.next_plan);
      })
      .catch(() => setSubscription(null));
  }, []);

  if (nextPlan?.due) {
    return (
      <div
        role="status"
        className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-accent bg-card px-4 py-3 text-sm"
      >
        <p>
          <span className="font-medium">Your new plan is ready.</span>{" "}
          <span className="text-muted-foreground">Confirm the payment to start it.</span>
        </p>
        <Link href="/pricing" className="btn btn-primary text-sm">
          Confirm plan
        </Link>
      </div>
    );
  }

  if (subscription?.status === "past_due") {
    return (
      <div
        role="alert"
        className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-500/50 bg-card px-4 py-3 text-sm"
      >
        <p>
          <span className="font-medium">Your last renewal didn&apos;t go through.</span>{" "}
          <span className="text-muted-foreground">
            {subscription.failure_reason ?? "Update your payment to keep your platforms running."}
          </span>
        </p>
        <Link href="/pricing" className="btn btn-primary text-sm">
          Pay now
        </Link>
      </div>
    );
  }

  if (subscription?.status === "active" && !subscription.renews && !nextPlan) {
    return (
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm">
        <p>
          <span className="font-medium">Your plan ends {formatDate(subscription.current_period_ends_at)}.</span>{" "}
          <span className="text-muted-foreground">It won&apos;t renew unless you keep it.</span>
        </p>
        <Link href="/pricing" className="btn btn-secondary text-sm">
          Keep my plan
        </Link>
      </div>
    );
  }

  if (subscription?.status === "trialing") {
    const days = subscription.trial_days_left ?? 0;
    return (
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm">
        <p>
          <span className="font-medium">Free trial:</span>{" "}
          <span className="text-muted-foreground">
            {days <= 1 ? "ends today." : `${days} days left.`} Upgrade any time to keep your platforms running.
          </span>
        </p>
        <Link href="/pricing" className="btn btn-secondary text-sm">
          Upgrade
        </Link>
      </div>
    );
  }

  return null;
}
