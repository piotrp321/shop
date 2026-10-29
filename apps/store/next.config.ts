import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Stops `next dev` from generating AGENTS.md. Its guidance lives in CLAUDE.md.
  agentRules: false,
  // Product photos come from the API variants (ProductPhoto); next/image must never optimize anything.
  images: {
    loader: "custom",
    loaderFile: "./src/images/forbidden-loader.ts",
  },
};

export default nextConfig;
