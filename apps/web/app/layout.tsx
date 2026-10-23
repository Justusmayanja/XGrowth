import type { Metadata, Viewport } from "next";
import "./globals.css";
import { IBM_Plex_Mono, IBM_Plex_Sans, IBM_Plex_Serif, Instrument_Serif, Inter } from "next/font/google";
import { cn } from "@/lib/utils";
import { AppProviders } from "@/components/AppProviders";

const sans = IBM_Plex_Sans({
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  variable: "--font-sans",
});

const heading = IBM_Plex_Serif({
  subsets: ["latin"],
  weight: ["400", "500"],
  style: ["normal", "italic"],
  variable: "--font-heading",
});

const mono = IBM_Plex_Mono({
  subsets: ["latin"],
  weight: ["400", "500"],
  variable: "--font-mono",
});

const inter = Inter({
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  variable: "--font-inter",
});

const display = Instrument_Serif({
  subsets: ["latin"],
  weight: "400",
  style: ["normal", "italic"],
  variable: "--font-display",
});

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
};

export const metadata: Metadata = {
  title: "Mackie17 | Build the audience. Let the accounts earn.",
  description:
    "Connect YouTube, TikTok, or X, describe the account, and let Mackie17 find what fits, make the piece, and post the way that network should.",
  keywords: [
    "Mackie17",
    "YouTube",
    "TikTok",
    "X posting",
    "creator publishing",
  ],
  openGraph: {
    title: "Mackie17 | Build the audience. Let the accounts earn.",
    description:
      "YouTube can make the video and post it, or wait for you. TikTok waits for your yes. X posts when the moment is right.",
    type: "website",
  },
  icons: {
    icon: [
      { url: "/favicon.ico" },
      { url: "/brand/m7-mark.png", type: "image/png" },
    ],
    apple: "/brand/mackie17-mark.png",
  },
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html
      lang="en"
      className={cn(
        "dark font-sans",
        sans.variable,
        heading.variable,
        mono.variable,
        inter.variable,
        display.variable,
      )}
    >
      <body className="min-h-screen bg-background text-foreground antialiased">
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
