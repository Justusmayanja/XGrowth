import type { TikTokIdea } from "@/lib/tiktok";

export function IdeasList({ ideas, title = "What we're looking at" }: { ideas: TikTokIdea[]; title?: string }) {
  return (
    <section aria-label={title}>
      <h3 className="text-sm font-medium">{title}</h3>
      {ideas.length === 0 ? (
        <p className="mt-3 text-sm text-muted-foreground">Ideas for this account show up here.</p>
      ) : (
        <ul className="mt-3 divide-y divide-border">
          {ideas.map((idea) => (
            <li key={`${idea.kind}-${idea.topic}`} className="py-3 first:pt-0 last:pb-0">
              <p className="text-xs text-muted-foreground">{idea.kind_label}</p>
              <p className="mt-1 font-medium break-words">{idea.topic}</p>
              <p className="mt-1 text-sm break-words text-muted-foreground">{idea.angle}</p>
              <p className="mt-1 text-sm break-words">{idea.reason}</p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
