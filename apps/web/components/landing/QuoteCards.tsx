const quotes = [
  {
    name: "Amina",
    role: "Product studio",
    where: "X · when the moment is right",
    quote:
      "I connected X and left posting on. There is no calendar. It sends while I am with a client, and only when the moment looks worth it.",
  },
  {
    name: "Jonah",
    role: "Tutorial channel",
    where: "YouTube · posts or waits",
    quote:
      "I described the channel once. It makes the video, or edits what I already filmed. I can let it post, or I look first.",
  },
  {
    name: "Priya",
    role: "Shop",
    where: "TikTok · you confirm",
    quote:
      "It makes the clip for the shop and suggests a time. Nothing goes out until I say yes. The next one is closer than the last.",
  },
];

function Card({ item, lean = false }: { item: (typeof quotes)[number]; lean?: boolean }) {
  return (
    <figure className={`glow-card flex h-full flex-col p-5 text-left ${lean ? "lean-left" : ""}`}>
      <div className="flex items-center gap-3">
        <figcaption className="min-w-0 flex-1">
          <p className="text-sm font-medium leading-tight">{item.name}</p>
          <p className="mt-0.5 text-sm text-muted">{item.role}</p>
        </figcaption>
      </div>
      <blockquote className="mt-4 flex-1 text-[15px] leading-relaxed">{item.quote}</blockquote>
      <p className="mt-4 border-t border-[#243246] pt-3 text-xs text-muted">{item.where}</p>
    </figure>
  );
}

export function QuoteCards({ still = false }: { still?: boolean }) {
  const loop = [...quotes, ...quotes];

  return (
    <section className="reveal py-10" aria-label={still ? "On the desk" : "What people say"}>
      <div className="mx-auto max-w-6xl px-6">
        <h2 className="display motion-copy text-center text-4xl leading-tight sm:text-5xl">
          {still ? "On the desk" : "Step up the accounts"}
          <span className="kicker"> you already run.</span>
        </h2>
      </div>
      {still ? (
        <div className="mx-auto mt-8 grid max-w-6xl items-stretch gap-4 px-6 md:grid-cols-3">
          {quotes.map((item, index) => (
            <Card key={item.name} item={item} lean={index === 0} />
          ))}
        </div>
      ) : (
        <div className="quote-rail mt-8">
          <div className="quote-track px-6">
            {loop.map((item, index) => (
              <Card key={`${item.name}-${index}`} item={item} />
            ))}
          </div>
        </div>
      )}
    </section>
  );
}
