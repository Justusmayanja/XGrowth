import type { Config } from "tailwindcss";

function alpha(variable: string) {
  return `color-mix(in oklch, var(${variable}) calc(<alpha-value> * 100%), transparent)`;
}

const config: Config = {
  darkMode: ["class"],
  content: [
    "./app/**/*.{js,ts,jsx,tsx,mdx}",
    "./components/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ["var(--font-sans)", "sans-serif"],
        heading: ["var(--font-heading)", "Georgia", "serif"],
        mono: ["var(--font-mono)", "ui-monospace", "monospace"],
      },
      colors: {
        background: "var(--background)",
        foreground: "var(--foreground)",
        card: {
          DEFAULT: "var(--card)",
          foreground: "var(--card-foreground)",
        },
        popover: {
          DEFAULT: "var(--popover)",
          foreground: "var(--popover-foreground)",
        },
        primary: {
          DEFAULT: alpha("--primary"),
          foreground: "var(--primary-foreground)",
        },
        secondary: {
          DEFAULT: alpha("--secondary"),
          foreground: "var(--secondary-foreground)",
        },
        muted: {
          DEFAULT: alpha("--muted"),
          foreground: "var(--muted-foreground)",
        },
        accent: {
          DEFAULT: alpha("--accent"),
          foreground: "var(--accent-foreground)",
        },
        destructive: {
          DEFAULT: alpha("--destructive"),
          foreground: "var(--destructive-foreground, var(--foreground))",
        },
        border: "var(--border)",
        input: "var(--input)",
        ring: "var(--ring)",
        sidebar: "var(--sidebar)",
        surface: "var(--surface)",
        "surface-2": "var(--surface-2)",
        "accent-light": "#f0b2a6",
      },
      borderRadius: {
        lg: "var(--radius)",
        md: "calc(var(--radius) - 2px)",
        sm: "calc(var(--radius) - 4px)",
      },
      animation: {
        "fade-up": "fade-up 0.7s ease-out forwards",
        "slide-in": "slide-in 0.5s ease-out forwards",
        "word-in": "word-in 0.45s ease-out forwards",
        "word-out": "word-out 0.35s ease-in forwards",
      },
      keyframes: {
        "fade-up": {
          "0%": { opacity: "0", transform: "translateY(24px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
        "slide-in": {
          "0%": { opacity: "0", transform: "translateX(-16px)" },
          "100%": { opacity: "1", transform: "translateX(0)" },
        },
        "word-in": {
          "0%": { opacity: "0", transform: "translateY(8px)" },
          "100%": { opacity: "1", transform: "translateY(0)" },
        },
        "word-out": {
          "0%": { opacity: "1", transform: "translateY(0)" },
          "100%": { opacity: "0", transform: "translateY(-8px)" },
        },
      },
    },
  },
  plugins: [],
};

export default config;
