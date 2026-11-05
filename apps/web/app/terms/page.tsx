import type { Metadata } from "next";
import { LegalLayout } from "@/components/landing/LegalLayout";

export const metadata: Metadata = {
  title: "Terms of Service | Mackie17",
  description:
    "Rules for using Mackie17, connecting X, TikTok, or YouTube, and what happens if a platform acts on an account.",
};

export default function TermsPage() {
  return (
    <LegalLayout title="Terms of Service">
      <p className="text-sm">Last updated: 5 November 2026</p>

      <p>
        These terms are a contract between you and Mackie17 for the product at{" "}
        <a href="https://mackie17.com">mackie17.com</a>. They sit next to our{" "}
        <a href="/privacy">Privacy Policy</a>. If you do not agree, do not create an account and do
        not connect a platform.
      </p>

      <h2>1. The service</h2>
      <p>
        Mackie17 is a growth desk for accounts you already run. It is not a shared content calendar
        and it is not a bot that posts the same asset on every network.
      </p>
      <ul className="list-disc space-y-2 pl-6">
        <li>
          <strong>X.</strong> Watches the niche and scores whether a moment can travel. There is no
          posting clock. If you turn posting on, a strong moment can go live. A quiet day stays
          quiet.
        </li>
        <li>
          <strong>TikTok.</strong> Looks for a fit, writes the piece, makes or prepares the clip,
          suggests a time, and waits. Nothing posts until you confirm.
        </li>
        <li>
          <strong>YouTube.</strong> Looks for a fit, writes, and makes the video — or edits footage
          you brought — then posts on its own or waits, depending on the setting you chose.
        </li>
      </ul>
      <p>
        X can be connected today. TikTok and YouTube desks and drafts exist; live posting on those
        networks follows the connection and features we have actually shipped. We may change,
        pause, or retire a feature.
      </p>

      <h2>2. Who can use it</h2>
      <p>
        You must be at least 18, able to form a contract, and allowed to run the accounts you
        connect. One person or business per Mackie17 account, unless you are on a plan that allows
        more than one connected profile (Agency on X).
      </p>

      <h2>3. Your Mackie17 account</h2>
      <p>
        Sign-in is an email code, not a password. You are responsible for the inbox that receives
        the code and for everything done while you are signed in, including actions the growth
        engine takes under the settings you left on. If you think someone else used the account,
        email <a href="mailto:info@mackie17.com">info@mackie17.com</a>.
      </p>
      <p>We may refuse, suspend, or close an account that breaks these terms or a platform&apos;s rules.</p>

      <h2>4. Connecting X, TikTok, and YouTube</h2>
      <p>
        You connect an account on that platform&apos;s own page. We never see the password. You
        promise that you own or are allowed to operate every account you connect, and that granting
        us access does not break that platform&apos;s terms.
      </p>
      <p>
        Your relationship with X, TikTok, and YouTube is only with them. Their terms still apply
        when Mackie17 posts, replies, likes, reads a timeline, or uploads media for you. Start here:{" "}
        <a href="https://x.com/tos">X terms</a>,{" "}
        <a href="https://www.tiktok.com/legal/page/global/terms-of-service/en">TikTok terms</a>,{" "}
        <a href="https://www.youtube.com/t/terms">YouTube terms</a>.
      </p>
      <p>
        If a platform cuts the connection, changes its API, or limits what a token can do, parts of
        Mackie17 will stop for that account. That is not a breach by us.
      </p>

      <h2>5. Automation and account safety</h2>
      <p>
        You choose how much can go out without you: suggest only, you approve, post the strong
        moments, or full automation. You can pause the engine. On X we also:
      </p>
      <ul className="list-disc space-y-2 pl-6">
        <li>Keep posts, replies, and likes at a human pace for your plan</li>
        <li>Hold a draft when the prediction says the room is quiet or the take is not worth sending</li>
        <li>Freeze that connected account for hours if X returns a rate limit, lock, or similar flag</li>
        <li>Wait for a louder moment if the last posts on that account got no replies</li>
        <li>Skip a follow. Mackie17 does not follow people</li>
      </ul>
      <p>
        Those guards are there so the account stays at a human pace. They are not a promise that X,
        TikTok, or YouTube will leave the account alone. Any tool that posts for you can draw a
        restriction, a label, or a ban. You accept that risk when you turn posting on.
      </p>

      <h2>6. Content</h2>
      <p>
        You keep the rights in what you write, the footage you upload, and what we draft for your
        account. You give Mackie17 a licence to store it, send it to the model, show it on the desk,
        generate an occasional picture, and publish it to the accounts you connected, for as long as
        the account is open.
      </p>
      <p>
        You are responsible for every post, reply, like, and upload that leaves through your
        connected accounts, including text the model wrote. Check drafts when you want a human in
        the loop. Do not ask the product to publish anything you would not stand behind.
      </p>
      <p>
        The model can be wrong, bland, or off-niche. A &quot;worth posting&quot; score is a
        prediction from the timeline, the draft, and this account&apos;s recent results. It is not a
        guarantee of impressions, followers, or payouts.
      </p>

      <h2>7. What you must not do</h2>
      <ul className="list-disc space-y-2 pl-6">
        <li>Spam, scrape, or run engagement pods through Mackie17</li>
        <li>
          Mass-mention, mass-reply, or unsolicited DMs. The product does not send DMs; do not use it
          as a workaround for that
        </li>
        <li>Harass, impersonate, or post illegal, hateful, or sexually exploitative content</li>
        <li>Connect an account you do not control</li>
        <li>Try to beat X, TikTok, or YouTube rules, or Mackie17&apos;s posting pace</li>
        <li>Open extra Mackie17 accounts to stack trials or dodge a block</li>
        <li>Copy, resell, or attack the product</li>
      </ul>
      <p>
        If you use Mackie17 in a way that breaks a platform&apos;s rules, you alone deal with the
        lock, the label, or the ban. We may close the Mackie17 account as well.
      </p>

      <h2>8. Billing</h2>
      <p>
        Each network is its own line (Starter, Pro, Agency). A mix of networks is one checkout.
        Yearly billing charges ten months. Checkout runs through Paddle or Flutterwave. We do not
        store your card.
      </p>
      <p>
        The free trial is 7 days and does not need a card. When it ends, the desk stops working until you pick a
        paid package. Paid plans renew until you cancel. Cancel renewal in Settings; access continues
        until the end of the period you already paid. We do not pro-rate a mid-cycle cancel unless
        the payment provider requires it or we agree in writing.
      </p>
      <p>
        Prices can change at the next cycle. We will show the new price before we charge it. If a
        payment fails, we may retry and then pause the desk.
      </p>

      <h2>9. Our rights in the product</h2>
      <p>
        Mackie17, the desk, the scoring, and the brand stay ours. You do not get a licence to copy
        the product. Feedback you send us can be used to improve it without paying you.
      </p>

      <h2>10. It is offered as-is</h2>
      <p>
        We work to keep the desk up and the pace human. We do not warrant that a post will go out, a
        moment will be scored right, a platform will accept the write, or an account will grow. The
        service is provided as-is and as-available, to the extent the law allows.
      </p>

      <h2>11. If something goes wrong</h2>
      <p>
        To the extent the law allows, Mackie17 is not liable for lost posts, lost followers, a
        platform penalty, lost profits, or data you can restore from the platform itself. If we are
        liable anyway, the cap is the amount you paid us for that network in the three months before
        the claim.
      </p>

      <h2>12. Closing the account</h2>
      <p>
        You can stop using Mackie17, disconnect a platform, and email us to delete the account. We
        can close or suspend an account for a breach, non-payment, or risk to other users or to a
        platform. After deletion we drop live data as described in the Privacy Policy. You still owe
        any unpaid fees.
      </p>

      <h2>13. Changes</h2>
      <p>
        We can update these terms. The date at the top will change. If the change is material we
        will email you or show a note in the desk. Using Mackie17 after that is acceptance. If you
        do not agree, disconnect the accounts and stop using the product.
      </p>

      <h2>14. Contact</h2>
      <p>
        Legal: <a href="mailto:legal@mackie17.com">legal@mackie17.com</a>
        <br />
        General: <a href="mailto:info@mackie17.com">info@mackie17.com</a>
      </p>
    </LegalLayout>
  );
}
