"use client";

import Link from "next/link";
import { formatDate } from "@/lib/billing";
import { planSummary, useBillingStatus } from "@/components/shell/BillingState";

export function PlanCard() {
  const status = useBillingStatus();
  const subscription = status?.subscription ?? null;
  const trialing = subscription?.status === "trialing";
  const days = subscription?.trial_days_left;
  const ends = formatDate(subscription?.trial_ends_at ?? null);
  const showCountdown = trialing && days != null && !status?.trial_paused;

  return (
    <div className="rounded-md border border-border bg-surface px-3 py-3">
      <p className="text-sm font-medium">{planSummary(subscription)}</p>
      {showCountdown && (
        <p className="mt-1 text-xs text-muted-foreground">
          {days <= 1 ? "Ends today" : `${days} days left`}
          {ends ? ` · ${ends}` : ""}
        </p>
      )}
      {showCountdown && (
        <Link href="/pricing" className="mt-3 inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground">
          Upgrade
        </Link>
      )}
    </div>
  );
}
