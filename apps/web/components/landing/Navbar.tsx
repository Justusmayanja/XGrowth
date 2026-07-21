"use client";

import Link from "next/link";
import { BrandLogo } from "@/components/BrandLogo";

const navLinks = [
  { href: "#features", label: "Features" },
  { href: "#how-it-works", label: "How it works" },
  { href: "#pricing", label: "Pricing" },
  { href: "#faq", label: "FAQ" },
];

export function Navbar() {
  return (
    <header className="fixed inset-x-0 top-0 z-50 landing-glass border-b border-white/5 py-4">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-6">
        <BrandLogo className="text-lg" />

        <nav className="hidden items-center gap-8 md:flex">
          {navLinks.map((link) => (
            <a
              key={link.href}
              href={link.href}
              className="text-sm text-muted transition hover:text-white"
            >
              {link.label}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-3">
          <Link href="/login" className="btn btn-secondary hidden px-4 py-2 text-sm sm:inline-flex">
            Sign in
          </Link>
          <Link href="/register" className="btn btn-primary px-4 py-2 text-sm">
            Start now
          </Link>
        </div>
      </div>
    </header>
  );
}
