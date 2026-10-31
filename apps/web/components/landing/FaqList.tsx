"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";

const faqs = [
  {
    q: "How does YouTube work?",
    a: "Connect the channel and describe who it is for. It looks for ideas that already feel ready to catch on, writes what should be said, and makes the video. If you already filmed something, hand that over and it edits it. Set posting to run and it can go up on its own. Leave it off and it waits until you confirm.",
  },
  {
    q: "How does TikTok work?",
    a: "Connect the account and describe the business or the brand. It looks for ideas that suit that world, writes what should be said, and makes the clip. It suggests a time, then waits. Nothing posts until you confirm. After it is live, it pays attention to what people stayed for, and the next one gets closer.",
  },
  {
    q: "How does X work?",
    a: "Connect the profile, describe the niche, and turn posting on. There is no schedule. It stays in that world and posts when the moment looks strong enough to travel. It notices what landed, then waits for the next moment that feels the same before it posts again.",
  },
  {
    q: "What do I write in the account description?",
    a: "Who it is for, the business or the brand, and how it should sound. Write it once per account. That is what keeps the next piece in your world.",
  },
  {
    q: "Can I bring something I already made?",
    a: "Yes, on YouTube. Hand over footage you already filmed and it edits that into the piece. The point stays yours. Then it posts on its own, or waits until you confirm, depending on what you set.",
  },
  {
    q: "Will it post on a quiet day just to stay active?",
    a: "No. If nothing looks worth saying, it holds. On X it waits for a moment that looks strong enough. A quiet week does not get a filler post.",
  },
  {
    q: "Does TikTok post on its own?",
    a: "No. It makes the clip, suggests a time, and waits. You confirm, and only then does it post. That check stays with you.",
  },
  {
    q: "Can this get an account restricted?",
    a: "Any tool that posts for you carries that risk. The pace is meant to stay human. Mackie17 does not promise a platform will skip its own rules. You choose how much can go out without you.",
  },
  {
    q: "Do I have to be at the computer?",
    a: "Not for X, once posting is on: it sends when the moment is worth it. Not for YouTube, if you set it to post on its own. TikTok is the one you still open. It suggests a time, and it waits until you confirm.",
  },
  {
    q: "What happens when the free trial ends?",
    a: "The trial needs no card. When it ends, you choose a package if you want Mackie17 to keep working. Until you do, the trial is over.",
  },
  {
    q: "Can I cancel?",
    a: "Yes. Cancel renewal from billing settings. A paid plan runs until the end of the period you already paid, then it stops.",
  },
  {
    q: "Can I change a piece before it goes out?",
    a: "Yes. Drafts stay editable. TikTok does not post until you confirm. YouTube posts on its own only if you set that. On X, pause from Settings and the next one waits.",
  },
  {
    q: "Do you store my password?",
    a: "No. You approve the connection on YouTube, TikTok, or X. Mackie17 never sees the password.",
  },
];

export function FaqList() {
  const [open, setOpen] = useState<number | null>(0);

  return (
    <div className="space-y-2">
      {faqs.map((faq, i) => {
        const isOpen = open === i;
        const panelId = `faq-page-${i}`;
        return (
          <div key={faq.q} className="border-b border-[#1c3048]">
            <button
              type="button"
              className="flex w-full cursor-pointer items-center justify-between gap-4 py-4 text-left text-base font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
              aria-expanded={isOpen}
              aria-controls={panelId}
              onClick={() => setOpen(isOpen ? null : i)}
            >
              <span className="motion-copy">{faq.q}</span>
              <ChevronDown
                className={`h-4 w-4 shrink-0 text-muted transition-transform duration-200 ${isOpen ? "rotate-180" : ""}`}
                aria-hidden="true"
              />
            </button>
            {isOpen && (
              <p id={panelId} className="motion-copy pb-4 text-base leading-relaxed text-muted">
                {faq.a}
              </p>
            )}
          </div>
        );
      })}
    </div>
  );
}
