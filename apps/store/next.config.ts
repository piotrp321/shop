import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Only the production image build (STORE_STANDALONE=1 in the Dockerfile) emits the self-contained server that the
  // image runs with `node server.js`. `next start` (STORE_MODE=production in Compose) and `next dev` are unchanged.
  output: process.env.STORE_STANDALONE === "1" ? "standalone" : undefined,
  // Stops `next dev` from generating AGENTS.md. Its guidance lives in CLAUDE.md.
  agentRules: false,
  experimental: {
    // `.next` lives in the bind-mounted source tree, so the Turbopack dev cache outlives the container. A dev server
    // started on a cache written for other sources (e.g. a new stack after a checkout) sends HMR `restart` in a loop.
    turbopackFileSystemCacheForDev: false,
  },
  // Product photos come from the API variants (ProductPhoto); next/image must never optimize anything.
  images: {
    loader: "custom",
    loaderFile: "./src/images/forbidden-loader.ts",
  },
};

export default nextConfig;
