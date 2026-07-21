import Link from "next/link";
import { Footer } from "@/components/landing/Footer";
import { Navbar } from "@/components/landing/Navbar";

export function LegalLayout({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="min-h-screen">
      <Navbar />
      <main className="mx-auto max-w-3xl px-6 pb-24 pt-32">
        <Link href="/" className="text-sm text-accent hover:underline">
          ← Back to home
        </Link>
        <h1 className="mt-6 text-4xl font-bold">{title}</h1>
        <div className="prose prose-invert mt-8 max-w-none space-y-6 text-muted [&_h2]:text-white [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:mt-8">
          {children}
        </div>
      </main>
      <Footer />
    </div>
  );
}
