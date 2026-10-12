import { CTA } from "./CTA";
import { FaqList } from "./FaqList";
import { Features } from "./Features";
import { Footer } from "./Footer";
import { Hero } from "./Hero";
import { HowItWorks } from "./HowItWorks";
import { ModesSection } from "./ModesSection";
import { MotionCopy } from "./MotionCopy";
import { Navbar } from "./Navbar";
import { Pricing } from "./Pricing";
import { ProblemSection } from "./ProblemSection";
import { ScrollField } from "./ScrollField";
import { ScrollFocus } from "./ScrollFocus";
import { SectionDrop } from "./SectionDrop";

export function LandingPage() {
  return (
    <div className="landing-canvas min-h-screen">
      <ScrollField />
      <ScrollFocus />
      <SectionDrop />
      <MotionCopy />
      <Navbar />
      <main>
        <Hero />
        <ProblemSection />
        <Features />
        <HowItWorks />
        <ModesSection />
        <Pricing />
        <section id="faq" className="py-16">
          <div className="mx-auto max-w-3xl px-6">
            <h2 className="landing-section-title motion-copy motion-live text-center text-4xl">
              Questions, before you connect.
            </h2>
            <div className="mt-8">
              <FaqList />
            </div>
          </div>
        </section>
        <CTA />
      </main>
      <Footer />
    </div>
  );
}
