"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { useReveal } from "@/hooks/useReveal";

const faqs = [
  {
    q: "Is Mackie17 a posting scheduler?",
    a: "No. Schedulers post at fixed times. Mackie17 watches your niche and posts when the opportunity is high, during breaking news, live events, or trending debates, and waits when there is nothing worth saying.",
  },
  {
    q: "Will this put my X account at risk?",
    a: "Mackie17 is built with account protection: rate limits, natural pacing, duplicate checks, and random delays. It is designed to behave like a thoughtful creator, not a spam bot.",
  },
  {
    q: "Does it work while I'm offline?",
    a: "Yes. Mackie17 runs continuously. Your phone can be off and the dashboard closed, and it keeps watching trends and acting based on the settings you choose.",
  },
  {
    q: "What happens after I sign up?",
    a: "You answer a short setup about your goals, niche, audience, and voice. Mackie17 uses that to tailor trends, timing, and content to your account from day one.",
  },
  {
    q: "What niches does it support?",
    a: "Football, tech, business, crypto, motivation, memes, local news, entertainment, and custom niches. You describe your account during setup.",
  },
  {
    q: "Can I approve posts before they go live?",
    a: "Yes. You can start in suggest-only mode where Mackie17 drafts and you approve. Increase automation when you are comfortable.",
  },
];

export function FAQ() {
  const { ref, visible } = useReveal();
  const [open, setOpen] = useState<number | null>(0);

  return (
    <section id="faq" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-3xl px-6">
        <div className="text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-widest text-accent-light">
            FAQ
          </p>
          <h2 className="landing-section-title">Common questions</h2>
        </div>

        <div className="mt-12 space-y-3">
          {faqs.map((faq, i) => (
            <div
              key={faq.q}
              className="landing-glass overflow-hidden rounded-xl transition hover:border-accent/20"
            >
              <button
                type="button"
                className="flex w-full items-center justify-between px-5 py-4 text-left"
                onClick={() => setOpen(open === i ? null : i)}
              >
                <span className="font-medium">{faq.q}</span>
                <ChevronDown
                  className={`ml-4 h-4 w-4 shrink-0 text-accent transition-transform duration-200 ${
                    open === i ? "rotate-180" : ""
                  }`}
                  aria-hidden="true"
                />
              </button>
              <div
                className={`grid transition-all duration-300 ${
                  open === i ? "grid-rows-[1fr] opacity-100" : "grid-rows-[0fr] opacity-0"
                }`}
              >
                <div className="overflow-hidden">
                  <p className="px-5 pb-4 text-sm leading-relaxed text-muted">{faq.a}</p>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
