"use client";

import { useEffect, useRef, useState } from "react";

function overlapsViewport(node: HTMLElement) {
  const rect = node.getBoundingClientRect();
  const height = window.innerHeight || document.documentElement.clientHeight;
  return rect.bottom > 0 && rect.top < height;
}

export function useReveal() {
  const ref = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const node = ref.current;
    if (!node) return;

    let shown = false;
    const reveal = () => {
      if (shown) return;
      shown = true;
      setVisible(true);
      observer.disconnect();
      window.removeEventListener("scroll", check, true);
      window.removeEventListener("resize", check);
    };

    const check = () => {
      if (overlapsViewport(node)) reveal();
    };

    // Any visible pixel is enough. A ratio threshold never fires when the
    // section is taller than the phone screen, so the block stays invisible.
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) reveal();
      },
      { threshold: 0 }
    );

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      reveal();
      return;
    }

    observer.observe(node);
    check();
    window.addEventListener("scroll", check, { capture: true, passive: true });
    window.addEventListener("resize", check);

    return () => {
      observer.disconnect();
      window.removeEventListener("scroll", check, true);
      window.removeEventListener("resize", check);
    };
  }, []);

  return { ref, visible };
}
