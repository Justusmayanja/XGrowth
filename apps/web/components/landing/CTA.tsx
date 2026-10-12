import Link from "next/link";
import { ChevronRight } from "lucide-react";

export function CTA() {
  return (
    <section className="reveal mx-auto max-w-6xl px-6 py-20">
      <h2 className="landing-section-title motion-copy max-w-xl">
        Start with the accounts you already run.
      </h2>
      <p className="mt-4 max-w-xl text-base leading-relaxed text-muted">
        Describe them once. YouTube can make the video and post it, or wait for you. TikTok
        suggests a time and waits for your yes. X posts when the moment is right. No card
        required.
      </p>
      <div className="mt-8 flex flex-wrap items-center gap-5">
        <Link href="/register" className="th-cta">
          Start free
          <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
        <Link href="/login" className="text-sm font-medium text-[#f7f8f8]">
          Sign in
        </Link>
      </div>
    </section>
  );
}
