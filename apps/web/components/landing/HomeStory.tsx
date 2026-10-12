import Link from "next/link";
import { SocialName } from "@/components/SocialLogo";
import { ProductVisual } from "./ProductVisual";
import { ScrollLetters } from "./ScrollLetters";
import { StepSwitch } from "./StepSwitch";

const columns = [
  {
    title: "Compose",
    items: [
      "A rough note comes back as three cuts",
      "X post, thread, poll, or quote",
      "TikTok writes the piece and makes the clip",
      "YouTube writes the piece and makes the video, or edits what you filmed",
      "The voice is the one you wrote for that account",
    ],
  },
  {
    title: "The queue",
    items: [
      "Suggest only and semi-auto wait for you",
      "X posts when the moment is worth it, not on a schedule",
      "YouTube can post on its own, or wait for your yes",
      "TikTok offers a time, then waits for you",
      "Posts, replies, and follows stay inside a daily cap",
      "Pause from Settings without disconnecting",
    ],
  },
  {
    title: "The desk",
    items: [
      "Topics watches the niche you named",
      "Drafts is what you can still edit",
      "Replies hold when the day’s cap is close",
      "Results is the log of what left",
      "It does not send DMs",
      "It does not retweet you while you sleep",
    ],
  },
  {
    title: "The accounts",
    items: [
      "Connect X now",
      "Describe YouTube and TikTok in the same sitting",
      "One description per account",
      "A quiet day stays quiet",
      "Bring your own YouTube footage if you already filmed it",
      "TikTok does not post until you confirm",
    ],
  },
];

