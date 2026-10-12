import Image from "next/image";
import Link from "next/link";

export function BrandLogo({ className = "" }: { className?: string }) {
  return (
    <Link
      href="/"
      className={`inline-flex items-center gap-2.5 font-medium tracking-tight text-foreground ${className}`}
    >
      <Image
        src="/brand/m7-mark.png"
        alt=""
        width={637}
        height={509}
        className="h-[1.7em] w-auto shrink-0"
      />
      <span>Mackie17</span>
    </Link>
  );
}
