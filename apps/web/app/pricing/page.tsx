import type { Metadata } from "next";
import { MarketingShell } from "@/components/landing/MarketingShell";
import { Pricing } from "@/components/landing/Pricing";

export const metadata: Metadata = {
  title: "Pricing | Mackie17",
  description:
    "Choose X, TikTok, YouTube, or any mix. Each platform has Starter, Pro, and Agency. Extra platforms are discounted.",
};

export default function PricingPage() {
  return (
    <MarketingShell
      kicker="Plans"
      title="Pay for the platforms you actually use."
      lede="Choose X, TikTok, YouTube, or any mix. Each one has Starter, Pro, and Agency, and those tiers can differ. The most expensive line stays full price. Every other platform is 15% off."
    >
      <Pricing showIntro={false} />
    </MarketingShell>
  );
}
