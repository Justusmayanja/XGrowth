import type { NextConfig } from "next";

// OneDrive drops native file events, so the dev server forgets routes and the
// browser then fails to load the page chunk. Polling keeps the route list intact.
if (!process.env.WATCHPACK_POLLING) {
  process.env.WATCHPACK_POLLING = "1000";
}

const apiOrigin = process.env.API_PROXY_TARGET ?? "http://127.0.0.1:8000";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  onDemandEntries: {
    maxInactiveAge: 15 * 60 * 1000,
    pagesBufferLength: 12,
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
