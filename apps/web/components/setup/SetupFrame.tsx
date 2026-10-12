import type { ReactNode } from "react";
import { BrandLogo } from "@/components/BrandLogo";

export function SetupFrame({
  step,
  total,
  title,
  lede,
  aside,
  asideTitle = "So far",
  children,
  footer,
}: {
  step: number;
  total: number;
  title: string;
  lede: string;
  aside: ReactNode;
  asideTitle?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="setup">
      <div className="setup-shell">
        <div className="flex items-center justify-between gap-4">
          <BrandLogo className="text-base" />
          <p className="text-sm text-[#a1a1aa]">
            {step} of {total}
          </p>
        </div>
        <div className="setup-progress" aria-hidden="true">
          {Array.from({ length: total }, (_, index) => (
            <span key={index} className={index < step ? "is-on" : ""} />
          ))}
        </div>
        <div className="setup-grid">
          <div>
            <h1 className="setup-title">{title}</h1>
            <p className="setup-lede">{lede}</p>
            {children}
            {footer ? <div className="mt-6 text-sm text-[#a1a1aa]">{footer}</div> : null}
          </div>
          <aside className="setup-aside">
            <h2>{asideTitle}</h2>
            {aside}
          </aside>
        </div>
      </div>
    </div>
  );
}
