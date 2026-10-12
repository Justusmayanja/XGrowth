"use client";

import { BarChart3, Clock, PenLine, Radar, Radio, Shield, type LucideIcon } from "lucide-react";
import { useReveal } from "@/hooks/useReveal";

const features: { title: string; description: string; icon: LucideIcon }[] = [
  {
    title: "Niche radar",
    icon: Radar,
    description:
      "Tracks what is rising in your market — stories, debates, and moments before they peak.",
  },
  {
    title: "Timing",
    icon: Clock,
    description:
      "Knows when to post, when to reply, and when silence is the better move.",
  },
  {
    title: "Compose",
    icon: PenLine,
    description:
      "Turn a rough note or a trending angle into drafts that still sound like your brand.",
  },
  {
    title: "Limits",
    icon: Shield,
    description:
      "Daily caps, spacing, and duplicate checks so accounts stay human-paced.",
  },
  {
    title: "Results",
    icon: BarChart3,
    description:
      "See what shipped, what waited, and what moved — without another spreadsheet.",
  },
  {
    title: "Always on",
    icon: Radio,
    description:
      "Keeps watching and drafting while you are offline, inside the rules you set.",
  },
];

export function Features() {
  const { ref, visible } = useReveal();

  return (
    <section id="features" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">
            Features
          </p>
          <h2 className="landing-section-title motion-copy motion-live">
            Everything to grow the accounts you run
          </h2>
          <p className="motion-copy mt-4 text-lg text-muted">
            For shops, studios, creators, and brands who want demand-led marketing — not another
            row of empty time slots.
          </p>
        </div>

        <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {features.map((feature) => (
            <div key={feature.title} className="landing-feature-card">
              <h3 className="flex items-center gap-2.5 text-lg font-medium">
                <feature.icon className="h-4 w-4 shrink-0 text-[#c9c0b4]" strokeWidth={1.6} aria-hidden="true" />
                {feature.title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">{feature.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
