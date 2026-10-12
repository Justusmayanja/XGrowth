function DraftsBoard() {
  const rows = [
    { net: "X", line: "The offer, in one sentence the buyer can repeat." },
    { net: "TikTok", line: "The clip, with a time waiting on your yes." },
    { net: "YouTube", line: "The video, ready to post or waiting on you." },
  ];
  return (
    <>
      <p className="text-sm font-medium text-[#f4f7fb]">Drafts for today</p>
      <p className="mt-1 text-xs text-[#9aa8b8]">Three cuts from the note you already wrote.</p>
      <div className="mt-4 space-y-2">
        {rows.map((row) => (
          <div key={row.net} className="rounded-lg bg-[#171d28] px-3 py-2.5">
            <p className="text-[11px] text-[#7eb6ee]">{row.net}</p>
            <p className="mt-1 text-sm leading-snug text-[#e7eef6]">{row.line}</p>
          </div>
        ))}
      </div>
    </>
  );
}

function QueueBoard() {
  const rows = [
    { when: "Now", net: "X", line: "When the moment is strong" },
    { when: "Auto", net: "YouTube", line: "Video, set to post" },
    { when: "Fri", net: "TikTok", line: "Waiting on your yes" },
  ];
  return (
    <>
      <p className="text-sm font-medium text-[#f4f7fb]">Queue</p>
      <p className="mt-1 text-xs text-[#9aa8b8]">Leaves only where you allowed posting.</p>
      <div className="mt-4 space-y-2">
        {rows.map((row) => (
          <div key={row.when} className="flex items-center gap-3 rounded-lg bg-[#171d28] px-3 py-2.5">
            <span className="w-12 text-xs text-[#9aa8b8]">{row.when}</span>
            <span className="text-sm text-[#e7eef6]">
              {row.net}
              <span className="text-[#9aa8b8]"> · {row.line}</span>
            </span>
          </div>
        ))}
      </div>
    </>
  );
}

function ResultsBoard() {
  const stats = [
    { label: "Sent", value: "18" },
    { label: "Held", value: "4" },
    { label: "Replies", value: "6" },
  ];
  return (
    <>
      <p className="text-sm font-medium text-[#f4f7fb]">Results</p>
      <p className="mt-1 text-xs text-[#9aa8b8]">A sample day. Not a promise about yours.</p>
      <div className="mt-4 grid grid-cols-3 gap-2">
        {stats.map((stat) => (
          <div key={stat.label} className="rounded-lg bg-[#171d28] px-2 py-3 text-center">
            <p className="text-xl text-[#f4f7fb]">{stat.value}</p>
            <p className="mt-1 text-[11px] text-[#9aa8b8]">{stat.label}</p>
          </div>
        ))}
      </div>
    </>
  );
}

const boards = {
  drafts: DraftsBoard,
  queue: QueueBoard,
  results: ResultsBoard,
};

export function ProductVisual({ kind }: { kind: keyof typeof boards }) {
  const Board = boards[kind];
  return (
    <div className="fade-visual">
      <div className="fade-visual-board">
        <Board />
      </div>
    </div>
  );
}

export function shotKind(src: string): keyof typeof boards {
  if (src.includes("wire") || src.includes("foil") || src.includes("engraved")) return "queue";
  if (src.includes("mesh") || src.includes("holo")) return "results";
  return "drafts";
}
