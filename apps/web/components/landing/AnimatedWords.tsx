"use client";

import { useEffect, useState } from "react";

const words = [
  "demand shows up",
  "the niche is loud",
  "buyers are listening",
  "the moment is right",
  "attention is cheap",
];

const longestPhrase = words.reduce((a, b) => (a.length >= b.length ? a : b));

export function AnimatedWords() {
  const [index, setIndex] = useState(0);
  const [phase, setPhase] = useState<"in" | "out">("in");

  useEffect(() => {
    const interval = setInterval(() => {
      setPhase("out");
      setTimeout(() => {
        setIndex((current) => (current + 1) % words.length);
        setPhase("in");
      }, 350);
    }, 2800);

    return () => clearInterval(interval);
  }, []);

  return (
    <span aria-live="polite" className="relative block w-full">
      <span aria-hidden="true" className="invisible landing-shimmer-text block">
        {longestPhrase}
      </span>
      <span
        key={`${index}-${phase}`}
        className={`landing-shimmer-text absolute inset-x-0 top-0 block w-full ${
          phase === "in" ? "animate-word-in" : "animate-word-out"
        }`}
      >
        {words[index]}
      </span>
    </span>
  );
}
