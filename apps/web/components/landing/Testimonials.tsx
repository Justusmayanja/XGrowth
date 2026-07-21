"use client";

import { useReveal } from "@/hooks/useReveal";

const testimonials = [
  {
    quote:
      "I was spending 2 hours a day on X. Xpulse catches transfer news faster than I ever could manually.",
    author: "James K.",
    role: "Football creator · 12K followers",
  },
  {
    quote:
      "Finally something that doesn't just schedule tweets. The timing actually makes sense for my niche.",
    author: "Sarah M.",
    role: "Tech founder",
  },
  {
    quote:
      "I don't worry about posting too much or at the wrong time anymore. Xpulse handles the pacing.",
    author: "David O.",
    role: "Finance creator · 8K followers",
  },
];

export function Testimonials() {
  const { ref, visible } = useReveal();

  return (
    <section
      ref={ref}
      className={`landing-reveal border-y border-white/5 bg-surface/30 py-24 ${visible ? "visible" : ""}`}
    >
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            Creators on Xpulse
          </p>
          <h2 className="landing-section-title">Built for people who move fast</h2>
        </div>

        <div className="mt-12 grid gap-6 md:grid-cols-3">
          {testimonials.map((t) => (
            <div
              key={t.author}
              className="landing-glass rounded-2xl p-6 transition hover:border-accent/20"
            >
              <p className="text-sm leading-relaxed text-muted">&ldquo;{t.quote}&rdquo;</p>
              <div className="mt-6 flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-accent/20 text-sm font-bold text-accent-light">
                  {t.author[0]}
                </div>
                <div>
                  <div className="text-sm font-semibold">{t.author}</div>
                  <div className="text-xs text-muted">{t.role}</div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
