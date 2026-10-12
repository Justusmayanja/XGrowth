import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "About | Mackie17",
  description: "Mackie17 connects YouTube, TikTok, and X, then finds what fits, makes the piece, and posts the way each network should.",
};

export default function AboutPage() {
  return (
    <LegalLayout title="About Mackie17">
      <p>
        Mackie17 is for people who already have an account on YouTube, TikTok, or X, and usually
        on more than one of them. You connect the accounts and describe who they are for. After
        that it looks for what fits, makes the piece, and posts the way that network should.
      </p>

      <h2>What you bring</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>The accounts, and a plain description of the business, the brand, and who they are for</li>
        <li>Footage you already filmed, if you want YouTube to start from that</li>
      </ul>

      <h2>What each account does</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>YouTube looks for what could catch on, writes it, and makes the video — or edits footage you brought — then posts on its own or waits for you</li>
        <li>TikTok looks for what suits the business, writes it, and makes the clip, then suggests a time and waits for you to confirm</li>
        <li>X stays in your niche and posts when the moment looks strong, with no schedule, then waits for the next moment like it</li>
      </ul>

      <h2>After it goes out</h2>
      <p>
        Connect the accounts you already run, describe them, and then stay with the rest of the
        business. What actually gets posted is what the next round pays attention to, so TikTok
        gets closer to what people stay for, and X posts again only when the moment looks just
        as strong.
      </p>

      <h2>Contact</h2>
      <p>
        Questions or partnerships:{" "}
        <a href="mailto:hello@xpulse.app">hello@xpulse.app</a>
      </p>
    </LegalLayout>
  );
}
