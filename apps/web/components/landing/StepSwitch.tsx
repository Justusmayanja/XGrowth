"use client";

import { useState } from "react";
import { ProductVisual } from "./ProductVisual";

const steps = [
  {
    label: "Connect",
    title: "Attach the accounts, then leave the apps.",
    body: "Connect YouTube, TikTok, or X and describe who the account is for. After that, the work moves back to the shop, the studio, or the film day.",
    shot: "drafts" as const,
    points: [
      "YouTube can make the video and post it, or wait for you.",
      "TikTok makes the clip, suggests a time, and waits.",
      "X posts when the moment is right. No schedule.",
    ],
  },
  {
    label: "Draft",
    title: "The piece, from the account you described.",
    body: "It looks for what fits that account, writes what should be said, and on YouTube and TikTok it makes the video. Bring footage you already filmed and YouTube edits that instead.",
    shot: "drafts" as const,
    points: [
      "YouTube: a video made for the channel, or an edit of yours.",
      "TikTok: a clip that suits the business or the brand.",
      "X: a post that waits for a moment worth taking.",
    ],
  },
  {
    label: "Queue",
    title: "Each network leaves differently.",
    body: "YouTube can post the finished video on its own, or hold it until you confirm. TikTok suggests a time and waits for your yes. X has no calendar. It sends when the moment looks strong enough.",
    shot: "queue" as const,
    points: [
      "The daily caps still count.",
      "A quiet day stays quiet.",
      "Pause from Settings without disconnecting.",
    ],
  },
  {
    label: "Look back",
    title: "Results is a log, not a promise.",
    body: "See what went out and which pieces people stayed with. The next round uses that. It will not post just to fill a quiet day.",
    shot: "results" as const,
    points: [
      "What went out, and what is still a draft.",
      "Which pieces you can turn into the next cut.",
      "Replies hold when the day’s cap is close.",
    ],
  },
];

export function StepSwitch() {
  const [active, setActive] = useState(0);
  const step = steps[active];

  return (
    <section className="reveal mx-auto max-w-6xl px-6 py-16" aria-label="Mackie17 at each step">
      <h2 className="display text-center text-4xl leading-tight sm:text-5xl">
        Mackie17 helps at <span className="kicker">every step.</span>
      </h2>
      <div className="mt-8 flex flex-wrap justify-center gap-2">
        {steps.map((item, index) => (
          <button
            key={item.label}
            type="button"
            className={`cursor-pointer rounded-full px-4 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground ${
              index === active ? "bg-[#1d9bf0] text-white" : "bg-white/5 text-muted hover:text-foreground"
            }`}
            aria-pressed={index === active}
            onClick={() => setActive(index)}
          >
            {item.label}
          </button>
        ))}
      </div>
      <div key={step.label} className="panel-swap mt-10 grid items-center gap-10 md:grid-cols-2">
        <div>
          <h3 className="display text-3xl leading-tight">{step.title}</h3>
          <p className="mt-4 text-base leading-relaxed text-muted">{step.body}</p>
          <ul className="mt-6 space-y-3 text-sm leading-relaxed text-muted">
            {step.points.map((point) => (
              <li key={point} className="border-t border-[#243246] pt-3">
                {point}
              </li>
            ))}
          </ul>
        </div>
        <ProductVisual kind={step.shot} />
      </div>
    </section>
  );
}
