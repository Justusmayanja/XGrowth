"use client";

import Link from "next/link";
import { AlertTriangle, Clock } from "lucide-react";
import { formatDate } from "@/lib/billing";
import { useBillingStatus } from "@/components/shell/BillingState";

function Row({ children, action, tone }: { children: React.ReactNode; action: React.ReactNode; tone?: string }) {
  return (
    <div className={`border-b border-border ${tone ?? "bg-muted/70"}`}>
      <div className="mx-auto flex h-10 w-full max-w-6xl items-center justify-between gap-3 px-4 text-sm sm:px-6 lg:px-8">
        <p className="flex min-w-0 items-center gap-2 truncate">{children}</p>
        {action}
      </div>
    </div>
  );
}

export function TrialBanner() {
  const status = useBillingStatus();
  const subscription = status?.subscription ?? null;
  const nextPlan = status?.next_plan ?? null;

  if (!status) return null;

  if (nextPlan?.due) {
    return (
      <Row
        action={
          <Link href="/pricing" className="shrink-0 text-sm font-medium underline underline-offset-4">
            Confirm plan
          </Link>
        }
      >
        <Clock className="size-4 shrink-0" aria-hidden />
        <span className="font-medium">Your new plan is ready.</span>
        <span className="text-muted-foreground">Confirm the payment to start it.</span>
      </Row>
    );
  }

  if (subscription?.status === "past_due") {
    return (
      <Row
        tone="bg-destructive/10"
        action={
          <Link href="/pricing" className="shrink-0 text-sm font-medium underline underline-offset-4">
            Pay now
          </Link>
        }
      >
        <AlertTriangle className="size-4 shrink-0" aria-hidden />
        <span className="font-medium">Your last renewal didn&apos;t go through.</span>
        <span className="truncate text-muted-foreground">
          {subscription.failure_reason ?? "Update your payment to keep your platforms running."}
        </span>
      </Row>
    );
  }

  if (subscription?.status === "active" && !subscription.renews && !nextPlan) {
    return (
      <Row
        action={
          <Link href="/pricing" className="shrink-0 text-sm font-medium underline underline-offset-4">
            Keep my plan
          </Link>
        }
      >
        <Clock className="size-4 shrink-0" aria-hidden />
        <span className="font-medium">Your plan ends {formatDate(subscription.current_period_ends_at)}.</span>
        <span className="text-muted-foreground">It won&apos;t renew unless you keep it.</span>
      </Row>
    );
  }

  if (subscription?.status === "trialing") {
    if (status.trial_paused || subscription.trial_days_left == null) return null;
    const days = subscription.trial_days_left;
    if (days >= 4) return null;
    return (
      <Row
        action={
          <Link href="/pricing" className="shrink-0 text-sm font-medium underline underline-offset-4">
            Upgrade
          </Link>
        }
      >
        <Clock className="size-4 shrink-0" aria-hidden />
        <span className="font-medium">Free trial:</span>
        <span className="truncate text-muted-foreground">
          {days <= 1 ? "ends today." : `${days} days left.`} Upgrade any time to keep your platforms running.
        </span>
      </Row>
    );
  }

  if (subscription?.status === "trial_ended") {
    if (status.trial_paused) return null;
    return (
      <Row
        action={
          <Link href="/pricing" className="shrink-0 text-sm font-medium underline underline-offset-4">
            Upgrade now
          </Link>
        }
      >
        <AlertTriangle className="size-4 shrink-0" aria-hidden />
        <span className="font-medium">Your free trial has ended.</span>
        <span className="text-muted-foreground">Upgrade to keep your platforms and packages.</span>
      </Row>
    );
  }

  return null;
}
