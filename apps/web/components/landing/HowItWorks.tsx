"use client";

import Image from "next/image";
import { useReveal } from "@/hooks/useReveal";

const steps = [
  {
    step: "01",
    title: "YouTube",
    description:
      "Connect the channel and describe it once: who it is for, and how it should sound. It looks for ideas that already feel ready to catch on, writes what should be said, and makes the video. Already filmed something? Hand that over and it edits it into the piece. Set it to post on its own, or leave the last word with you until you confirm.",
  },
  {
    step: "02",
    title: "TikTok",
    description:
      "Connect the account and describe the business or the brand. It looks for ideas that suit that world, writes what should be said, and makes the clip. Then it suggests a time and waits. Nothing goes out until you confirm. After it is live, it pays attention to what people stayed for, and the next one gets closer.",
  },
  {
    step: "03",
    title: "X",
    description:
      "Connect the profile, describe the niche, and turn posting on. There is no schedule. It stays in that world and posts when the moment looks strong enough to travel. It notices what landed, then waits for the next moment that feels the same before it posts again.",
  },
];

export function HowItWorks() {
  const { ref, visible } = useReveal();

  return (
    <section
      id="how-it-works"
      ref={ref}
      className={`landing-reveal border-y border-border bg-card/40 py-24 ${visible ? "visible" : ""}`}
    >
      <div className="mx-auto max-w-6xl px-6">
        <div className="grid items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)]">
          <div>
            <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">
              How it works
            </p>
            <h2 className="landing-section-title motion-copy motion-live">
              You describe the account. Each network takes it from there.
            </h2>
            <p className="motion-copy mt-4 text-lg text-muted">
              Connect YouTube, TikTok, or X and say who it is for. What happens next is different
              on each one, and it gets sharper every time something goes out.
            </p>

            <ol className="mt-10 space-y-6">
              {steps.map((item) => (
                <li key={item.step} className="flex gap-4">
                  <span className="font-mono text-sm text-primary">{item.step}</span>
                  <div>
                    <p className="font-medium text-foreground">{item.title}</p>
                    <p className="mt-1 text-sm leading-relaxed text-muted">{item.description}</p>
                  </div>
                </li>
              ))}
            </ol>
          </div>

          <figure className="holo">
            <Image
              src="/images/wire-mesh.jpg"
              alt="A glowing wireframe mesh on a dark field."
              width={1600}
              height={900}
              className="blend-photo h-auto w-full border-0 shadow-none"
            />
            <span className="holo-sheen" aria-hidden="true" />
          </figure>
        </div>
      </div>
    </section>
  );
}
