"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { ChevronDown, ChevronRight } from "lucide-react";
import { BrandLogo } from "@/components/BrandLogo";

const linksBefore = [{ href: "/features", label: "Features" }];
const linksAfter = [
  { href: "/pricing", label: "Pricing" },
  { href: "/faq", label: "FAQ" },
];

const productLinks = [
  {
    href: "/how-it-works",
    title: "How it works",
    text: "Connect the accounts, then leave the apps.",
  },
  {
    href: "/channels",
    title: "Channels",
    text: "YouTube posts or waits. TikTok asks you. X sends when it is worth it.",
  },
];

export function Navbar() {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [productOpen, setProductOpen] = useState(false);
  const headerRef = useRef<HTMLElement>(null);

  useEffect(() => {
    setMobileOpen(false);
    setProductOpen(false);
  }, [pathname]);

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [mobileOpen]);

  useEffect(() => {
    if (!mobileOpen && !productOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setMobileOpen(false);
        setProductOpen(false);
      }
    };
    const onPointer = (event: MouseEvent) => {
      if (!headerRef.current?.contains(event.target as Node)) {
        setProductOpen(false);
        setMobileOpen(false);
      }
    };
    window.addEventListener("keydown", onKey);
    window.addEventListener("mousedown", onPointer);
    return () => {
      window.removeEventListener("keydown", onKey);
      window.removeEventListener("mousedown", onPointer);
    };
  }, [mobileOpen, productOpen]);

  function closeAll() {
    setMobileOpen(false);
    setProductOpen(false);
  }

  return (
    <header ref={headerRef} className="fixed inset-x-0 top-0 z-50">
      <div className="mx-auto max-w-[1168px] min-[992px]:px-6 min-[992px]:pt-4">
        <div
          className={`th-bar flex h-[63px] items-center justify-between py-[7px] pl-4 pr-2 min-[992px]:h-14 ${
            mobileOpen ? "is-open" : ""
          }`}
        >
          <BrandLogo className="text-[15px] text-[#f7f8f8]" />

          <nav className="hidden min-w-0 flex-1 items-center justify-center gap-6 min-[992px]:flex">
            {linksBefore.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                aria-current={pathname === link.href ? "page" : undefined}
                className="th-link"
              >
                {link.label}
              </Link>
            ))}

            <div
              className="relative"
              onMouseEnter={() => {
                if (window.innerWidth >= 992) setProductOpen(true);
              }}
              onMouseLeave={() => {
                if (window.innerWidth >= 992) setProductOpen(false);
              }}
            >
              <button
                type="button"
                className="th-link inline-flex cursor-pointer items-center gap-2"
                aria-expanded={productOpen}
                aria-haspopup="menu"
                onClick={() => {
                  if (window.innerWidth < 992) setProductOpen((open) => !open);
                  else setProductOpen(true);
                }}
              >
                Product
                <ChevronDown className={`h-3 w-3 ${productOpen ? "rotate-180" : ""}`} />
              </button>
              {productOpen && (
                <div className="absolute left-1/2 top-full z-10 w-[28rem] -translate-x-1/2 pt-2">
                  <div className="th-drop p-3">
                  <div className="grid grid-cols-2 gap-2">
                    {productLinks.map((item) => (
                      <Link
                        key={item.href}
                        href={item.href}
                        onClick={closeAll}
                        className="rounded-md px-3 py-2 hover:bg-white/[0.06]"
                      >
                        <span className="block text-sm text-[#f7f8f8]">{item.title}</span>
                        <span className="mt-1 block text-xs leading-snug text-[#f7f8f8]/55">
                          {item.text}
                        </span>
                      </Link>
                    ))}
                  </div>
                  </div>
                </div>
              )}
            </div>

            {linksAfter.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                aria-current={pathname === link.href ? "page" : undefined}
                className="th-link"
              >
                {link.label}
              </Link>
            ))}
          </nav>

          <div className="hidden shrink-0 items-center gap-1 min-[992px]:flex">
            <Link href="/login" className="th-link">
              Sign in
            </Link>
            <Link href="/register" className="th-cta">
              Get started for free
              <ChevronRight className="h-3.5 w-3.5" />
            </Link>
          </div>

          <button
            type="button"
            className="inline-flex h-12 w-12 cursor-pointer items-center justify-center text-[#f7f8f8]/80 min-[992px]:hidden"
            onClick={() =>
              setMobileOpen((open) => {
                if (!open) setProductOpen(false);
                return !open;
              })
            }
            aria-label={mobileOpen ? "Close menu" : "Open menu"}
            aria-expanded={mobileOpen}
            aria-controls="mobile-menu"
          >
            {mobileOpen ? (
              <span className="relative block h-3.5 w-4" aria-hidden="true">
                <span className="absolute left-0 top-1.5 h-px w-4 rotate-45 bg-current" />
                <span className="absolute left-0 top-1.5 h-px w-4 -rotate-45 bg-current" />
              </span>
            ) : (
              <span className="flex w-4 flex-col gap-[5px]" aria-hidden="true">
                <span className="h-px w-full bg-current" />
                <span className="h-px w-full bg-current" />
                <span className="h-px w-full bg-current" />
              </span>
            )}
          </button>
        </div>
      </div>

      {mobileOpen && (
        <div id="mobile-menu" className="th-menu px-4 pb-8 pt-3 min-[992px]:hidden">
          <nav className="mx-auto flex max-w-sm flex-col items-center gap-3">
            {linksBefore.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={closeAll}
                aria-current={pathname === link.href ? "page" : undefined}
                className="th-link"
              >
                {link.label}
              </Link>
            ))}
            <button
              type="button"
              className="th-link inline-flex cursor-pointer items-center gap-2"
              aria-expanded={productOpen}
              onClick={() => setProductOpen((open) => !open)}
            >
              Product
              <ChevronDown className={`h-3 w-3 ${productOpen ? "rotate-180" : ""}`} />
            </button>
            {productOpen && (
              <div className="flex w-full flex-col gap-1">
                {productLinks.map((item) => (
                  <Link
                    key={item.href}
                    href={item.href}
                    onClick={closeAll}
                    className="rounded-md px-3 py-2 text-center"
                  >
                    <span className="block text-sm text-[#f7f8f8]">{item.title}</span>
                    <span className="mt-1 block text-xs text-[#f7f8f8]/55">{item.text}</span>
                  </Link>
                ))}
              </div>
            )}
            {linksAfter.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={closeAll}
                aria-current={pathname === link.href ? "page" : undefined}
                className="th-link"
              >
                {link.label}
              </Link>
            ))}
            <Link href="/register" onClick={closeAll} className="th-cta mt-2 w-full">
              Get started for free
              <ChevronRight className="h-3.5 w-3.5" />
            </Link>
            <Link href="/login" onClick={closeAll} className="th-link mt-1">
              Sign in
            </Link>
          </nav>
        </div>
      )}
    </header>
  );
}
