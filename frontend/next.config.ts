import type { NextConfig } from "next";
import { loadEnvConfig } from "@next/env";

// Make .env / .env.local values available inside next.config (Next does not
// load them here automatically). No-op in production where no .env.local exists.
loadEnvConfig(process.cwd());

// Where the frontend proxies "/api/*" to. Defaults to the in-cluster nginx
// (production/docker); override with API_PROXY_DESTINATION for local dev, e.g.
// API_PROXY_DESTINATION=http://localhost:8000/api/:path*
const apiDestination =
  process.env.API_PROXY_DESTINATION || 'http://nginx/api/:path*';

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: apiDestination,
      },
    ];
  },
};

export default nextConfig;
