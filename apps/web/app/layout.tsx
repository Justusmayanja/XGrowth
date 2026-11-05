import type { Metadata, Viewport } from "next";
import "./globals.css";
import { display, heading, inter, mono, sans } from "./fonts";
import { cn } from "@/lib/utils";
import { AppProviders } from "@/components/AppProviders";

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
      "Mackie17 can make the YouTube video and post it, or wait for you. TikTok waits for your yes. Mackie17 posts on X when the moment is right.",
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
      suppressHydrationWarning
    >
      <head>
        <script
          dangerouslySetInnerHTML={{
            __html: `try{if(localStorage.getItem("mackie17-theme")==="light")document.documentElement.classList.remove("dark")}catch(e){}`,
          }}
        />
      </head>
      <body className="min-h-screen bg-background text-foreground antialiased">
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
