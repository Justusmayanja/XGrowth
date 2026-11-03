import { notFound } from "next/navigation";
import { TikTokBody } from "@/components/dashboard/tiktok/TikTokBody";
import { tiktokStateFixtures } from "@/components/dashboard/tiktok/fixtures";
import { DEFAULT_VIDEO, VideoSettingsCard } from "@/components/settings/VideoSettingsCard";

const validationVideo = {
  ...DEFAULT_VIDEO,
  brand: { ...DEFAULT_VIDEO.brand, primary: "blue", outro_text: "Thanks for watching the bowl." },
  context: {
    ...DEFAULT_VIDEO.context,
    mention_location: true,
    location_text: "A place name that is far too long for the sixty character limit on this field",
    show_contact: true,
    business_name: "Weeknight Kitchen",
    website: "not a site",
    phone: "call me",
  },
};

const businessOn = {
  ...DEFAULT_VIDEO,
  context: {
    mention_location: true,
    location_text: "Kampala",
    show_contact: true,
    business_name: "Weeknight Kitchen",
    website: "weeknight.example",
    phone: "+256 700 000 000",
  },
};

export default function TikTokStatesPage() {
  if (process.env.NODE_ENV === "production") notFound();

  return (
    <div className="space-y-12">
      <div>
        <h1 className="text-balance text-xl font-medium">TikTok states</h1>
        <p className="mt-1 text-sm text-muted-foreground">Dev-only fixtures. Delete this page when the desk review is done.</p>
      </div>
      {tiktokStateFixtures.map((fixture) => (
        <section key={fixture.name} className="space-y-4 border-t border-border pt-8">
          <h2 className="text-sm font-medium text-muted-foreground">{fixture.name}</h2>
          <TikTokBody tiktok={fixture.tiktok} review={fixture.review} />
        </section>
      ))}
      <section className="space-y-4 border-t border-border pt-8">
        <h2 className="text-sm font-medium text-muted-foreground">Settings, validation errors</h2>
        <VideoSettingsCard
          review={{
            video: validationVideo,
            section: "business",
            fieldErrors: {
              "context.location_text": "Location can be up to 60 characters.",
              "context.website": "Enter a website like example.com.",
              "context.phone": "Enter a phone number with digits, spaces, or + ( ) . -",
              "brand.primary": "Use a colour like #112233.",
            },
          }}
        />
      </section>
      <section className="space-y-4 border-t border-border pt-8">
        <h2 className="text-sm font-medium text-muted-foreground">Brand kit, failing contrast</h2>
        <VideoSettingsCard
          review={{
            video: { ...DEFAULT_VIDEO, brand: { ...DEFAULT_VIDEO.brand, use_colors: true, primary: "#eeeeee", font: "source_sans", outro_text: "Plate the bowl." } },
            logo: true,
            section: "brand",
            contrastPair: { background: "#eeeeee", foreground: "#f4f4f4" },
          }}
        />
      </section>
      <section className="space-y-4 border-t border-border pt-8">
        <h2 className="text-sm font-medium text-muted-foreground">Business details on</h2>
        <VideoSettingsCard review={{ video: businessOn, section: "business" }} />
      </section>
      <section className="space-y-4 border-t border-border pt-8">
        <h2 className="text-sm font-medium text-muted-foreground">Business details off</h2>
        <VideoSettingsCard review={{ video: DEFAULT_VIDEO, section: "business" }} />
      </section>
    </div>
  );
}
