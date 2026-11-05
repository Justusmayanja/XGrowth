import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "Privacy Policy | Mackie17",
  description:
    "What Mackie17 collects, how it uses X, TikTok, and YouTube data, and how you can ask us to delete it.",
};

export default function PrivacyPage() {
  return (
    <LegalLayout title="Privacy Policy">
      <p className="text-sm">Last updated: 5 November 2026</p>

      <p>
        This policy is for Mackie17, the product at{" "}
        <a href="https://mackie17.com">mackie17.com</a>. It explains what we collect, why, who we
        send it to, and how you can get it deleted. It is written for this product, not copied from a
        generic scheduler.
      </p>
      <p>
        By creating an account or connecting a platform, you agree to this policy and to our{" "}
        <a href="/terms">Terms of Service</a>.
      </p>

      <h2>1. Who we are</h2>
      <p>
        Mackie17 is a growth desk for people who already run accounts on X, TikTok, and YouTube. You
        describe the account once. We watch what fits that account, draft in that voice, and publish
        only under the rule you set for that network. We do not send DMs. We do not follow people. We
        do not ask for your platform password.
      </p>
      <p>
        Questions:{" "}
        <a href="mailto:privacy@mackie17.com">privacy@mackie17.com</a> or{" "}
        <a href="mailto:info@mackie17.com">info@mackie17.com</a>.
      </p>

      <h2>2. Information we collect</h2>

      <h3>Account and sign-in</h3>
      <p>
        Name, username, and email. Sign-in is a six-digit code emailed to you. The code expires in ten
        minutes. We do not store a password for Mackie17. After you sign in we keep a session token in
        your browser so you stay signed in.
      </p>

      <h3>What you tell us about the account</h3>
      <p>
        Goals, niche, audience, tone, description, automation level, and whether posting, replies, or
        likes are on. Agency plans can store a separate voice per connected X account.
      </p>

      <h3>Connected X accounts</h3>
      <p>
        When you connect X, you approve access on X&apos;s own page. We receive an access token, a
        refresh token, your X user id, and username. Tokens are encrypted at rest. We use them only
        for the permissions you granted, which today are: read tweets, write tweets, read likes, read
        your profile, upload media, and stay connected when you are offline.
      </p>
      <p>From X we also read, when the feature needs it:</p>
      <ul className="list-disc space-y-2 pl-6">
        <li>Your recent posts and public metrics (impressions, likes, replies, reposts, profile visits)</li>
        <li>Your home timeline, so we can score whether a moment is worth a post</li>
        <li>Follower count and other profile figures shown on your desk</li>
        <li>Mentions and posts in your niche that we might draft a reply under, if you turn replies on</li>
      </ul>
      <p>
        We do not request Direct Messages. We do not store your X password. You can disconnect X in
        Settings. You can also revoke Mackie17 from X&apos;s connected-apps screen.
      </p>

      <h3>TikTok and YouTube</h3>
      <p>
        Those desks can already hold a description, drafts, and (for TikTok) a suggested time. When
        you connect those accounts, we will store the same kind of token and public account data, and
        only for the actions that network allows. Until a connection is live, we do not pull that
        network&apos;s private data.
      </p>

      <h3>Drafts, posts, and pictures</h3>
      <p>
        Text you write or that we draft for you, status (draft or posted), the predicted score we
        attached before it went out, and any picture we generated for an occasional X post. After a
        post is live we store the performance numbers X (or later TikTok and YouTube) give us, so the
        next draft can learn from what actually got replies.
      </p>

      <h3>AI context</h3>
      <p>
        To write in your voice we send the model a short brief: your description, audience, recent
        posts, and the conversation we are joining. That is how a draft stays in-niche instead of
        sounding like a bio.
      </p>

      <h3>Billing</h3>
      <p>
        Plan, platforms you bought, trial dates, and whether renewal is on. Card numbers and bank
        details go to Paddle or Flutterwave, not to us. We keep the payment reference they send back
        so we can open or close the desk.
      </p>

      <h3>Logs</h3>
      <p>
        Technical logs (time, path, error) so we can fix the product and keep posting at a human pace.
        We do not run a separate advertising pixel on the marketing site today.
      </p>

      <h2>3. How we use it</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Sign you in and keep the desk on the accounts you connected</li>
        <li>Score whether a moment on X is worth posting before anything goes live</li>
        <li>Draft and, if you turned posting on, publish in that account&apos;s voice</li>
        <li>
          Pace the account: plan caps, a freeze when X says slow down, and a hold when the last posts
          got no replies
        </li>
        <li>Suggest a TikTok time and wait for your yes</li>
        <li>Write or edit YouTube pieces and follow the publish setting you chose</li>
        <li>Charge the plan you picked and email service notices (codes, billing, safety)</li>
        <li>Stop abuse and blocked accounts</li>
      </ul>
      <p>We do not sell your personal data. We do not sell X, TikTok, or YouTube data.</p>

      <h2>4. What we send to other companies</h2>
      <p>Only what the feature needs:</p>
      <ul className="list-disc space-y-2 pl-6">
        <li>
          <strong>X.</strong> Publish, read the timeline, and pull your own metrics.{" "}
          <a href="https://x.com/privacy">X privacy policy</a>.
        </li>
        <li>
          <strong>OpenAI.</strong> Drafts, replies, scripts, and occasional pictures. Sent to provide
          the feature you asked for. We do not use your account as training data for our own model.
        </li>
        <li>
          <strong>Paddle</strong> or <strong>Flutterwave.</strong> Checkout and invoices.{" "}
          <a href="https://www.paddle.com/legal/privacy">Paddle privacy</a>.{" "}
          <a href="https://flutterwave.com/us/privacy-policy">Flutterwave privacy</a>.
        </li>
        <li>
          <strong>Our email provider.</strong> Sign-in codes and service mail from{" "}
          <a href="mailto:info@mackie17.com">info@mackie17.com</a>.
        </li>
        <li>
          <strong>Hosting and database.</strong> The servers that keep the product running.
        </li>
        <li>
          <strong>TikTok</strong> and <strong>YouTube / Google</strong>, once you connect those
          accounts.{" "}
          <a href="https://www.tiktok.com/legal/page/global/privacy-policy/en">TikTok privacy</a>.{" "}
          <a href="https://policies.google.com/privacy">Google privacy</a>.
        </li>
      </ul>
      <p>
        We share data with law enforcement only if a valid legal order requires it. If we can tell
        you without breaking the law, we will.
      </p>

      <h2>5. Cookies and local storage</h2>
      <p>
        The signed-in app keeps a session token in your browser so the desk stays yours. The site
        may use a small essential cookie for the same reason. We do not use that token to advertise
        on other sites. You can clear it by signing out or clearing site data; you will need a new
        email code to come back.
      </p>

      <h2>6. How long we keep it</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Sign-in codes: until they expire (ten minutes) or are used</li>
        <li>Account, setup, drafts, and connected tokens: while the account is open</li>
        <li>Posted performance: while we need it to score the next moment, then as long as the account stays</li>
        <li>Billing records: as long as tax and payment rules require</li>
        <li>
          After you ask us to delete the account: we remove the profile, tokens, drafts, and posts
          from the live product. Residual backups are dropped as those backups rotate, usually within
          30 days
        </li>
      </ul>

      <h2>7. Security</h2>
      <p>
        Traffic uses HTTPS. X tokens are encrypted. Daily post, reply, and like caps follow your
        plan, not a loose bot quota. If X returns a rate limit or a lock, that connected account
        freezes instead of retrying. No method is perfect. If we learn of a breach that affects you,
        we will email the address on the account.
      </p>

      <h2>8. Your choices</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Change the description, niche, and automation level in Settings</li>
        <li>Pause the engine so nothing new goes live</li>
        <li>Disconnect X (or later TikTok or YouTube) without deleting Mackie17</li>
        <li>Revoke Mackie17 inside X, TikTok, or YouTube</li>
        <li>Cancel renewal in billing; the paid period you already bought still runs to its end</li>
        <li>
          Email <a href="mailto:privacy@mackie17.com">privacy@mackie17.com</a> to access, correct, or
          delete your data. Export is not built in the product yet; we will send what we hold
        </li>
      </ul>
      <p>
        If you are in the UK, EEA, or another place with similar rules, you can also ask us to
        restrict or object to some processing, or complain to your local data authority.
      </p>

      <h2>9. Children</h2>
      <p>
        Mackie17 is for people 18 or older who already run a creator or business account. We do not
        knowingly collect data from anyone under 18. If we learn that we have, we will delete it.
      </p>

      <h2>10. Where data sits</h2>
      <p>
        The product and its processors may store data outside the country you live in. By using
        Mackie17 you understand that transfer. We only send what the feature needs.
      </p>

      <h2>11. Changes</h2>
      <p>
        If we change this policy in a way that matters, we will update the date at the top and email
        the address on the account or put a note in the desk. The current version is always on this
        page.
      </p>

      <h2>12. Contact</h2>
      <p>
        Privacy: <a href="mailto:privacy@mackie17.com">privacy@mackie17.com</a>
        <br />
        General: <a href="mailto:info@mackie17.com">info@mackie17.com</a>
      </p>
    </LegalLayout>
  );
}
