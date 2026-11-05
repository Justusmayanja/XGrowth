const NEXT_HREF_KEY = "mackie17-next-href";
const NEXT_HREF_AT = "mackie17-next-href-at";

export function destinationsMatch(href: string): boolean {
  if (typeof window === "undefined") return false;
  const url = new URL(href, window.location.href);
  if (url.origin !== window.location.origin) return false;
  if (url.pathname !== window.location.pathname) return false;
  if (url.search !== window.location.search) return false;
  if (url.hash && url.hash !== window.location.hash) return false;
  return true;
}

export function rememberNavHref(href: string) {
  try {
    sessionStorage.setItem(NEXT_HREF_KEY, href);
    sessionStorage.setItem(NEXT_HREF_AT, String(Date.now()));
  } catch {
    // Private mode can block sessionStorage.
  }
}

export function peekNavHref(): string | null {
  try {
    return sessionStorage.getItem(NEXT_HREF_KEY);
  } catch {
    return null;
  }
}

export function navHrefAge(): number {
  try {
    const at = Number(sessionStorage.getItem(NEXT_HREF_AT) || 0);
    return at ? Date.now() - at : 0;
  } catch {
    return 0;
  }
}

export function takeNavHref(): string | null {
  try {
    const href = sessionStorage.getItem(NEXT_HREF_KEY);
    sessionStorage.removeItem(NEXT_HREF_KEY);
    sessionStorage.removeItem(NEXT_HREF_AT);
    return href;
  } catch {
    return null;
  }
}

export function openPage(href: string) {
  rememberNavHref(href);
  if (typeof window === "undefined") return;
  if (destinationsMatch(href)) {
    takeNavHref();
    return;
  }
  window.location.assign(href);
}