export function HomeStory() {
  return (
    <>
      <section className="mx-auto max-w-3xl px-6 py-24 text-center">
        <ScrollLetters
          className="text-lg leading-relaxed text-[#f7f8f8]"
          text="Spending the evening on a YouTube video, then a TikTok, then an X post for the same idea?"
        />
        <p className="th-serif mt-8 text-5xl text-[#f7f8f8]">Stop.</p>
        <ScrollLetters
          className="mt-8 text-lg leading-relaxed text-[#f7f8f8]"
          text="Put the minutes into the business. Connect the accounts. Describe them once."
        />
        <ScrollLetters
          className="mt-4 text-base leading-relaxed text-[#f7f8f8]"
          text="YouTube can make the video and post it, or wait for you. TikTok makes the clip, offers a time, and waits. X posts when the moment is strong enough, with no schedule."
        />
        <p className="th-serif mt-8 text-3xl text-[#f7f8f8]">Less rewriting. More of the actual work.</p>
      </section>

      <StepSwitch />

      <section className="reveal mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:grid-cols-2">
        <div>
          <h2 className="landing-section-title motion-copy">
            Draft a week of pieces in one sitting.
          </h2>
          <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
            You still need a point of view. What you do not need is a fresh blank page for every
            network. Topics follows the niche you named. Compose takes the rough note and returns
            it in that account&apos;s voice.
          </p>
          <ul className="mt-8 space-y-5 text-sm leading-relaxed text-muted">
            <li>
              <p className="font-medium text-foreground">Topics</p>
              <p className="motion-copy mt-1">
                It watches what that niche is still paying attention to, and it holds the draft if
                the moment has passed.
              </p>
            </li>
            <li>
              <p className="font-medium text-foreground">Your own words</p>
              <p className="motion-copy mt-1">
                Bring footage you already filmed, or a note you would not post yet. It shapes
                that. The point stays yours.
              </p>
            </li>
            <li>
              <p className="font-medium text-foreground">Three cuts, not one post</p>
              <p className="motion-copy mt-1">
                A YouTube video, made or edited from what you filmed. A TikTok clip, with a time
                waiting on your yes. An X post that leaves when the moment looks right.
              </p>
            </li>
          </ul>
        </div>
        <ProductVisual kind="drafts" />
      </section>

      <section className="reveal mx-auto max-w-6xl px-6 py-8">
        <h2 className="landing-section-title motion-copy max-w-[18ch]">
          Fill the queue, then get back to the work.
        </h2>
        <div className="mt-10 grid items-center gap-10 md:grid-cols-2">
          <ol className="grid gap-4">
            <li className="glow-card lean-left p-5">
              <p className="display text-3xl text-[#9fd0ff]">1</p>
              <h3 className="mt-2 font-medium">Connect and describe</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                Niche, who it is for, and how it should sound. Do it per account, so X and the
                YouTube channel do not share a sentence.
              </p>
            </li>
            <li className="glow-card p-5">
              <p className="display text-3xl text-[#9fd0ff]">2</p>
              <h3 className="mt-2 font-medium">Add your own twist</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                Change a line, or bring a note you already wrote. The draft sits in Drafts until
                you are happy with it.
              </p>
            </li>
            <li className="glow-card p-5">
              <p className="display text-3xl text-[#9fd0ff]">3</p>
              <h3 className="mt-2 font-medium">Queue what you allowed</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                YouTube posts on its own when you set that, or waits for your yes. TikTok offers a
                time and waits for you. X has no calendar. It posts when the moment is worth it.
              </p>
            </li>
          </ol>
          <ProductVisual kind="queue" />
        </div>
      </section>

      <section className="reveal mx-auto grid max-w-6xl items-center gap-12 px-6 py-16 md:grid-cols-2">
        <ProductVisual kind="results" />
        <div>
          <h2 className="landing-section-title motion-copy">
            The repeats, without the risky tricks.
          </h2>
          <p className="motion-copy mt-4 text-base leading-relaxed text-muted">
            You are not logging in at odd hours to press send. YouTube can post the finished video
            for you, or hold it. TikTok comes back with a time and waits. X does not sit on a
            schedule. It sends when the moment looks strong, then pays attention to what traveled.
          </p>
          <div className="mt-6 space-y-4">
            <article className="glow-card p-5">
              <h3 className="text-sm font-medium">Suggest only, or semi-auto</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                It drafts. You decide what goes live. Nothing publishes until you say so.
              </p>
            </article>
            <article className="glow-card p-5">
              <h3 className="text-sm font-medium">X, when the moment is right</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                Turn posting on. There is no schedule. It stays in your niche and sends when the
                moment looks strong enough to travel, then waits for the next one like it.
              </p>
            </article>
          </div>
        </div>
      </section>

      <section className="reveal mx-auto max-w-6xl px-6 py-8" aria-label="X, TikTok, and YouTube">
        <h2 className="landing-section-title motion-copy max-w-[16ch]">
          Three networks. Not one switch.
        </h2>
          <p className="motion-copy mt-4 max-w-2xl text-base leading-relaxed text-muted">
          Connect the accounts you already run and describe each one: the business, the brand, who
          it is for. What happens after that is different on each network.
        </p>
        <div className="mt-10 grid gap-4 md:grid-cols-3">
          <article className="glow-card p-5">
              <p className="text-sm text-muted">Your channel, or your footage</p>
              <h3 className="display mt-3 text-3xl">
                <SocialName brand="youtube" className="gap-3" logoClassName="h-7 w-7">YouTube</SocialName>
              </h3>
              <p className="mt-2 text-sm font-medium text-[#9fd0ff]">Posts on its own, or waits</p>
              <p className="mt-3 text-sm leading-relaxed text-muted">
                Connect the channel and describe who it is for. It looks for ideas that already
                feel ready to catch on, writes what should be said, and makes the video. Bring
                footage you already filmed and it edits that instead. Set it to post, or keep the
                last word until you confirm.
              </p>
          </article>
          <article className="glow-card lean-left p-5">
              <p className="text-sm text-muted">You confirm</p>
              <h3 className="display mt-3 text-3xl">
                <SocialName brand="tiktok" className="gap-3" logoClassName="h-7 w-7">TikTok</SocialName>
              </h3>
              <p className="mt-2 text-sm font-medium text-[#9fd0ff]">A time, then your yes</p>
              <p className="mt-3 text-sm leading-relaxed text-muted">
                Connect the account and describe the business or the brand. It looks for ideas
                that suit that world, writes what should be said, and makes the clip. It suggests
                a time and waits. You confirm, and only then does it post. What goes out makes
                the next one closer.
              </p>
          </article>
          <article className="glow-card p-5">
              <p className="text-sm text-muted">No timetable</p>
              <h3 className="display mt-3 text-3xl">
                <SocialName brand="x" className="gap-3" logoClassName="h-7 w-7">X</SocialName>
              </h3>
              <p className="mt-2 text-sm font-medium text-[#9fd0ff]">When the moment is worth it</p>
              <p className="mt-3 text-sm leading-relaxed text-muted">
                Connect the profile, describe the niche, and turn posting on. It stays in that
                world and sends when the moment looks strong enough to travel. It notices what
                landed, then waits for the next moment that feels the same. Nothing on X is put
                on a calendar.
              </p>
          </article>
        </div>
        <p className="mt-8 text-sm">
          <Link href="/channels" className="text-foreground underline underline-offset-4">
            How each account is handled
          </Link>
        </p>
      </section>

      <section className="reveal mx-auto max-w-6xl px-6 py-16">
        <h2 className="display motion-copy text-center text-4xl leading-tight sm:text-5xl">
          The desk, <span className="kicker">written out.</span>
        </h2>
        <div className="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
          {columns.map((column) => (
            <div key={column.title}>
              <h3 className="text-sm font-medium">{column.title}</h3>
              <ul className="mt-4 space-y-3 text-sm leading-relaxed text-muted">
                {column.items.map((item) => (
                  <li key={item} className="border-t border-[#243246] pt-3">
                    {item}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </section>
    </>
  );
}
