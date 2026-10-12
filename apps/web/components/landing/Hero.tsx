import Link from "next/link";
import { ChevronRight } from "lucide-react";
import { HeroShot } from "./HeroShot";

export function Hero() {
  return (
    <section className="th-hero">
      <div className="th-hero-copy">
        <h1 className="th-hero-serif">
          Build the audience.
          <br />
          Let the accounts
          <span className="th-hero-punch">EARN</span>
        </h1>
        <p className="th-hero-sub">
          Connect the account and describe it once.
          <br />
          YouTube can make the video. TikTok waits for you. X posts when the moment is right.
        </p>
        <div className="th-hero-actions">
          <Link href="/register" className="th-cta">
            Start free
            <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
          <p className="th-hero-note">No card required.</p>
        </div>
      </div>
      <HeroShot />
    </section>
  );
}
