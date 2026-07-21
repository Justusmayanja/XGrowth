"use client";

import { useReveal } from "@/hooks/useReveal";

const features = [
  {
    icon: "📡",
    title: "Trend Radar",
    description:
      "Tracks what is rising in your niche — breaking stories, live events, and debates before they peak.",
    accent: "border-blue-500/30 bg-blue-500/10",
  },
  {
    icon: "⏱️",
    title: "Smart Timing",
    description:
      "Knows when to post, when to engage, and when to stay quiet. Opportunity beats the clock every time.",
    accent: "border-violet-500/30 bg-violet-500/10",
  },
  {
    icon: "✍️",
    title: "Content Studio",
    description:
      "Turn a topic into ready-to-post drafts matched to your tone, niche, and what performs for you.",
    accent: "border-yellow-500/30 bg-yellow-500/10",
  },
  {
    icon: "🛡️",
    title: "Account Protection",
    description:
      "Rate limits, natural delays, and duplicate checks — so your account grows without risky behavior.",
    accent: "border-green-500/30 bg-green-500/10",
  },
  {
    icon: "📊",
    title: "Reach Insights",
    description:
      "See estimated reach and engagement potential before you publish. Focus on impressions that count.",
    accent: "border-pink-500/30 bg-pink-500/10",
  },
  {
    icon: "🌙",
    title: "Always On",
    description:
      "Xpulse keeps working while you sleep. Your phone off, your account still growing in your niche.",
    accent: "border-accent/30 bg-accent/10",
  },
];

export function Features() {
  const { ref, visible } = useReveal();

  return (
    <section id="features" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            Features
          </p>
          <h2 className="landing-section-title">Everything you need to grow on X</h2>
          <p className="mt-4 text-lg text-muted">
            For creators, founders, and niche accounts who want momentum — not another calendar full
            of empty time slots.
          </p>
        </div>

        <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {features.map((feature, i) => (
            <div
              key={feature.title}
              className="landing-feature-card group"
              style={{ transitionDelay: `${i * 80}ms` }}
            >
              <div
                className={`mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl border text-2xl ${feature.accent}`}
              >
                {feature.icon}
              </div>
              <h3 className="text-lg font-semibold group-hover:text-accent-light">{feature.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">{feature.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
