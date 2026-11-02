"use client";

import { useReveal } from "@/hooks/useReveal";

const steps = [
  {
    step: "01",
    title: "Connect",
    description: "Attach the YouTube channel, the TikTok account, or the X profile you already run.",
  },
  {
    step: "02",
    title: "Describe it once",
    description: "Who it is for, the business or the brand, and how it should sound.",
  },
  {
    step: "03",
    title: "Review the first piece",
    description:
      "YouTube can post or wait. TikTok holds for your yes. X sends when the moment looks strong enough.",
  },
];

const drafts = [
  { net: "X", line: "The offer, in one sentence the buyer can repeat." },
  { net: "TikTok", line: "The clip, with a time waiting on your yes." },
  { net: "YouTube", line: "The video, ready to post or waiting on you." },
];

const queue = [
  { when: "Now", net: "X", line: "When the moment is strong" },
  { when: "Auto", net: "YouTube", line: "Video, set to post" },
  { when: "Fri", net: "TikTok", line: "Waiting on your yes" },
];

const results = [
  { label: "Sent", value: "18" },
  { label: "Held", value: "4" },
  { label: "Replies", value: "6" },
];

export function SampleDesk() {
  const { ref, visible } = useReveal();

  return (
    <section
      id="desk"
      ref={ref}
      className={`landing-reveal border-y border-border bg-card/40 py-24 ${visible ? "visible" : ""}`}
    >
      <div className="mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">The first sitting</p>
          <h2 className="landing-section-title motion-copy motion-live">What you do, then what the desk shows</h2>
          <p className="motion-copy mt-4 text-lg text-muted">
            The boards are a sample day. The counts are an example, not a result from a customer account.
          </p>
        </div>

        <ol className="mt-14 grid gap-8 md:grid-cols-3">
          {steps.map((item) => (
            <li key={item.step}>
              <span className="font-mono text-sm text-primary">{item.step}</span>
              <p className="mt-2 font-medium text-foreground">{item.title}</p>
              <p className="mt-1 text-sm leading-relaxed text-muted">{item.description}</p>
            </li>
          ))}
        </ol>

        <div className="mt-14 grid gap-6 lg:grid-cols-3">
          <article className="landing-feature-card" aria-label="Sample drafts">
            <p className="text-xs uppercase tracking-widest text-[#c9c0b4]">Sample</p>
            <h3 className="mt-2 text-lg font-medium">Drafts for today</h3>
            <p className="mt-1 text-sm text-muted">Three cuts from the note you already wrote.</p>
            <div className="mt-5 space-y-2">
              {drafts.map((row) => (
                <div key={row.net} className="rounded-lg bg-[#171d28] px-3 py-2.5">
                  <p className="text-[11px] text-[#7eb6ee]">{row.net}</p>
                  <p className="mt-1 text-sm leading-snug text-[#e7eef6]">{row.line}</p>
                </div>
              ))}
            </div>
          </article>

          <article className="landing-feature-card" aria-label="Sample queue">
            <p className="text-xs uppercase tracking-widest text-[#c9c0b4]">Sample</p>
            <h3 className="mt-2 text-lg font-medium">Queue</h3>
            <p className="mt-1 text-sm text-muted">Leaves only where you allowed posting.</p>
            <div className="mt-5 space-y-2">
              {queue.map((row) => (
                <div key={row.when} className="flex items-center gap-3 rounded-lg bg-[#171d28] px-3 py-2.5">
                  <span className="w-12 shrink-0 text-xs text-[#9aa8b8]">{row.when}</span>
                  <span className="text-sm text-[#e7eef6]">
                    {row.net}
                    <span className="text-[#9aa8b8]"> · {row.line}</span>
                  </span>
                </div>
              ))}
            </div>
          </article>

          <article className="landing-feature-card" aria-label="Sample results">
            <p className="text-xs uppercase tracking-widest text-[#c9c0b4]">Sample</p>
            <h3 className="mt-2 text-lg font-medium">Results</h3>
            <p className="mt-1 text-sm text-muted">A sample day. Not a promise about yours.</p>
            <div className="mt-5 grid grid-cols-3 gap-2">
              {results.map((stat) => (
                <div key={stat.label} className="rounded-lg bg-[#171d28] px-2 py-3 text-center">
                  <p className="text-xl text-[#f4f7fb]">{stat.value}</p>
                  <p className="mt-1 text-[11px] text-[#9aa8b8]">{stat.label}</p>
                </div>
              ))}
            </div>
          </article>
        </div>
      </div>
    </section>
  );
}
