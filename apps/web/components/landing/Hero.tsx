"use client";

import Link from "next/link";
import { AnimatedWords } from "./AnimatedWords";

export function Hero() {
  return (
    <section className="relative overflow-x-hidden pt-32 pb-20 lg:pt-40 lg:pb-28">
      <div className="pointer-events-none absolute inset-0 bg-hero-gradient" />
      <div className="landing-grid pointer-events-none absolute inset-0 opacity-40" />

      <div className="relative mx-auto max-w-6xl px-6">
        <div className="mx-auto max-w-4xl text-center">
          <div className="mb-6 inline-flex animate-fade-up items-center gap-2 rounded-full border border-accent/30 bg-accent/10 px-4 py-1.5 text-sm text-accent-light">
            <span className="h-2 w-2 animate-pulse rounded-full bg-green-400" />
            Built for X growth, not another posting calendar
          </div>

          <h1
            className="animate-fade-up text-4xl font-bold tracking-tight sm:text-5xl lg:text-7xl"
            style={{ animationDelay: "0.1s", animationFillMode: "both" }}
          >
            <span className="block leading-[1.15]">Grow on X when</span>
            <span className="mt-2 block min-h-[1.2em] leading-[1.15] sm:mt-3">
              <AnimatedWords />
            </span>
          </h1>

          <p
            className="mx-auto mt-6 max-w-2xl animate-fade-up text-lg text-muted sm:text-xl"
            style={{ animationDelay: "0.2s", animationFillMode: "both" }}
          >
            Xpulse watches what matters in your niche, spots the right moment to post, and keeps your
            account active while you&apos;re offline, safely and on your terms.
          </p>

          <div
            className="mt-10 flex animate-fade-up flex-col items-center justify-center gap-4 sm:flex-row"
            style={{ animationDelay: "0.3s", animationFillMode: "both" }}
          >
            <Link href="/register" className="btn btn-primary px-8 py-3.5 text-base">
              Start now, it&apos;s free
            </Link>
            <a href="#how-it-works" className="btn btn-secondary px-8 py-3.5 text-base">
              See how it works
            </a>
          </div>

          <p className="mt-4 text-sm text-muted">
            No credit card · Set up in minutes · Works while you sleep
          </p>
        </div>

        <div className="relative mx-auto mt-16 max-w-5xl">
          <div className="absolute -left-8 top-8 hidden h-24 w-24 animate-pulse-glow rounded-full bg-accent/20 blur-2xl lg:block" />
          <div className="absolute -right-6 bottom-4 hidden h-32 w-32 animate-pulse-glow rounded-full bg-violet-500/20 blur-3xl lg:block" />

          <div className="landing-glass animate-float overflow-hidden rounded-2xl border border-white/10 shadow-2xl shadow-accent/10">
            <div className="flex items-center gap-2 border-b border-white/5 bg-surface-2/80 px-4 py-3">
              <span className="h-3 w-3 rounded-full bg-red-400/80" />
              <span className="h-3 w-3 rounded-full bg-yellow-400/80" />
              <span className="h-3 w-3 rounded-full bg-green-400/80" />
              <span className="ml-2 text-xs text-muted">Xpulse · Live activity</span>
            </div>

            <div className="grid gap-4 bg-gradient-to-br from-surface to-surface-2 p-6 sm:grid-cols-3 sm:p-8">
              <div className="animate-float-delayed rounded-xl border border-green-500/30 bg-green-500/10 p-4">
                <div className="text-xs font-semibold uppercase tracking-wide text-green-400">
                  Trend alert
                </div>
                <p className="mt-2 text-sm font-medium">#TransferNews rising in your niche</p>
                <div className="mt-3 text-2xl font-bold text-green-300">92/100</div>
              </div>

              <div className="rounded-xl border border-accent/30 bg-accent/10 p-4 sm:translate-y-4">
                <div className="text-xs font-semibold uppercase tracking-wide text-accent-light">
                  Draft ready
                </div>
                <p className="mt-2 text-sm leading-relaxed">
                  &quot;Bold take: this move changes everything for the season. Here&apos;s what fans
                  are missing…&quot;
                </p>
                <div className="mt-3 text-xs text-muted">Reach estimate: High · Tone: Passionate</div>
              </div>

              <div className="animate-float rounded-xl border border-violet-500/30 bg-violet-500/10 p-4 sm:-translate-y-2">
                <div className="text-xs font-semibold uppercase tracking-wide text-violet-300">
                  Account check
                </div>
                <p className="mt-2 text-sm">Limits OK · Natural timing · Ready to publish</p>
                <div className="mt-3 inline-flex rounded-full bg-green-500/20 px-3 py-1 text-xs text-green-300">
                  Cleared to post
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
