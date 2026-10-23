import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "Privacy Policy | Mackie17",
  description: "Mackie17 privacy policy.",
};

export default function PrivacyPage() {
  return (
    <LegalLayout title="Privacy Policy">
      <p className="text-sm">Last updated: October 23, 2026</p>

      <p>
        Mackie17 (&quot;we&quot;, &quot;our&quot;, &quot;us&quot;) respects your privacy. This policy
        explains what information we collect and how we use it.
      </p>

      <h2>Information we collect</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Account details: name, username, and email. Sign-in uses a short-lived code sent to that email.</li>
        <li>Setup answers: goals, niche, audience, tone, and preferences</li>
        <li>Connected platform tokens (encrypted) when you authorize access</li>
        <li>Drafts, posts, and performance data from your use of the service</li>
        <li>Usage logs and device information</li>
      </ul>

      <h2>How we use your information</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Provide and personalize the Mackie17 service for your account</li>
        <li>Draft and schedule content aligned with your profile</li>
        <li>Monitor account safety and enforce platform limits</li>
        <li>Process subscriptions via Paddle or Flutterwave</li>
        <li>Send important service updates</li>
      </ul>

      <h2>Data sharing</h2>
      <p>
        We do not sell your personal data. We share information only with providers needed to run
        Mackie17 (hosting, payments, and content processing). Connected account data is used only for
        actions you authorize.
      </p>

      <h2>Your rights</h2>
      <p>
        Contact{" "}
        <a href="mailto:privacy@xpulse.app" className="text-accent hover:underline">
          privacy@xpulse.app
        </a>{" "}
        to access, correct, or delete your data. You can disconnect a platform and delete your account
        from Settings.
      </p>
    </LegalLayout>
  );
}
