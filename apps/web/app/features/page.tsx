import type { Metadata } from "next";
import { MarketingShell } from "@/components/landing/MarketingShell";
import { SideFrame } from "@/components/landing/SideFrame";

export const metadata: Metadata = {
  title: "Features | Mackie17",
  description:
    "Connect YouTube, TikTok, or X and describe the account. YouTube can post or wait. TikTok waits for your yes. X posts when the moment is right.",
};

export default function FeaturesPage() {
  return (
    <MarketingShell
      kicker="The desk"
      title="Connect the accounts. Then stay with the business."
      lede="The first job is the connection, and a plain description of who the account is for. After that, YouTube can make the video and post it or wait for you. TikTok makes the clip, suggests a time, and waits. X posts when the moment is right."
    >
      <SideFrame
        src="/images/node-network.jpg"
        alt="Glowing blue nodes linked across a dark grid."
      >
        <p className="text-sm text-muted">Connect</p>
        <h2 className="display motion-copy mt-3 text-4xl leading-tight">One sitting to attach the accounts.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Connect the account and describe it: the business, the brand, who it is for, and how it
          should sound. On YouTube it looks for ideas that already feel ready to catch on, writes
          what should be said, and makes the video. Bring footage you already filmed and it edits
          that instead. Then it posts on its own, or waits until you confirm. TikTok does the same
          kind of work for the brand, then suggests a time and waits for your yes. X stays in the
          niche and posts when the moment looks strong enough. There is no schedule on X.
        </p>
      </SideFrame>

      <SideFrame
        src="/images/wire-mesh.jpg"
        alt="A blue wireframe mesh in a dark field."
        flip
      >
        <p className="text-sm text-muted">Compose</p>
        <h2 className="kicker motion-copy mt-3 text-3xl text-foreground">A rough note, three cuts.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Bring a rough note, or footage you already have. On YouTube it can start from what you
          filmed and edit that into the piece, or make the video from an idea that fits the
          channel. On TikTok it makes the clip for the brand you described. On X it writes the
          post in that account&apos;s voice. The point stays yours.
        </p>
      </SideFrame>

      <div className="grid gap-4 md:grid-cols-2">
        <article className="glow-card lean-left p-6">
            <h2 className="display text-2xl">Topics</h2>
            <p className="mt-3 text-sm leading-relaxed text-muted">
              After you describe the account, it watches for ideas that fit. On YouTube, ideas that
              already feel ready to catch on. On TikTok, ideas that suit the business. On X, a
              moment strong enough to be worth a post. If that moment has passed, it holds.
            </p>
        </article>
        <article className="glow-card p-6">
            <h2 className="display text-2xl">Drafts and the queue</h2>
            <p className="mt-3 text-sm leading-relaxed text-muted">
              YouTube can leave on its own when you set that, or sit until you confirm. TikTok
              suggests a time and does not post until you say yes. X is not queued for a clock
              time. It posts when the moment looks strong enough.
            </p>
        </article>
        <article className="glow-card p-6">
            <h2 className="text-sm text-muted">Replies</h2>
            <p className="mt-3 text-sm leading-relaxed text-muted">
              Replies drafts a response in a conversation that is already happening. Near the daily
              reply cap, it holds. It does not jump into every thread, and it does not send DMs.
            </p>
        </article>
        <article className="glow-card p-6">
            <h2 className="kicker text-3xl text-foreground">Results</h2>
            <p className="mt-3 text-sm leading-relaxed text-muted">
              Results is the record of what went out and which pieces earned attention. Take a winner
              back into Compose. It is a log, not a promise about tomorrow&apos;s numbers.
            </p>
        </article>
      </div>

      <SideFrame
        src="/images/mesh-surface.jpg"
        alt="A rolling neon-blue triangular mesh."
      >
        <p className="text-sm text-muted">Limits</p>
        <h2 className="display motion-copy mt-3 text-4xl">A quiet day still stays quiet.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          It will not post just to fill a gap. On X it waits for a moment that looks worth it.
          On TikTok it still needs your yes. You can pause Mackie17 from Settings without
          disconnecting, and get back to the shop, the studio, or the film day.
        </p>
      </SideFrame>
    </MarketingShell>
  );
}
