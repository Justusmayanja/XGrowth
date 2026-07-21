import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "About — Xpulse",
  description: "Learn about Xpulse — grow on X when the moment is right.",
};

export default function AboutPage() {
  return (
    <LegalLayout title="About Xpulse">
      <p>
        Xpulse helps creators, founders, and niche accounts grow on X (Twitter). Unlike schedulers
        that post at fixed times, Xpulse watches trends in your niche and helps you show up when
        people are actually paying attention.
      </p>

      <h2>Our mission</h2>
      <p>
        Help you grow followers, impressions, and monetization eligibility without spending hours on
        the platform every day. Growth should follow opportunity — not a calendar.
      </p>

      <h2>What Xpulse does</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Tracks trends and conversations in your niche</li>
        <li>Scores the best moments to post or engage</li>
        <li>Drafts content matched to your voice and goals</li>
        <li>Protects your account with smart limits and natural pacing</li>
        <li>Keeps working while you are offline</li>
      </ul>

      <h2>Who it&apos;s for</h2>
      <p>
        Football fans, tech founders, finance creators, local news accounts, meme pages, and anyone
        building an audience on X who wants intelligent growth — not another empty posting schedule.
      </p>

      <h2>Contact</h2>
      <p>
        Questions or partnerships?{" "}
        <a href="mailto:hello@xpulse.app" className="text-accent hover:underline">
          hello@xpulse.app
        </a>
      </p>
    </LegalLayout>
  );
}
