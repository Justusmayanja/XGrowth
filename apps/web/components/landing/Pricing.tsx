"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useReveal } from "@/hooks/useReveal";
import { api } from "@/lib/api";
import { type BillingInterval, type SubscriptionPlan } from "@/lib/plans";

export function Pricing() {
  const { ref, visible } = useReveal();
  const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
  const [interval, setInterval] = useState<BillingInterval>("monthly");

  useEffect(() => {
    api<{ plans: SubscriptionPlan[] }>("/plans")
      .then((res) => setPlans(res.plans))
      .catch(() => setPlans([]));
  }, []);

  return (
    <section id="pricing" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            Pricing
          </p>
          <h2 className="landing-section-title">Plans that grow with you</h2>
          <p className="mt-4 text-lg text-muted">
            Pick a plan and upgrade when Xpulse is driving real followers and impressions.
          </p>

          <div className="mt-8 inline-flex rounded-full border border-border bg-surface p-1">
            <button
              type="button"
              onClick={() => setInterval("monthly")}
              className={`rounded-full px-4 py-1.5 text-sm font-medium transition ${
                interval === "monthly" ? "bg-accent text-white" : "text-muted hover:text-foreground"
              }`}
            >
              Monthly
            </button>
            <button
              type="button"
              onClick={() => setInterval("yearly")}
              className={`rounded-full px-4 py-1.5 text-sm font-medium transition ${
                interval === "yearly" ? "bg-accent text-white" : "text-muted hover:text-foreground"
              }`}
            >
              Yearly
            </button>
          </div>
        </div>

        <div className="mt-16 grid gap-6 lg:grid-cols-3">
          {plans.map((plan, i) => (
            <div
              key={plan.slug}
              className={`landing-feature-card flex flex-col ${
                plan.highlighted
                  ? "border-accent/50 bg-gradient-to-b from-accent/15 to-surface shadow-lg shadow-accent/20 lg:-translate-y-2"
                  : ""
              }`}
              style={{ transitionDelay: `${i * 60}ms` }}
            >
              {plan.highlighted && (
                <span className="mb-3 w-fit rounded-full bg-accent px-3 py-0.5 text-xs font-semibold">
                  Most popular
                </span>
              )}
              <h3 className="text-lg font-bold">{plan.name}</h3>
              <div className="mt-2 flex items-baseline gap-1">
                <span className="text-3xl font-bold">
                  {interval === "yearly" && plan.yearly_price_display
                    ? plan.yearly_price_display
                    : plan.price_display}
                </span>
                <span className="text-sm text-muted">
                  {interval === "yearly" ? plan.yearly_period : plan.period}
                </span>
              </div>
              {interval === "yearly" && plan.yearly_price_display && (
                <p className="mt-1 text-xs text-muted">Billed annually</p>
              )}
              <p className="mt-2 text-sm text-muted">{plan.description}</p>
              <ul className="mt-6 flex-1 space-y-2 text-sm text-muted">
                {plan.features.map((f) => (
                  <li key={f} className="flex items-start gap-2">
                    <span className="mt-0.5 text-accent">✓</span>
                    {f}
                  </li>
                ))}
              </ul>
              <Link
                href="/register"
                className={`btn mt-6 w-full text-sm ${
                  plan.highlighted ? "btn-primary" : "btn-secondary"
                }`}
              >
                {plan.cta}
              </Link>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
