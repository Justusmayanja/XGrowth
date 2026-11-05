"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { fetchBillingStatus, formatDate, type BillingStatus, type BillingSubscriptionView } from "@/lib/billing";

const BillingContext = createContext<BillingStatus | null>(null);

export function BillingProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<BillingStatus | null>(null);

  useEffect(() => {
    fetchBillingStatus()
      .then(setStatus)
      .catch(() => setStatus(null));
  }, []);

  return <BillingContext.Provider value={status}>{children}</BillingContext.Provider>;
}

export function useBillingStatus() {
  return useContext(BillingContext);
}

export function planSummary(subscription: BillingSubscriptionView | null | undefined): string {
  if (!subscription) return "No plan";
  if (subscription.status === "trialing") {
    if (subscription.trial_days_left == null) return "Free trial";
    const days = subscription.trial_days_left;
    return days <= 1 ? "Free trial · ends today" : `Free trial · ${days} days left`;
  }
  if (subscription.status === "trial_ended") return "Free trial has ended";
  return subscription.lines[0]?.tier_name || "Current plan";
}

export function trialEndsLine(subscription: BillingSubscriptionView | null | undefined): string | null {
  if (subscription?.status !== "trialing" || !subscription.trial_ends_at) return null;
  const date = formatDate(subscription.trial_ends_at);
  return date ? `Trial ends ${date}.` : "Trial ends today.";
}
