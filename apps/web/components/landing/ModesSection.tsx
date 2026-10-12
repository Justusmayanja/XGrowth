"use client";

import Image from "next/image";
import { AudioLines, Moon, type LucideIcon } from "lucide-react";
import { useReveal } from "@/hooks/useReveal";

const modes: {
  title: string;
  bestFor: string;
  description: string;
  badge: string;
  icon: LucideIcon;
}[] = [
  {
    title: "When the market is loud",
    bestFor: "Launches, local demand, a story people already share",
    description:
      "Drafts while attention is there — so the brand shows up in the moment that can bring a customer.",
    badge: "Demand-first",
    icon: AudioLines,
  },
  {
    title: "When the market is quiet",
    bestFor: "Shops, studios, teachers, product brands",
    description:
      "Still writes from the brand you described. A quiet week does not mean the account goes dark.",
    badge: "Voice-first",
    icon: Moon,
  },
];

export function ModesSection() {
  const { ref, visible } = useReveal();

  return (
    <section ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">
            Two modes
          </p>
          <h2 className="landing-section-title motion-copy motion-live">
            Marketing that follows demand
          </h2>
        </div>

        <figure className="holo mx-auto mt-12 max-w-4xl">
          <Image
            src="/images/mesh-surface.jpg"
            alt="A rolling blue mesh surface on a dark field."
            width={1200}
            height={900}
            className="blend-photo h-auto w-full border-0 shadow-none"
          />
          <span className="holo-sheen" aria-hidden="true" />
        </figure>

        <div className="mt-12 grid gap-6 md:grid-cols-2">
          {modes.map((mode) => (
            <div key={mode.title} className="landing-feature-card">
              <p className="text-xs text-[#c9c0b4]">{mode.badge}</p>
              <h3 className="mt-3 flex items-center gap-2.5 text-xl font-medium">
                <mode.icon className="h-4 w-4 shrink-0 text-[#c9c0b4]" strokeWidth={1.6} aria-hidden="true" />
                {mode.title}
              </h3>
              <p className="mt-1 text-sm text-[#f0b2a6]">{mode.bestFor}</p>
              <p className="mt-3 text-sm leading-relaxed text-muted">{mode.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
