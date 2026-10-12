import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "Terms of Service | Mackie17",
  description: "Mackie17 terms of service.",
};

export default function TermsPage() {
  return (
    <LegalLayout title="Terms of Service">
      <p className="text-sm">Last updated: July 21, 2026</p>

      <p>
        By using Mackie17, you agree to these Terms of Service. Please read them before creating an
        account.
      </p>

      <h2>Service</h2>
      <p>
        Mackie17 provides tools for X, TikTok, and YouTube: topic scoring, drafts in your voice, timing,
        and optional publishing based on your settings. X can be connected today. TikTok and YouTube
        drafts are available before those account connections open.
      </p>

      <h2>Your responsibilities</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Comply with the terms of X, TikTok, and YouTube for every account you connect</li>
        <li>You are responsible for content published through your account</li>
        <li>Keep your login credentials secure</li>
        <li>Do not use Mackie17 for spam, harassment, or illegal activity</li>
      </ul>

      <h2>Automation</h2>
      <p>
        Mackie17 caps how often an account posts, replies, and follows. No tool can promise that a
        platform will skip enforcement. You choose how much can publish without you, and you accept
        that platform risk.
      </p>

      <h2>Billing</h2>
      <p>
        Paid plans bill monthly or yearly through Paddle or Flutterwave. Each platform is its own tier,
        and a mix of platforms is one payment. Cancel anytime; access continues until the end of the
        period you already paid.
      </p>

      <h2>Contact</h2>
      <p>
        <a href="mailto:legal@xpulse.app" className="text-accent hover:underline">
          legal@xpulse.app
        </a>
      </p>
    </LegalLayout>
  );
}
