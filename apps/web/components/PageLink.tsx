"use client";

import Link from "next/link";
import type { ComponentProps, MouseEvent } from "react";
import { openPage } from "@/lib/nav-recovery";

function hrefOf(href: ComponentProps<typeof Link>["href"]): string {
  if (typeof href === "string") return href;
  const path = href.pathname ?? "";
  const search =
    href.search ??
    (href.query ? `?${new URLSearchParams(href.query as Record<string, string>).toString()}` : "");
  const hash = href.hash ?? "";
  return `${path}${search}${hash}`;
}

export function PageLink({ href, onClick, prefetch = false, ...props }: ComponentProps<typeof Link>) {
  return (
    <Link
      href={href}
      prefetch={prefetch}
      onClick={(event: MouseEvent<HTMLAnchorElement>) => {
        onClick?.(event);
        if (event.defaultPrevented) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        openPage(hrefOf(href));
      }}
      {...props}
    />
  );
}
