"use client";

import { useReveal } from "@/hooks/useReveal";

const stats = [
  { value: "5–20s", label: "Target reaction to breaking news" },
  { value: "70%", label: "Growth from timely content" },
  { value: "24/7", label: "Always watching your niche" },
  { value: "100%", label: "Focused on opportunity, not clocks" },
];

export function StatsBar() {
  const { ref, visible } = useReveal();

  return (
    <section ref={ref} className={`landing-reveal border-y border-white/5 py-12 ${visible ? "visible" : ""}`}>
      <div className="mx-auto grid max-w-6xl grid-cols-2 gap-8 px-6 md:grid-cols-4">
        {stats.map((stat, i) => (
          <div key={stat.label} className="text-center" style={{ transitionDelay: `${i * 100}ms` }}>
            <div className="text-3xl font-bold text-white sm:text-4xl">{stat.value}</div>
            <div className="mt-1 text-sm text-muted">{stat.label}</div>
          </div>
        ))}
      </div>
    </section>
  );
}
