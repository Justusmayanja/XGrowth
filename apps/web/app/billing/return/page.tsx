"use client";

import Link from "next/link";
import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { getToken } from "@/lib/api";
import { confirmSubscription, formatCents, type BillingSubscriptionView } from "@/lib/billing";

function BillingReturnInner() {
  const params = useSearchParams();
  const subscriptionId = Number(params.get("subscription"));
  const [subscription, setSubscription] = useState<BillingSubscriptionView | null>(null);
  const [message, setMessage] = useState("Checking the payment…");

  useEffect(() => {
    if (!getToken()) {
      setMessage("Sign in to confirm this payment.");
      return;
    }

    if (!Number.isFinite(subscriptionId) || subscriptionId < 1) {
      setMessage("This return link is missing the subscription.");
      return;
    }

    let stopped = false;

    async function tick() {
      try {
        const res = await confirmSubscription(subscriptionId);
        if (stopped) return;
        setSubscription(res.subscription);
        setMessage(res.paid ? "Payment confirmed." : "Waiting for the gateway to confirm payment.");
        if (res.paid) stopped = true;
      } catch (err) {
        if (stopped) return;
        setMessage(err instanceof Error ? err.message : "Could not confirm payment yet.");
      }
    }

    void tick();
    const timer = window.setInterval(() => {
      if (!stopped) void tick();
    }, 4000);

    return () => {
      stopped = true;
      window.clearInterval(timer);
    };
  }, [subscriptionId]);

  return (
    <main className="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6 py-16">
      <p className="text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">Billing</p>
      <h1 className="display mt-3 text-4xl">Checkout</h1>
      <p className="mt-4 text-muted">{message}</p>
      {subscription && (
        <ul className="mt-6 space-y-2 text-sm">
          {subscription.lines.map((line) => (
            <li key={line.platform} className="flex justify-between gap-4">
              <span>
                {line.platform_name} · {line.tier_name}
              </span>
              <span>
                {formatCents(line.charged_cents)}
                {subscription.period}
              </span>
            </li>
          ))}
        </ul>
      )}
      <Link href={subscription?.status === "active" ? "/dashboard" : "/pricing"} className="btn btn-primary mt-8 w-fit text-sm">
        {subscription?.status === "active" ? "Go to the desk" : "Back to pricing"}
      </Link>
    </main>
  );
}

export default function BillingReturnPage() {
  return (
    <Suspense fallback={<main className="mx-auto max-w-lg px-6 py-16 text-muted">Checking the payment…</main>}>
      <BillingReturnInner />
    </Suspense>
  );
}
