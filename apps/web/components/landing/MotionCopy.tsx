"use client";

import { useEffect } from "react";

const KINDS = [
  { mode: "words", shape: "rise" },
  { mode: "line", shape: "fade" },
  { mode: "words", shape: "side" },
  { mode: "line", shape: "wipe" },
  { mode: "line", shape: "drop" },
] as const;

function splitWords(el: HTMLElement) {
  if (el.dataset.motionSplit === "1") return;
  if (el.children.length > 0) return;
  const text = el.textContent?.replace(/\s+/g, " ").trim() ?? "";
  if (!text) return;
  const words = text.split(" ");
  el.textContent = "";
  el.dataset.motionSplit = "1";
  words.forEach((word, index) => {
    const span = document.createElement("span");
    span.className = "word-bit";
    span.style.setProperty("--d", `${Math.min(index, 10) * 55}ms`);
    span.textContent = word;
    el.appendChild(span);
    if (index < words.length - 1) el.appendChild(document.createTextNode(" "));
  });
}

function isSectionHeading(el: HTMLElement) {
  if (el.tagName !== "H1" && el.tagName !== "H2") return false;
  if (el.closest("footer, header")) return false;
  const section = el.closest("section");
  if (!section) return false;
  const sections = Array.from(document.querySelectorAll("section"));
  if (sections[0] === section) return false;
  const first = section.querySelector("h1.motion-copy, h2.motion-copy");
  return first === el;
}

export function MotionCopy() {
  useEffect(() => {
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const seen = new WeakSet<Element>();
    let kindIndex = 0;
    let scrolled = false;

    const markScrolled = () => {
      scrolled = true;
      document.querySelectorAll<HTMLElement>(".motion-play:not(.is-on)").forEach((el) => {
        if (el.getBoundingClientRect().top < window.innerHeight * 0.78) {
          el.classList.add("is-on");
        }
      });
    };
    window.addEventListener("scroll", markScrolled, { passive: true });

    const arm = (el: HTMLElement) => {
      if (el.dataset.motionReady === "1") return;
      el.dataset.motionReady = "1";
      const kind = KINDS[kindIndex % KINDS.length];
      kindIndex += 1;
      el.classList.add(
        "motion-play",
        kind.mode === "words" ? "motion-words" : "motion-line",
        `shape-${kind.shape}`,
      );
      if (kind.mode === "words") splitWords(el);
    };

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const el = entry.target as HTMLElement;
          const top = entry.boundingClientRect.top;
          if (!entry.isIntersecting) {
            if (top < 0 && el.classList.contains("motion-play")) {
              el.classList.add("is-on");
              observer.unobserve(el);
              return;
            }
            if (top > window.innerHeight * 0.45) arm(el);
            return;
          }
          if (!scrolled || !el.classList.contains("motion-play")) {
            observer.unobserve(el);
            return;
          }
          el.classList.add("is-on");
          observer.unobserve(el);
        });
      },
      { threshold: 0.45 },
    );

    const watch = (node: Element) => {
      if (!(node instanceof HTMLElement) || !node.classList.contains("motion-copy")) return;
      if (seen.has(node) || reduced || !isSectionHeading(node)) return;
      seen.add(node);
      observer.observe(node);
    };

    const scan = (root: ParentNode) => {
      root.querySelectorAll(".motion-copy").forEach(watch);
    };

    scan(document);
    const changes = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return;
          watch(node);
          scan(node);
        });
      });
    });
    changes.observe(document.body, { childList: true, subtree: true });

    return () => {
      observer.disconnect();
      changes.disconnect();
      window.removeEventListener("scroll", markScrolled);
    };
  }, []);

  return null;
}
