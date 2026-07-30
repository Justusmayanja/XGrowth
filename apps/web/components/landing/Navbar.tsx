"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Menu, X } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";

const navLinks = [
  { href: "#features", label: "Features" },
  { href: "#how-it-works", label: "How it works" },
  { href: "#pricing", label: "Pricing" },
  { href: "#faq", label: "FAQ" },
];

export function Navbar() {
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);

  function closeMenu() {
    setMobileOpen(false);
  }

  return (
    <header className="fixed inset-x-0 top-0 z-50 landing-glass border-b border-white/5 py-4">
      <div className="mx-auto flex max-w-6xl items-center justify-between px-4 sm:px-6">
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

        <div className="flex items-center gap-2 sm:gap-3">
          <Link
            href="/login"
            className="btn btn-secondary hidden px-4 py-2 text-sm sm:inline-flex"
          >
            Sign in
          </Link>
          <Link href="/register" className="btn btn-primary px-3 py-2 text-sm sm:px-4">
            Start now
          </Link>
          <button
            type="button"
            className="rounded-lg p-2 text-muted transition hover:bg-white/5 hover:text-white md:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Open menu"
            aria-expanded={mobileOpen}
          >
            <Menu className="h-5 w-5" />
          </button>
        </div>
      </div>

      {mobileOpen && (
        <>
          <button
            type="button"
            className="fixed inset-0 z-40 bg-black/60 md:hidden"
            onClick={closeMenu}
            aria-label="Close menu overlay"
          />
          <div className="fixed inset-x-0 top-[65px] z-50 max-h-[calc(100dvh-65px)] overflow-y-auto border-b border-white/10 bg-surface/95 px-4 py-6 backdrop-blur-xl md:hidden">
            <div className="relative mx-auto flex max-w-6xl flex-col gap-6 pt-10">
              <button
                type="button"
                className="absolute right-0 top-0 rounded-lg p-2 text-muted hover:bg-white/5 hover:text-white"
                onClick={closeMenu}
                aria-label="Close menu"
              >
                <X className="h-5 w-5" />
              </button>

              <nav className="flex flex-col gap-1">
                {navLinks.map((link) => (
                  <a
                    key={link.href}
                    href={link.href}
                    onClick={closeMenu}
                    className="rounded-lg px-3 py-3 text-base text-muted transition hover:bg-white/5 hover:text-white"
                  >
                    {link.label}
                  </a>
                ))}
              </nav>

              <div className="flex flex-col gap-3 border-t border-white/10 pt-4">
                <Link
                  href="/login"
                  onClick={closeMenu}
                  className="btn btn-secondary w-full py-3 text-sm"
                >
                  Sign in
                </Link>
                <Link
                  href="/register"
                  onClick={closeMenu}
                  className="btn btn-primary w-full py-3 text-sm"
                >
                  Start now — it&apos;s free
                </Link>
              </div>
            </div>
          </div>
        </>
      )}
    </header>
  );
}
