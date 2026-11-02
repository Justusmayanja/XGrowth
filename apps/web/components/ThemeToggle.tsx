"use client";

import { useEffect, useState } from "react";
import { useTheme } from "next-themes";
import { Moon, Sun } from "lucide-react";

export function ThemeToggle({ className = "" }: { className?: string }) {
  const { resolvedTheme, setTheme } = useTheme();
  const [ready, setReady] = useState(false);

  useEffect(() => {
    setReady(true);
  }, []);

  const dark = resolvedTheme !== "light";

  return (
    <button
      type="button"
      className={`inline-flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-2 hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${className}`}
      aria-label={ready ? (dark ? "Switch to light" : "Switch to dark") : "Switch theme"}
      onClick={() => setTheme(dark ? "light" : "dark")}
    >
      {ready ? (dark ? <Sun className="size-4" /> : <Moon className="size-4" />) : <span className="size-4" aria-hidden />}
    </button>
  );
}
