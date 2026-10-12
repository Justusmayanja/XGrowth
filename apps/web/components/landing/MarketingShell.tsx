import { Footer } from "./Footer";
import { MotionCopy } from "./MotionCopy";
import { Navbar } from "./Navbar";
import { ScrollField } from "./ScrollField";
import { SectionDrop } from "./SectionDrop";

export function MarketingShell({
  kicker,
  title,
  lede,
  children,
}: {
  kicker: string;
  title: string;
  lede: string;
  children: React.ReactNode;
}) {
  return (
    <div className="landing-canvas min-h-screen">
      <ScrollField />
      <SectionDrop />
      <MotionCopy />
      <Navbar />
      <main className="mx-auto max-w-6xl px-6 pb-24 pt-32">
        <p className="kicker motion-copy text-base text-muted">{kicker}</p>
        <h1 className="display motion-copy mt-3 text-4xl leading-tight text-foreground sm:text-5xl">
          {title}
        </h1>
        <p className="motion-copy mt-5 text-base leading-relaxed text-muted sm:text-lg">{lede}</p>
        <div className="mt-14 space-y-14">{children}</div>
      </main>
      <Footer />
    </div>
  );
}
