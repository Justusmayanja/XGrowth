import Link from "next/link";
import { Footer } from "@/components/landing/Footer";
import { MotionCopy } from "@/components/landing/MotionCopy";
import { Navbar } from "@/components/landing/Navbar";
import { ScrollField } from "@/components/landing/ScrollField";
import { SectionDrop } from "@/components/landing/SectionDrop";

export function LegalLayout({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="landing-canvas min-h-screen">
      <ScrollField />
      <SectionDrop />
      <MotionCopy />
      <Navbar />
      <main className="mx-auto max-w-3xl px-6 pb-24 pt-32">
        <Link href="/" className="text-sm text-accent hover:underline">
          ← Back to home
        </Link>
        <h1 className="display mt-6 text-4xl">{title}</h1>
        <div className="mt-8 max-w-none space-y-6 text-sm leading-relaxed text-muted [&_h2]:mt-8 [&_h2]:font-heading [&_h2]:text-xl [&_h2]:font-medium [&_h2]:text-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4">
          {children}
        </div>
      </main>
      <Footer />
    </div>
  );
}
