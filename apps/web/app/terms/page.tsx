import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "Terms of Service | Xpulse",
  description: "Xpulse terms of service.",
};

export default function TermsPage() {
  return (
    <LegalLayout title="Terms of Service">
      <p className="text-sm">Last updated: July 21, 2026</p>

      <p>
        By using Xpulse, you agree to these Terms of Service. Please read them before creating an
        account.
      </p>

      <h2>Service</h2>
      <p>
        Xpulse provides tools to grow on X (Twitter), including trend monitoring, content drafting,
        timing recommendations, and optional automated posting based on your settings.
      </p>

      <h2>Your responsibilities</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Comply with X&apos;s Terms of Service at all times</li>
        <li>You are responsible for content published through your account</li>
        <li>Keep your login credentials secure</li>
        <li>Do not use Xpulse for spam, harassment, or illegal activity</li>
      </ul>

      <h2>Automation</h2>
      <p>
        Xpulse includes account protection features, but no tool can guarantee immunity from platform
        enforcement. You choose your level of automation and accept the associated platform risk.
      </p>

      <h2>Billing</h2>
      <p>
        Paid plans bill monthly through Paddle or Flutterwave. Cancel anytime; access continues until
        the end of your billing period.
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
