import type { Metadata } from "next";
import { FaqList } from "@/components/landing/FaqList";
import { MarketingShell } from "@/components/landing/MarketingShell";
import { SideFrame } from "@/components/landing/SideFrame";

export const metadata: Metadata = {
  title: "FAQ | Mackie17",
  description:
    "What can publish today, how the four automation levels differ, and what the daily limits cover.",
};

export default function FaqPage() {
  return (
    <MarketingShell
      kicker="Before you connect anything"
      title="Connect first. Then ask the smaller questions."
      lede="YouTube can make the video and post it, or wait for you. TikTok makes the clip, suggests a time, and waits for your yes. X posts when the moment is right, with no schedule. The rest of these answers sit under that."
    >
      <SideFrame src="/images/mesh-surface.jpg" alt="A rolling blue mesh on a dark field.">
        <p className="text-sm text-muted">The short version</p>
        <h2 className="display motion-copy mt-3 text-4xl leading-tight">Three accounts. Three ways out.</h2>
        <p className="kicker mt-4 text-2xl leading-snug text-foreground">
          Attach them, describe them, and go back to the work that is not the apps.
        </p>
        <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
          YouTube can leave on its own, or wait for your yes. TikTok always waits, after it has
          offered a time. X has no calendar. It posts when the moment looks worth it, and it will
          not post just to fill a quiet day.
        </p>
      </SideFrame>
      <FaqList />
    </MarketingShell>
  );
}
