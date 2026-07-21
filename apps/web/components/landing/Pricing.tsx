"use client";

import Link from "next/link";
import { useReveal } from "@/hooks/useReveal";

const plans = [
  {
    name: "Free",
    price: "$0",
    period: "/mo",
    description: "Try Xpulse on one account",
    features: ["1 X account", "Limited drafts per day", "Basic analytics", "Trend previews"],
    cta: "Start now",
    highlighted: false,
  },
  {
    name: "Starter",
    price: "$12",
    period: "/mo",
    description: "For creators getting serious",
    features: ["Content studio", "Trend discovery", "Draft library", "Basic analytics"],
    cta: "Start now",
    highlighted: false,
  },
  {
    name: "Creator",
    price: "$29",
    period: "/mo",
    description: "Full growth engine",
    features: [
      "Auto growth modes",
      "Advanced analytics",
      "Trend intelligence",
      "Account protection",
      "Reach insights",
    ],
    cta: "Start now",
    highlighted: true,
  },
  {
    name: "Pro",
    price: "$59",
    period: "/mo",
    description: "Multiple accounts",
    features: [
      "Multiple accounts",
      "Priority processing",
      "Competitor insights",
      "Full automation",
      "Priority support",
    ],
    cta: "Start now",
    highlighted: false,
  },
  {
    name: "Agency",
    price: "$149+",
    period: "/mo",
    description: "Teams and clients",
    features: ["Team seats", "Many accounts", "Advanced reporting", "Client switcher", "Custom limits"],
    cta: "Contact us",
    highlighted: false,
  },
];

export function Pricing() {
  const { ref, visible } = useReveal();

  return (
    <section id="pricing" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            Pricing
          </p>
          <h2 className="landing-section-title">Plans that grow with you</h2>
          <p className="mt-4 text-lg text-muted">
            Start free today. Upgrade when Xpulse is driving real followers and impressions.
          </p>
        </div>

        <div className="mt-16 grid gap-6 lg:grid-cols-5">
          {plans.map((plan, i) => (
            <div
              key={plan.name}
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
                <span className="text-3xl font-bold">{plan.price}</span>
                <span className="text-sm text-muted">{plan.period}</span>
              </div>
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
