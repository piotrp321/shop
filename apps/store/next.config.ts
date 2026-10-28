import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Stops `next dev` from generating AGENTS.md. Its guidance lives in CLAUDE.md.
  agentRules: false,
};

export default nextConfig;
