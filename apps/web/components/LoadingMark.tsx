import Image from "next/image";

const OUTER = "M 160 16 L 468 8 C 524 10 536 42 528 80 C 520 112 470 150 430 190 L 312 470";
const INNER = "M 214 64 C 360 52 478 68 502 104 C 456 142 412 168 392 196 L 214 468";

function Lights({ path, count, duration }: { path: string; count: number; duration: number }) {
  return Array.from({ length: count }, (_, index) => (
    <g key={`${duration}-${index}`}>
      <circle r="36" fill="#3d8dff" opacity="0.45" />
      <circle r="18" fill="#f7fbff" />
      <animateMotion
        dur={`${duration}s`}
        begin={`${(index * duration) / count}s`}
        repeatCount="indefinite"
        path={path}
      />
    </g>
  ));
}

export function LoadingMark({ className = "" }: { className?: string }) {
  return (
    <div role="status" aria-live="polite" className={`flex items-center justify-center ${className}`}>
      <div className="relative h-[4.75rem] w-[5.25rem]">
        <Image
          src="/brand/m7-seven.png"
          alt=""
          width={540}
          height={487}
          priority
          className="h-full w-full object-contain"
        />
        <svg
          viewBox="0 0 540 487"
          className="seven-lights pointer-events-none absolute inset-0 h-full w-full"
          aria-hidden="true"
        >
          <Lights path={OUTER} count={5} duration={1.8} />
          <Lights path={INNER} count={4} duration={2.1} />
        </svg>
      </div>
      <span className="sr-only">Loading</span>
    </div>
  );
}
