"use client";

import { useEffect } from "react";

export function SectionDrop() {
  useEffect(() => {
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let frame = 0;

    const place = (el: HTMLElement) => {
      if (reduced) {
        el.classList.add("is-in");
        return;
      }
      const box = el.getBoundingClientRect();
      const visible = box.bottom > 48 && box.top < window.innerHeight - 48;
      if (visible) {
        if (!el.classList.contains("is-in")) {
          const fromAbove = box.top < window.innerHeight * 0.28;
          el.classList.toggle("from-above", fromAbove);
          el.classList.toggle("from-below", !fromAbove);
          el.classList.add("is-in");
        }
        return;
      }
      el.classList.remove("is-in");
    };

    const scan = () => {
      document.querySelectorAll<HTMLElement>(".reveal").forEach(place);
    };

    const onScroll = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(scan);
    };

    scan();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);

    const changes = new MutationObserver(onScroll);
    changes.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["class"],
    });

    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
      changes.disconnect();
    };
  }, []);

  return null;
}
