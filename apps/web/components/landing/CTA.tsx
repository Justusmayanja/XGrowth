import Link from "next/link";

export function CTA() {
  return (
    <section className="py-24">
      <div className="mx-auto max-w-6xl px-6">
        <div className="relative overflow-hidden rounded-3xl border border-accent/30 bg-gradient-to-br from-accent/20 via-surface to-violet-900/20 px-8 py-16 text-center sm:px-16">
          <div className="pointer-events-none absolute inset-0 bg-hero-gradient opacity-60" />
          <div className="relative">
            <h2 className="text-3xl font-bold sm:text-4xl lg:text-5xl">
              Ready to feel the pulse of X?
            </h2>
            <p className="mx-auto mt-4 max-w-xl text-lg text-muted">
              Create your account, answer a few quick questions, and let Xpulse start working for
              your niche — free to start, no credit card required.
            </p>
            <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
              <Link href="/register" className="btn btn-primary px-8 py-3.5 text-base">
                Start now
              </Link>
              <Link href="/login" className="btn btn-secondary px-8 py-3.5 text-base">
                Sign in
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
