"use client";

import { useEffect } from "react";

const TARGETS = [
  "h1",
  "h2",
  "h3",
  "h4",
  "p",
  "li",
  "blockquote",
  "img",
  "figure",
  "table",
  "details",
  ".landing-feature-card",
  ".glow-card",
  ".depth-card",
  "[data-slot='card']",
  ".th-cta",
].join(",");

const DIM = 0.32;

function collect(main: HTMLElement): HTMLElement[] {
  const found = Array.from(main.querySelectorAll<HTMLElement>(TARGETS));
  const picked = new Set<HTMLElement>();
  return found.filter((el) => {
    let parent = el.parentElement;
    while (parent && parent !== main) {
      if (picked.has(parent)) return false;
      parent = parent.parentElement;
    }
    picked.add(el);
    return true;
  });
}

export function ScrollFocus() {
  useEffect(() => {
    const main = document.querySelector<HTMLElement>(".landing-canvas main");
    if (!main) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    let items = collect(main);
    let frame = 0;

    const paint = () => {
      const vh = window.innerHeight;
      const start = vh * 0.92;
      const end = vh * 0.5;
      for (const el of items) {
        const top = el.getBoundingClientRect().top;
        const raw = Math.min(1, Math.max(0, (start - top) / (start - end)));
        const eased = raw * raw * (3 - 2 * raw);
        el.style.setProperty("--focus", (DIM + (1 - DIM) * eased).toFixed(3));
      }
    };

    const onScroll = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(paint);
    };

    const refresh = () => {
      items.forEach((el) => el.classList.remove("scroll-focus"));
      items = collect(main);
      items.forEach((el) => el.classList.add("scroll-focus"));
      onScroll();
    };

    refresh();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);

    let pending = 0;
    const changes = new MutationObserver(() => {
      window.clearTimeout(pending);
      pending = window.setTimeout(refresh, 150);
    });
    changes.observe(main, { childList: true, subtree: true });

    return () => {
      cancelAnimationFrame(frame);
      window.clearTimeout(pending);
      changes.disconnect();
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
      items.forEach((el) => {
        el.classList.remove("scroll-focus");
        el.style.removeProperty("--focus");
      });
    };
  }, []);

  return null;
}
