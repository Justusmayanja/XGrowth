import { CTA } from "./CTA";
import { FAQ } from "./FAQ";
import { Features } from "./Features";
import { Footer } from "./Footer";
import { Hero } from "./Hero";
import { HowItWorks } from "./HowItWorks";
import { ModesSection } from "./ModesSection";
import { Navbar } from "./Navbar";
import { Pricing } from "./Pricing";
import { ProblemSection } from "./ProblemSection";
import { StatsBar } from "./StatsBar";
import { Testimonials } from "./Testimonials";

export function LandingPage() {
  return (
    <div className="min-h-screen">
      <Navbar />
      <main>
        <Hero />
        <StatsBar />
        <ProblemSection />
        <Features />
        <HowItWorks />
        <ModesSection />
        <Pricing />
        <Testimonials />
        <FAQ />
        <CTA />
      </main>
      <Footer />
    </div>
  );
}
