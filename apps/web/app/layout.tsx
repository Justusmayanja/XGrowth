import type { Metadata } from "next";
import "./globals.css";
import { Geist } from "next/font/google";
import { cn } from "@/lib/utils";
import { AppProviders } from "@/components/AppProviders";

const geist = Geist({ subsets: ["latin"], variable: "--font-sans" });

export const metadata: Metadata = {
  title: "Xpulse — Grow on X when the moment is right",
  description:
    "Xpulse watches trends in your niche, scores opportunities, and helps you post on X at the right time — not on a fixed schedule.",
  keywords: ["X growth", "Twitter growth", "social media tool", "Xpulse", "creator growth"],
  openGraph: {
    title: "Xpulse — Grow on X when the moment is right",
    description: "Stop scheduling. Start growing when your niche is listening.",
    type: "website",
  },
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={cn("dark font-sans", geist.variable)}>
      <body>
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
