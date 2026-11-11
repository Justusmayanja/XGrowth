import fs from "node:fs";
import path from "node:path";
import type { NextConfig } from "next";

// ElevenLabs onboard writes the key to the repo-root .env.local.
// Next only auto-loads env files in this app directory.
for (const envFile of [
  path.resolve(process.cwd(), ".env.local"),
  path.resolve(process.cwd(), "../../.env.local"),
]) {
  if (fs.existsSync(envFile)) {
    process.loadEnvFile(envFile);
    break;
  }
}

// OneDrive drops native file events, so the dev server forgets routes and the
// browser then fails to load the page chunk. Polling keeps the route list intact.
if (!process.env.WATCHPACK_POLLING) {
  process.env.WATCHPACK_POLLING = "1000";
}

const apiOrigin = process.env.API_PROXY_TARGET ?? "http://127.0.0.1:8000";

const nextConfig: NextConfig = {
  ...(process.env.NEXT_STANDALONE === "1"
    ? { output: "standalone" as const, images: { unoptimized: true } }
    : {}),
  reactStrictMode: true,
  onDemandEntries: {
    maxInactiveAge: 15 * 60 * 1000,
    pagesBufferLength: 12,
  },
  webpack: (config, { dev }) => {
    if (dev) {
      config.watchOptions = {
        ...(config.watchOptions ?? {}),
        poll: 1000,
        aggregateTimeout: 500,
      };
    }
    return config;
  },
  async headers() {
    return [
      {
        source: "/((?!_next/static|_next/image).*)",
        headers: [
          { key: "Cache-Control", value: "private, no-cache, no-store, must-revalidate" },
        ],
      },
    ];
  },
  async redirects() {
    return [
      {
        source: "/admin/users",
        destination: "/admin/customers",
        permanent: true,
      },
      {
        source: "/admin/users/:path*",
        destination: "/admin/customers",
        permanent: true,
      },
    ];
  },
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${apiOrigin}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
