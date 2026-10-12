import Link from "next/link";

const pages = [
  {
    href: "/features",
    label: "Features",
    note: "Compose, Topics, Drafts, the queue, Replies, Results, and the daily caps.",
  },
  {
    href: "/how-it-works",
    label: "How it works",
    note: "Connect the account and describe it. YouTube posts or waits. TikTok asks you. X sends when it is worth it.",
  },
  {
    href: "/channels",
    label: "Channels",
    note: "YouTube can make the video. TikTok waits for your yes. X has no schedule.",
  },
  {
    href: "/faq",
    label: "FAQ",
    note: "The four levels, the daily caps, and what to write when you describe an account.",
  },
];

export function PageIndex() {
  return (
    <section className="reveal py-8" aria-label="More on Mackie17">
      <div className="mx-auto grid max-w-6xl gap-8 px-6 sm:grid-cols-2">
        {pages.map((page) => (
          <Link
            key={page.href}
            href={page.href}
            className="group block border-t border-[#1c3048] pt-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-foreground"
          >
            <h2 className="text-sm font-medium group-hover:text-[#9fd0ff]">{page.label}</h2>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-muted">{page.note}</p>
          </Link>
        ))}
      </div>
    </section>
  );
}
