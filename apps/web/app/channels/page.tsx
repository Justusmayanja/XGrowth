import type { Metadata } from "next";
import { MarketingShell } from "@/components/landing/MarketingShell";
import { SideFrame } from "@/components/landing/SideFrame";

export const metadata: Metadata = {
  title: "Channels | Mackie17",
  description:
    "Connect YouTube, TikTok, or X and describe the account. YouTube can post or wait. TikTok waits for your yes. X posts when the moment is right.",
};

export default function ChannelsPage() {
  return (
    <MarketingShell
      kicker="YouTube, TikTok, X"
      title="Connect them. Then each one works its own way."
      lede="You attach the accounts you already run and describe each one: the business, the brand, who it is for. After that, YouTube can make the video and post it or wait for you. TikTok makes the clip, suggests a time, and waits. X posts when the moment is right."
    >
      <SideFrame src="/images/foil-macro.jpg" alt="A close view of blue light across a dark metallic surface.">
        <p className="text-sm text-muted">YouTube</p>
        <h2 className="display motion-copy mt-3 text-4xl">Connect the channel. It brings the video.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Write who the channel is for, and how it should sound. From that, it looks for ideas
          that already feel ready to catch on, writes what should be said, and makes the video.
          If you filmed something yourself, hand that over and it edits it into the piece. Set
          posting to run, and it can go up while you are elsewhere. Leave it off, and nothing
          goes up until you confirm.
        </p>
      </SideFrame>

      <SideFrame
        src="/images/wire-mesh.jpg"
        alt="A glowing wireframe mesh."
        flip
      >
        <p className="text-sm text-muted">TikTok · you confirm</p>
        <h2 className="kicker motion-copy mt-3 text-4xl text-foreground">
          It makes the clip. You say when.
        </h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Connect the account and describe the business or the brand. It looks for ideas that
          suit that world, writes what should be said, and makes the clip. Then it suggests a
          time and waits. You confirm, and only then does it post. What goes out teaches the next
          round, so each one sits closer to what people actually stay for.
        </p>
      </SideFrame>

      <SideFrame src="/images/node-network.jpg" alt="Blue nodes linked by luminous lines.">
        <p className="text-sm text-muted">X · no schedule</p>
        <h2 className="display motion-copy mt-3 text-4xl">It posts when the moment is worth it.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Connect the profile, describe the niche, and turn posting on. It stays in that world.
          When the moment looks strong enough to travel, it posts. There is no timetable. It
          notices what landed, and it waits for the next moment that feels the same before it
          posts again.
        </p>
      </SideFrame>

      <section className="grid gap-4 md:grid-cols-3">
        <article className="glow-card lean-left p-6">
          <h2 className="text-sm text-muted">After YouTube</h2>
          <p className="mt-4 text-lg leading-snug">
            The video can go up on its own, or sit until you say yes. Your own footage can be the start.
          </p>
        </article>
        <article className="glow-card p-6">
          <h2 className="text-sm text-muted">After TikTok</h2>
          <p className="kicker mt-4 text-2xl text-foreground">
            A time is offered. You confirm. The next one is closer.
          </p>
        </article>
        <article className="glow-card p-6">
          <h2 className="text-sm text-muted">After X</h2>
          <p className="mt-4 text-sm leading-relaxed text-muted">
            No calendar. It posts when the moment looks strong, then waits for another one like it.
          </p>
        </article>
      </section>

      <section>
        <h2 className="landing-section-title motion-copy">Why the description is per account</h2>
        <p className="motion-copy mt-4 max-w-3xl text-base leading-relaxed text-muted">
          The next piece follows the description you wrote for that account. Change it when the
          business changes, and what comes after uses the new one. Connecting the account is what
          lets you stop living inside the app. Describing it is what keeps the voice yours.
        </p>
      </section>
    </MarketingShell>
  );
}
