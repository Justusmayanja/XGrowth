"use client";

import { useReveal } from "@/hooks/useReveal";

export function ProblemSection() {
  const { ref, visible } = useReveal();

  return (
    <section ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="grid items-center gap-12 lg:grid-cols-2">
          <div>
            <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
              Why Xpulse
            </p>
            <h2 className="landing-section-title">
              Schedulers post on time.
              <span className="mt-2 block text-muted">Creators post on opportunity.</span>
            </h2>
            <p className="mt-6 text-lg text-muted">
              Most tools help you queue tweets. Xpulse helps you show up when it actually matters,
              during breaking news, live events, and the conversations your audience cares about.
            </p>
          </div>

          <div className="space-y-4">
            <div className="rounded-2xl border border-red-500/20 bg-red-500/5 p-6">
              <h3 className="font-semibold text-red-300">Typical schedulers</h3>
              <ul className="mt-3 space-y-2 text-sm text-muted">
                <li>Post at fixed times whether or not anyone is listening</li>
                <li>Same generic advice for every account</li>
                <li>No protection against spammy patterns</li>
                <li>You still do all the thinking yourself</li>
              </ul>
            </div>
            <div className="rounded-2xl border border-accent/30 bg-accent/5 p-6">
              <h3 className="font-semibold text-accent-light">Xpulse</h3>
              <ul className="mt-3 space-y-2 text-sm text-muted">
                <li>Posts when trends, news, and debates peak in your niche</li>
                <li>Personalized to your goals, audience, and voice</li>
                <li>Built-in limits and natural pacing for your account</li>
                <li>Drafts, timing, and growth handled for you</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
