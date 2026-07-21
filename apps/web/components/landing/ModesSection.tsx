"use client";

import { useReveal } from "@/hooks/useReveal";

const modes = [
  {
    title: "Reactive",
    bestFor: "Football · News · Crypto · Politics",
    description: "Leads with breaking news, live events, and trending debates in your niche.",
    badge: "News-first",
  },
  {
    title: "Proactive",
    bestFor: "Business · Startups · Motivation · Brands",
    description: "Creates original posts, threads, and polls when the timeline is quiet.",
    badge: "Voice-first",
  },
];

export function ModesSection() {
  const { ref, visible } = useReveal();

  return (
    <section ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            Two modes
          </p>
          <h2 className="landing-section-title">Fits how your account works</h2>
        </div>

        <div className="mt-12 grid gap-6 md:grid-cols-2">
          {modes.map((mode, i) => (
            <div
              key={mode.title}
              className="landing-feature-card animate-float"
              style={{ animationDelay: `${i * 1.5}s` }}
            >
              <span className="rounded-full border border-accent/30 bg-accent/10 px-3 py-1 text-xs text-accent-light">
                {mode.badge}
              </span>
              <h3 className="mt-4 text-xl font-bold">{mode.title}</h3>
              <p className="mt-1 text-sm text-accent-light">{mode.bestFor}</p>
              <p className="mt-3 text-muted">{mode.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
