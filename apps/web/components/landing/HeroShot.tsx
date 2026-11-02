import {
  BarChart3,
  FileText,
  Hash,
  ListOrdered,
  PenLine,
  Sun,
} from "lucide-react";

const rail = [
  { icon: Sun, label: "Today", active: true },
  { icon: PenLine, label: "Compose", active: false },
  { icon: FileText, label: "Drafts", active: false },
  { icon: ListOrdered, label: "Queue", active: false },
  { icon: Hash, label: "Topics", active: false },
  { icon: BarChart3, label: "Results", active: false },
];

const drafts = [
  {
    net: "YouTube",
    line: "The video, ready to post or waiting on you.",
  },
  {
    net: "TikTok",
    line: "The clip, with a time waiting on your yes.",
  },
  {
    net: "X",
    line: "Goes out when the moment looks strong.",
  },
];

const stats = [
  { label: "Followers", value: "12,480", sub: "+86 today" },
  { label: "Impressions", value: "86.2k", sub: "+12% this week" },
  { label: "Growth", value: "74", sub: "score" },
  { label: "Drafts", value: "6", sub: "ready to review" },
];

export function HeroShot() {
  return (
    <div className="th-hero-photo" aria-hidden="true">
      <div className="th-hero-screen">
        <div className="th-hero-rail">
          <span className="th-hero-mark">M</span>
          {rail.map((item) => {
            const Icon = item.icon;
            return (
              <span
                key={item.label}
                className={item.active ? "th-hero-rail-btn is-on" : "th-hero-rail-btn"}
              >
                <Icon strokeWidth={1.75} />
              </span>
            );
          })}
        </div>
        <div className="th-hero-main">
          <p className="th-hero-sample">Sample desk</p>
          <p className="th-hero-kicker">Today</p>
          <p className="th-hero-lead">What is ready across X, TikTok, and YouTube.</p>
          <div className="th-hero-stats">
            {stats.map((stat) => (
              <div key={stat.label}>
                <p className="th-hero-stat-label">{stat.label}</p>
                <p className="th-hero-stat-value">{stat.value}</p>
                <p className="th-hero-stat-sub">{stat.sub}</p>
              </div>
            ))}
          </div>
          <p className="th-hero-block-label">Drafts for today</p>
          <div className="th-hero-rows">
            {drafts.map((row) => (
              <div key={row.net} className="th-hero-row">
                <span>{row.net}</span>
                <p>{row.line}</p>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
