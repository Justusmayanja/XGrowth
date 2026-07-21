import Link from "next/link";

export function BrandLogo({ className = "" }: { className?: string }) {
  return (
    <Link href="/" className={`flex items-center gap-2 font-bold ${className}`}>
      <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-sm font-black">
        X
      </span>
      <span>
        X<span className="text-accent-light">pulse</span>
      </span>
    </Link>
  );
}
