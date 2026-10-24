import Image from "next/image";

export function LoadingMark({ className = "" }: { className?: string }) {
  return (
    <div role="status" aria-live="polite" className={`flex items-center justify-center ${className}`}>
      <Image
        src="/brand/m7-mark.png"
        alt=""
        width={96}
        height={77}
        priority
        className="mark-sway h-14 w-auto mix-blend-screen"
      />
      <span className="sr-only">Loading</span>
    </div>
  );
}
