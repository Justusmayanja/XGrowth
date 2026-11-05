import localFont from "next/font/local";

export const sans = localFont({
  src: [
    { path: "./ibm-plex-sans-400.woff2", weight: "400", style: "normal" },
    { path: "./ibm-plex-sans-500.woff2", weight: "500", style: "normal" },
    { path: "./ibm-plex-sans-600.woff2", weight: "600", style: "normal" },
  ],
  variable: "--font-sans",
  display: "swap",
});

export const heading = localFont({
  src: [
    { path: "./ibm-plex-serif-400.woff2", weight: "400", style: "normal" },
    { path: "./ibm-plex-serif-400-italic.woff2", weight: "400", style: "italic" },
    { path: "./ibm-plex-serif-500.woff2", weight: "500", style: "normal" },
    { path: "./ibm-plex-serif-500-italic.woff2", weight: "500", style: "italic" },
  ],
  variable: "--font-heading",
  display: "swap",
});

export const mono = localFont({
  src: [
    { path: "./ibm-plex-mono-400.woff2", weight: "400", style: "normal" },
    { path: "./ibm-plex-mono-500.woff2", weight: "500", style: "normal" },
  ],
  variable: "--font-mono",
  display: "swap",
});

export const inter = localFont({
  src: [
    { path: "./inter-400.woff2", weight: "400", style: "normal" },
    { path: "./inter-500.woff2", weight: "500", style: "normal" },
    { path: "./inter-600.woff2", weight: "600", style: "normal" },
  ],
  variable: "--font-inter",
  display: "swap",
});

export const display = localFont({
  src: [
    { path: "./instrument-serif-400.woff2", weight: "400", style: "normal" },
    { path: "./instrument-serif-400-italic.woff2", weight: "400", style: "italic" },
  ],
  variable: "--font-display",
  display: "swap",
});
