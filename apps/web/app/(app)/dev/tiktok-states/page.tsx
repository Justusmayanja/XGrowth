import { notFound } from "next/navigation";
import { TikTokBody } from "@/components/dashboard/tiktok/TikTokBody";
import { tiktokStateFixtures } from "@/components/dashboard/tiktok/fixtures";

export default function TikTokStatesPage() {
  if (process.env.NODE_ENV === "production") notFound();

  return (
    <div className="space-y-12">
      <div>
        <h1 className="text-xl font-medium">TikTok states</h1>
        <p className="mt-1 text-sm text-muted-foreground">Dev-only fixtures. Delete this page when the desk review is done.</p>
      </div>
      {tiktokStateFixtures.map((fixture) => (
        <section key={fixture.name} className="space-y-4 border-t border-border pt-8">
          <h2 className="text-sm font-medium text-muted-foreground">{fixture.name}</h2>
          <TikTokBody tiktok={fixture.tiktok} review={fixture.review} />
        </section>
      ))}
    </div>
  );
}
