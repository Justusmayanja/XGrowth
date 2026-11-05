import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

export function DeskHeading({
  title,
  children,
  actions,
}: {
  icon?: LucideIcon;
  title: string;
  children?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-xl font-medium tracking-tight">{title}</h1>
        {children ? <div className="mt-1 text-sm text-[#a1a1aa]">{children}</div> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}
