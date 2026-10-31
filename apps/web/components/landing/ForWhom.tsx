"use client";

import { Clapperboard, Layers, Store, type LucideIcon } from "lucide-react";
import { useReveal } from "@/hooks/useReveal";

const people: { title: string; fit: string; description: string; icon: LucideIcon }[] = [
  {
    title: "A shop or a studio",
    fit: "TikTok waits for your yes",
    icon: Store,
    description:
      "Describe the business once. It makes the clip and suggests a time. Nothing goes out until you confirm.",
  },
  {
    title: "A channel",
    fit: "YouTube posts, or waits",
    icon: Clapperboard,
    description:
      "Describe who the channel is for. It writes the piece and makes the video, or edits footage you already filmed. You choose whether it posts or holds.",
  },
  {
    title: "More than one account",
    fit: "Each one keeps its own voice",
    icon: Layers,
    description:
      "The shop, the channel, and the X profile each get their own description. They do not share a sentence.",
  },
];

export function ForWhom() {
  const { ref, visible } = useReveal();

  return (
    <section id="who" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">Who it is for</p>
          <h2 className="landing-section-title motion-copy motion-live">
            People who already run the account
          </h2>
          <p className="motion-copy mt-4 text-lg text-muted">
            A shop, a channel, or a few accounts that should not sound like one another.
          </p>
        </div>

        <div className="mt-16 grid gap-6 md:grid-cols-3">
          {people.map((person) => (
            <div key={person.title} className="landing-feature-card">
              <h3 className="flex items-center gap-2.5 text-lg font-medium">
                <person.icon className="h-4 w-4 shrink-0 text-[#c9c0b4]" strokeWidth={1.6} aria-hidden="true" />
                {person.title}
              </h3>
              <p className="mt-2 text-sm text-[#f0b2a6]">{person.fit}</p>
              <p className="mt-3 text-sm leading-relaxed text-muted">{person.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
