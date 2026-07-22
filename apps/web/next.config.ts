import type { NextConfig } from "next";

const apiOrigin = process.env.API_PROXY_TARGET ?? "http://127.0.0.1:8000";

const nextConfig: NextConfig = {
  reactStrictMode: true,
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
