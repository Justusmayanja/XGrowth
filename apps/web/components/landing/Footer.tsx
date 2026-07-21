import Link from "next/link";
import { BrandLogo } from "@/components/BrandLogo";

const footerLinks = {
  Product: [
    { href: "/#features", label: "Features" },
    { href: "/#how-it-works", label: "How it works" },
    { href: "/#pricing", label: "Pricing" },
    { href: "/#faq", label: "FAQ" },
  ],
  Company: [
    { href: "/about", label: "About" },
    { href: "/privacy", label: "Privacy Policy" },
    { href: "/terms", label: "Terms of Service" },
  ],
  App: [
    { href: "/login", label: "Sign in" },
    { href: "/register", label: "Start now" },
    { href: "/dashboard", label: "Dashboard" },
  ],
};

export function Footer() {
  return (
    <footer className="border-t border-white/5 bg-surface/50 py-16">
      <div className="mx-auto max-w-6xl px-6">
        <div className="grid gap-12 md:grid-cols-4">
          <div>
            <BrandLogo className="text-lg" />
            <p className="mt-4 text-sm text-muted">
              Grow on X when the moment is right. Trend-aware posting, smart timing, and account
              protection built in.
            </p>
          </div>

          {Object.entries(footerLinks).map(([title, links]) => (
            <div key={title}>
              <h4 className="mb-4 text-sm font-semibold uppercase tracking-wider text-muted">
                {title}
              </h4>
              <ul className="space-y-2">
                {links.map((link) => (
                  <li key={link.href}>
                    <Link href={link.href} className="text-sm text-muted transition hover:text-white">
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-white/5 pt-8 sm:flex-row">
          <p className="text-sm text-muted">© {new Date().getFullYear()} Xpulse. All rights reserved.</p>
          <p className="text-xs text-muted">Built for creators worldwide · Paddle & Flutterwave</p>
        </div>
      </div>
    </footer>
  );
}
