import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

export function DeskHeading({
  title,
  children,
}: {
  icon?: LucideIcon;
  title: string;
  children?: ReactNode;
}) {
  return (
    <div>
      <h1 className="text-xl font-medium tracking-tight">{title}</h1>
      {children ? <div className="mt-1 text-sm text-[#a1a1aa]">{children}</div> : null}
    </div>
  );
}
