"use client";

import { useReveal } from "@/hooks/useReveal";

export function ProblemSection() {
  const { ref, visible } = useReveal();

  return (
    <section ref={ref} className={`landing-reveal py-24 ${visible ? "visible" : ""}`}>
      <div className="mx-auto max-w-6xl px-6">
        <div className="grid items-center gap-12 lg:grid-cols-2">
          <div>
            <p className="mb-3 text-sm font-medium uppercase tracking-widest text-[#f0b2a6]">
              Why Mackie17
            </p>
            <h2 className="landing-section-title motion-copy motion-live">
              Schedulers post on time.
              <span className="motion-copy mt-2 block text-muted">Operators post on demand.</span>
            </h2>
            <p className="motion-copy mt-6 text-lg text-muted">
              Most tools queue posts whether anyone is listening. Mackie17 drafts when the niche
              is moving — and waits when there is nothing worth saying.
            </p>
          </div>

          <div className="space-y-4">
            <div className="depth-card border border-border bg-card p-6">
              <h3 className="font-medium text-muted-foreground">Typical schedulers</h3>
              <ul className="mt-3 space-y-2 text-sm text-muted">
                <li>Fixed times, empty slots, same advice for every account</li>
                <li>You still rewrite every caption yourself</li>
                <li>No pacing or protection built in</li>
              </ul>
            </div>
            <div className="depth-card border border-primary/30 bg-primary/5 p-6">
              <h3 className="font-medium text-[#f0b2a6]">Mackie17</h3>
              <ul className="mt-3 space-y-2 text-sm text-muted">
                <li>Drafts when attention and demand line up with your niche</li>
                <li>Your voice, your caps, your channels</li>
                <li>Publishes only where you have allowed it</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
