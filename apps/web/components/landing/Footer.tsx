import Link from "next/link";
import { BrandLogo } from "@/components/BrandLogo";

const columns = [
  {
    title: "Product",
    links: [
      { href: "/features", label: "Features" },
      { href: "/how-it-works", label: "How it works" },
      { href: "/channels", label: "Channels" },
      { href: "/pricing", label: "Pricing" },
      { href: "/faq", label: "FAQ" },
    ],
  },
  {
    title: "Company",
    links: [
      { href: "/about", label: "About" },
      { href: "/privacy", label: "Privacy" },
      { href: "/terms", label: "Terms" },
    ],
  },
  {
    title: "Account",
    links: [
      { href: "/login", label: "Sign in" },
      { href: "/register", label: "Start free" },
    ],
  },
];

export function Footer() {
  return (
    <footer className="site-footer">
      <div className="mx-auto max-w-6xl px-6 pt-16">
        <div className="grid gap-12 border-b border-[#1c3048] pb-12 md:grid-cols-12">
          <div className="md:col-span-5">
            <BrandLogo />
            <p className="mt-4 max-w-sm text-sm leading-relaxed text-[#b7c6d8]">
              Grow the business online, improve the brand, and let the account start earning.
              Research, editing, and posting, after you describe the business once.
            </p>
            <Link href="/register" className="th-cta mt-6">
              Start free
            </Link>
          </div>

          <div className="grid grid-cols-2 gap-8 sm:grid-cols-3 md:col-span-7">
            {columns.map((column) => (
              <div key={column.title}>
                <h2 className="mb-4 text-sm font-medium text-foreground">{column.title}</h2>
                <ul className="space-y-3">
                  {column.links.map((link) => (
                    <li key={link.href}>
                      <Link
                        href={link.href}
                        className="text-sm text-[#d5e2f0] transition-colors hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                      >
                        {link.label}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>

        <div className="flex flex-col gap-3 py-6 text-sm text-[#9eb0c4] sm:flex-row sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} Mackie17</p>
          <p>Grow your business online</p>
          <nav className="flex gap-5" aria-label="Legal">
            <Link href="/about" className="hover:text-white">
              About
            </Link>
            <Link href="/privacy" className="hover:text-white">
              Privacy
            </Link>
            <Link href="/terms" className="hover:text-white">
              Terms
            </Link>
          </nav>
        </div>
      </div>
    </footer>
  );
}
