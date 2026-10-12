"use client";

import { useEffect, useMemo, useRef } from "react";

export function ScrollLetters({ text, className }: { text: string; className?: string }) {
  const ref = useRef<HTMLParagraphElement>(null);
  const chars = useMemo(() => Array.from(text), [text]);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const spans = [...el.querySelectorAll<HTMLElement>("[data-ch]")];
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced) {
      spans.forEach((span) => {
        span.style.opacity = "1";
      });
      return;
    }

    const update = () => {
      const box = el.getBoundingClientRect();
      const start = window.innerHeight * 0.8;
      const end = window.innerHeight * 0.3;
      const progress = Math.min(1, Math.max(0, (start - box.top) / (start - end + box.height)));
      const count = spans.length;
      const total = 0.5 + Math.max(0, count - 1) * 0.02;
      spans.forEach((span, index) => {
        const local = (progress * total - index * 0.02) / 0.5;
        const amount = Math.min(1, Math.max(0, local));
        span.style.opacity = String(0.5 + 0.5 * amount);
      });
    };

    update();
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    return () => {
      window.removeEventListener("scroll", update);
      window.removeEventListener("resize", update);
    };
  }, [text]);

  return (
    <p ref={ref} className={className}>
      {chars.map((char, index) => (
        <span key={`${char}-${index}`} data-ch="">
          {char === " " ? "\u00A0" : char}
        </span>
      ))}
    </p>
  );
}
