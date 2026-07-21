"use client";

import { useReveal } from "@/hooks/useReveal";

const steps = [
  {
    step: "01",
    title: "Watch",
    description: "Xpulse monitors trends, live events, and conversations across your niche on X.",
    color: "from-blue-500 to-cyan-400",
  },
  {
    step: "02",
    title: "Score",
    description: "Each moment gets an opportunity score based on your goals, niche, and audience.",
    color: "from-violet-500 to-purple-400",
  },
  {
    step: "03",
    title: "Draft",
    description: "When the moment is right, Xpulse prepares posts in your voice. You approve or go auto.",
    color: "from-accent to-indigo-400",
  },
  {
    step: "04",
    title: "Grow",
    description: "Publish safely, track results, and get sharper over time as Xpulse learns what works.",
    color: "from-green-500 to-emerald-400",
  },
];

export function HowItWorks() {
  const { ref, visible } = useReveal();

  return (
    <section
      id="how-it-works"
      ref={ref}
      className={`landing-reveal bg-surface/50 py-24 ${visible ? "visible" : ""}`}
    >
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            How it works
          </p>
          <h2 className="landing-section-title">Watch → Score → Draft → Grow</h2>
          <p className="mt-4 text-lg text-muted">
            The same instincts a sharp creator uses, running continuously in the background for your
            account.
          </p>
        </div>

        <div className="relative mt-16">
          <div className="absolute left-8 top-0 hidden h-full w-px bg-gradient-to-b from-accent via-violet-500 to-green-500 lg:left-1/2 lg:block" />

          <div className="space-y-8">
            {steps.map((item, i) => (
              <div
                key={item.step}
                className={`relative flex flex-col gap-6 lg:flex-row ${
                  i % 2 === 1 ? "lg:flex-row-reverse" : ""
                }`}
              >
                <div className="hidden flex-1 lg:block" />
                <div className="relative z-10 mx-auto flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-surface text-lg font-bold lg:mx-0">
                  {item.step}
                </div>
                <div className="landing-glass flex-1 rounded-2xl p-6 transition hover:border-accent/30">
                  <div
                    className={`mb-3 inline-block rounded-full bg-gradient-to-r ${item.color} px-3 py-1 text-xs font-semibold text-white`}
                  >
                    {item.title}
                  </div>
                  <p className="text-muted">{item.description}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
