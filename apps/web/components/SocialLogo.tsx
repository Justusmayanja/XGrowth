export type SocialBrand = "google" | "x" | "youtube" | "tiktok";

export function SocialLogo({ brand, className = "h-4 w-4" }: { brand: SocialBrand; className?: string }) {
  const common = { className: `shrink-0 ${className}`, "aria-hidden": true, focusable: false } as const;

  switch (brand) {
    case "google":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47a5.53 5.53 0 0 1-2.4 3.63v3.02h3.88c2.27-2.09 3.57-5.17 3.57-8.84Z" />
          <path fill="#34A853" d="M12 24c3.24 0 5.96-1.07 7.95-2.9l-3.88-3.02c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.26v3.11A12 12 0 0 0 12 24Z" />
          <path fill="#FBBC05" d="M5.27 14.27a7.2 7.2 0 0 1 0-4.54V6.62H1.26a12 12 0 0 0 0 10.76l4.01-3.11Z" />
          <path fill="#EA4335" d="M12 4.77c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.95 1.19 15.24 0 12 0A12 12 0 0 0 1.26 6.62l4.01 3.11C6.22 6.88 8.87 4.77 12 4.77Z" />
        </svg>
      );
    case "x":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path fill="currentColor" d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.67l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23Zm-1.16 17.52h1.83L7.08 4.13H5.12l11.96 15.64Z" />
        </svg>
      );
    case "youtube":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path fill="#FF0000" d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 5.81 3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-5.81Z" />
          <path fill="#fff" d="m9.55 15.57 6.27-3.57-6.27-3.57v7.14Z" />
        </svg>
      );
    case "tiktok":
      return (
        <svg viewBox="0 0 24 24" {...common}>
          <path fill="#25F4EE" d="M9.37 9.6v-.93a7.3 7.3 0 0 0-.98-.07 7.27 7.27 0 0 0-4.17 13.23 7.26 7.26 0 0 1 5.15-12.23Z" />
          <path fill="#25F4EE" d="M9.55 20.12a3.33 3.33 0 0 0 3.32-3.2V1.07h2.88a5.5 5.5 0 0 1-.09-1h-3.94v15.86a3.33 3.33 0 0 1-3.32 3.2 3.3 3.3 0 0 1-1.54-.38 3.33 3.33 0 0 0 2.7 1.37Zm11.57-13.67v-.88a5.44 5.44 0 0 1-3-.9 5.5 5.5 0 0 0 3 1.78Z" />
          <path fill="#FE2C55" d="M18.12 4.67a5.47 5.47 0 0 1-1.36-3.6h-1.05a5.52 5.52 0 0 0 2.41 3.6ZM8.39 12.47a3.33 3.33 0 0 0-1.54 6.28 3.32 3.32 0 0 1 3.66-5.24V9.47a7.3 7.3 0 0 0-.98-.07h-.17v3.07a3.3 3.3 0 0 0-.97-.15Z" />
          <path fill="#FE2C55" d="M21.12 6.45v3.04a9.41 9.41 0 0 1-5.5-1.77v8.02a7.27 7.27 0 0 1-11.4 5.98A7.26 7.26 0 0 0 16.8 16.92V8.9a9.41 9.41 0 0 0 5.5 1.77V6.72a5.5 5.5 0 0 1-1.18-.27Z" />
          <path fill="currentColor" d="M15.62 15.74V7.72a9.41 9.41 0 0 0 5.5 1.77V6.45a5.5 5.5 0 0 1-3-1.78 5.52 5.52 0 0 1-2.41-3.6h-2.84v15.86a3.33 3.33 0 0 1-6.02 1.81 3.33 3.33 0 0 1 1.54-6.28c.34 0 .66.05.97.15V9.54a7.26 7.26 0 0 0-5.15 12.23 7.27 7.27 0 0 0 11.41-6.03Z" />
        </svg>
      );
  }
}

export function SocialName({
  brand,
  children,
  className = "",
  logoClassName,
}: {
  brand: SocialBrand;
  children: React.ReactNode;
  className?: string;
  logoClassName?: string;
}) {
  return (
    <span className={`inline-flex items-center gap-2 ${className}`}>
      <SocialLogo brand={brand} className={logoClassName} />
      {children}
    </span>
  );
}
