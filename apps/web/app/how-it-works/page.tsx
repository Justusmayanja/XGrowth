import type { Metadata } from "next";
import Link from "next/link";
import { MarketingShell } from "@/components/landing/MarketingShell";
import { SideFrame } from "@/components/landing/SideFrame";

export const metadata: Metadata = {
  title: "How it works | Mackie17",
  description:
    "Connect YouTube, TikTok, or X and describe the account. YouTube can make the video and post it or wait. TikTok waits for your yes. X posts when the moment is right.",
};

const steps = [
  {
    title: "Connect, then describe the account",
    body: "Start free. Attach the YouTube channel, the TikTok account, or the X profile you already run. Say who it is for: the business, the brand, and how it should sound. Do this once per account. A shop and a channel do not share a sentence just because they sit in one login.",
  },
  {
    title: "YouTube finds what could catch on, then makes it",
    body: "After the channel is described, it looks for ideas that already feel ready to travel. It writes what should be said and makes the video. If you already filmed something, hand that over and it edits it into the piece instead. Set posting to run and it can go up while you are elsewhere. Leave it off and it waits until you confirm.",
  },
  {
    title: "TikTok suits the business, then waits for you",
    body: "It looks for ideas that fit the brand you described, writes what should be said, and makes the clip. Then it suggests a time. Nothing goes out until you confirm. After it is live, it pays attention to what people stayed for, and the next round sits closer.",
  },
  {
    title: "X posts when the moment is worth it",
    body: "Turn posting on. There is no schedule. It stays in the niche you named and sends when that moment looks strong enough to travel. It notices what landed, then waits for the next moment that feels the same before it posts again.",
  },
  {
    title: "It gets closer each time",
    body: "What actually goes out is what the next round learns from. TikTok gets nearer to what people stay for. X waits for another moment as strong as the last one that traveled. You can pause Mackie17 from Settings without disconnecting.",
  },
];

export default function HowItWorksPage() {
  return (
    <MarketingShell
      kicker="From the connection to the post"
      title="How a piece actually leaves."
      lede="Connect the account and describe it. YouTube can make the video and post it, or hold it until you say yes. TikTok makes the clip, offers a time, and waits. X has no calendar. It posts when the moment looks worth it."
    >
      <SideFrame
        src="/images/holo-plates.jpg"
        alt="Layered blue plates of light on a dark field."
      >
        <p className="text-sm text-muted">The split</p>
        <h2 className="display motion-copy mt-3 text-4xl leading-tight">Three networks. Not one switch.</h2>
        <p className="kicker mt-4 text-2xl text-foreground">
          YouTube posts or waits. TikTok asks you. X sends when it is worth it.
        </p>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          A quiet day stays quiet. TikTok never leaves without your yes. X is not put on a
          timetable. YouTube follows the setting you chose: on its own, or waiting for you.
        </p>
      </SideFrame>

      <ol className="grid gap-4">
        {steps.map((step, index) => (
          <li key={step.title} className="glow-card p-6">
            <p className="text-sm text-muted">{index + 1}</p>
            <h2 className="landing-section-title motion-copy mt-2">{step.title}</h2>
            <p className="motion-copy mt-4 max-w-3xl text-base leading-relaxed text-muted">{step.body}</p>
          </li>
        ))}
      </ol>

      <SideFrame
        src="/images/engraved-formats.jpg"
        alt="Fine blue lines engraved into a dark surface."
        flip
      >
        <h2 className="kicker motion-copy text-3xl text-foreground">After the accounts are on.</h2>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          Most days you are back at the work. YouTube may already have posted, or be holding a
          video for your yes. TikTok is the one you still open: see the time it suggested, confirm
          it, then go back to the shop or the studio. X sends when the moment looks strong, and
          not before.
        </p>
        <p className="mt-6 text-sm text-muted">
          The per-network rules are on{" "}
          <Link href="/channels" className="text-foreground underline underline-offset-4">
            Channels
          </Link>
          .
        </p>
      </SideFrame>
    </MarketingShell>
  );
}
