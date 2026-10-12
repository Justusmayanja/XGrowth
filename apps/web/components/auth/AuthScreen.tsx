import Link from "next/link";
import { ReactNode } from "react";
import { BrandLogo } from "@/components/BrandLogo";

export function AuthScreen({
  kicker,
  title,
  lede,
  children,
  footer,
}: {
  kicker: string;
  title: string;
  lede: string;
  children: ReactNode;
  footer: ReactNode;
}) {
  return (
    <div className="landing-canvas auth-screen min-h-screen">
      <div className="flex min-h-screen items-center justify-center px-4 py-10">
        <div className="w-full max-w-md rounded-[0.9rem] border border-[#243246] bg-[#0c121c] p-6 shadow-[0_24px_48px_rgba(0,0,0,0.35)] sm:p-8">
          <BrandLogo className="mb-6 text-xl" />
          <p className="text-sm font-medium text-[#9ec0ea]">{kicker}</p>
          <h1 className="display mt-2 text-3xl text-[#e7eef6]">{title}</h1>
          <p className="mb-6 mt-2 text-sm leading-relaxed text-[#b7c6d8]">{lede}</p>
          {children}
          <div className="mt-6 text-center text-sm text-[#b7c6d8]">{footer}</div>
        </div>
      </div>
    </div>
  );
}

export function AuthError({ children }: { children: string }) {
  if (!children) return null;

  return (
    <div className="mb-4 rounded-[0.7rem] border border-[#c73d2a]/50 bg-[#c73d2a]/10 p-3 text-sm text-[#f3c7c0]" role="alert">
      {children}
    </div>
  );
}

export function AuthChoice({
  label,
  detail,
  onClick,
  disabled,
  children,
}: {
  label: string;
  detail: string;
  onClick: () => void;
  disabled?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="flex w-full items-center gap-3 rounded-[0.7rem] border border-[#243246] bg-[#141c2a] px-3 py-3 text-left transition hover:border-[#2c6cb8] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#2c6cb8] disabled:cursor-not-allowed disabled:opacity-45"
    >
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#0c121c] text-[#9ec0ea]">
        {children}
      </span>
      <span>
        <span className="block text-sm font-medium text-[#e7eef6]">{label}</span>
        <span className="block text-sm text-[#b7c6d8]">{detail}</span>
      </span>
    </button>
  );
}

export function GoogleMark() {
  return (
    <svg viewBox="0 0 48 48" className="h-[18px] w-[18px]" aria-hidden="true">
      <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.5z" />
      <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 12 24 12c3.1 0 5.8 1.2 8 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 16.3 4 9.6 8.3 6.3 14.7z" />
      <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.2l-6.3-5.3C29.2 35.1 26.7 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
      <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1.1 3.2-3.5 5.7-6.7 7.2l6.3 5.3C37.4 38.3 44 33 44 24c0-1.3-.1-2.7-.4-3.5z" />
    </svg>
  );
}

export function XMark() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4 fill-[#e7eef6]" aria-hidden="true">
      <path d="M14.7 10.3 21.4 3h-1.6l-5.8 6.4L9.3 3H3.5l7 10.1L3.5 21h1.6l6.2-6.8 4.9 6.8h5.8l-7.3-10.7Zm-2.2 2.4-.7-1-5.7-7.8h2.4l4.6 6.3.7 1 6 8.2h-2.4l-4.9-6.7Z" />
    </svg>
  );
}

export function MailMark() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
      <rect x="3.5" y="5.5" width="17" height="13" rx="2" />
      <path d="m4 7 8 6 8-6" />
    </svg>
  );
}

export function PenMark() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
      <path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z" />
      <path d="m13.5 6.5 3 3" />
    </svg>
  );
}

export function AuthLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="text-[#9ec0ea] underline underline-offset-4">
      {children}
    </Link>
  );
}
