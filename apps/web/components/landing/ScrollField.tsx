"use client";

import { useEffect } from "react";

export function ScrollField() {
  useEffect(() => {
    const root = document.querySelector(".landing-canvas");
    if (!(root instanceof HTMLElement)) return;

    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced) {
      root.style.setProperty("--wash-left", "0.42");
      root.style.setProperty("--wash-right", "0.28");
      root.style.setProperty("--wash-center", "0");
      root.style.setProperty("--side-y", "32%");
      root.style.setProperty("--side-y-right", "68%");
      return;
    }

    let frame = 0;
    const paint = () => {
      const max = document.documentElement.scrollHeight - window.innerHeight;
      const t = max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
      const left = 0.12 + 0.22 * (0.5 + 0.5 * Math.sin(t * Math.PI * 2.15));
      const right = 0.08 + 0.18 * (0.5 + 0.5 * Math.sin(t * Math.PI * 2.15 + 2.4));
      const sideY = 12 + 76 * (0.5 + 0.5 * Math.sin(t * Math.PI * 1.35));
      const sideYRight = 88 - 70 * (0.5 + 0.5 * Math.sin(t * Math.PI * 1.35 + 0.8));
      const inCenter =
        (t > 0.22 && t < 0.42) || (t > 0.58 && t < 0.74) || (t > 0.86 && t < 0.96);
      let center = 0;
      if (inCenter) {
        const local =
          t < 0.5 ? (t - 0.22) / 0.2 : t < 0.8 ? (t - 0.58) / 0.16 : (t - 0.86) / 0.1;
        center = Math.sin(Math.min(1, Math.max(0, local)) * Math.PI) * 0.28;
      }
      root.style.setProperty("--wash-left", left.toFixed(3));
      root.style.setProperty("--wash-right", right.toFixed(3));
      root.style.setProperty("--wash-center", center.toFixed(3));
      root.style.setProperty("--side-y", `${sideY.toFixed(1)}%`);
      root.style.setProperty("--side-y-right", `${sideYRight.toFixed(1)}%`);
    };

    const cards = root.querySelectorAll<HTMLElement>(".landing-feature-card, .glow-card, .depth-card");
    cards.forEach((card) => {
      if (card.querySelector("a, button")) return;
      if (!card.hasAttribute("tabindex")) card.setAttribute("tabindex", "0");
    });

    const press = (card: HTMLElement) => {
      const group = card.parentElement;
      const wasForward = card.classList.contains("is-forward");
      group?.querySelectorAll<HTMLElement>(":scope > .is-forward").forEach((item) => {
        item.classList.remove("is-forward");
      });
      if (!wasForward) {
        card.classList.add("is-forward");
        if (card.hasAttribute("tabindex")) card.setAttribute("aria-pressed", "true");
      }
      group?.querySelectorAll<HTMLElement>(".landing-feature-card, .glow-card, .depth-card").forEach((item) => {
        if (item !== card || wasForward) {
          if (item.hasAttribute("tabindex")) item.setAttribute("aria-pressed", "false");
        }
      });
    };

    const onClick = (event: MouseEvent) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      if (target.closest("a, button")) return;
      const card = target.closest<HTMLElement>(".landing-feature-card, .glow-card, .depth-card");
      if (!card || !root.contains(card)) return;
      press(card);
    };

    const onKey = (event: KeyboardEvent) => {
      if (event.key !== "Enter" && event.key !== " ") return;
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (!target.matches(".landing-feature-card, .glow-card, .depth-card")) return;
      if (target.querySelector("a, button")) return;
      event.preventDefault();
      press(target);
    };

    const onScroll = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(paint);
    };

    paint();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    root.addEventListener("click", onClick);
    root.addEventListener("keydown", onKey);
    return () => {
      cancelAnimationFrame(frame);
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
      root.removeEventListener("click", onClick);
      root.removeEventListener("keydown", onKey);
    };
  }, []);

  return null;
}
