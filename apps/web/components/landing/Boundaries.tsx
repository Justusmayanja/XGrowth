"use client";

import { useReveal } from "@/hooks/useReveal";

const limits = [
  {
    title: "TikTok waits",
    description: "It makes the clip and suggests a time. Nothing posts until you confirm.",
  },
  {
    title: "YouTube posts only if you set that",
    description: "Leave the last word with you, and the video stays until you confirm.",
  },
  {
    title: "A quiet day stays quiet",
    description: "If nothing looks worth saying, it holds. A gap in the week does not get a filler post.",
  },
  {
    title: "No DMs",
    description: "It drafts posts and replies inside the daily caps. It does not send direct messages.",
  },
  {
    title: "Pause without disconnecting",
    description: "Turn it off from Settings. The accounts stay connected, and nothing goes out while it is paused.",
  },
  {
    title: "Your password stays with the network",
    description: "You approve the connection on YouTube, TikTok, or X. Mackie17 never sees the password.",
  },
];

export function Boundaries() {
  const { ref, visible } = useReveal();

  return (
    <section id="limits" ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="max-w-2xl">
          <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">Before you connect</p>
          <h2 className="landing-section-title motion-copy motion-live">What stays in your hands</h2>
          <p className="motion-copy mt-4 text-lg text-muted">
            Any tool that posts for you can still run into a platform’s own rules. These are the checks Mackie17 keeps.
          </p>
        </div>

        <ul className="mt-12 grid gap-x-12 sm:grid-cols-2">
          {limits.map((item) => (
            <li key={item.title} className="border-t border-border py-5">
              <p className="font-medium text-foreground">{item.title}</p>
              <p className="mt-1 text-sm leading-relaxed text-muted">{item.description}</p>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
