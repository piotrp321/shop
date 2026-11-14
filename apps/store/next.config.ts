import { createRequire } from "node:module";
import type { SentryBuildOptions } from "@sentry/nextjs/config";
import type { NextConfig } from "next";

/**
 * Security headers of every response of the store (spec: docs/features/hardening/spec.md, D10). There is no
 * `script-src`: a nonce makes every page dynamic, which would switch ISR off. HSTS is set by the ingress, not here.
 */
const SECURITY_HEADERS = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  { key: "Content-Security-Policy", value: "frame-ancestors 'none'; object-src 'none'; base-uri 'self'" },
];

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
  async headers() {
    return [{ source: "/:path*", headers: SECURITY_HEADERS }];
  },
  // Product photos come from the API variants (ProductPhoto); next/image must never optimize anything.
  images: {
    loader: "custom",
    loaderFile: "./src/images/forbidden-loader.ts",
  },
};

/**
 * Options of the upload of source maps (spec: docs/features/sentry/spec.md, P3, D10), or null without it. The deploy
 * workflow builds the image with SENTRY_UPLOAD=true only when it has the Sentry token (the Dockerfile reads it from a build
 * secret into SENTRY_AUTH_TOKEN for this one build). A flag that is on without the token, the organisation or the
 * project is an error, not an image without maps; a failed upload stops the build too.
 */
export function sentryBuildOptions(env: Record<string, string | undefined>): SentryBuildOptions | null {
  const flag = env.SENTRY_UPLOAD ?? "";
  if (flag !== "" && flag !== "true" && flag !== "false") {
    throw new Error(`SENTRY_UPLOAD must be "true" or "false" (got "${flag}").`);
  }
  if (flag !== "true") return null;
  for (const key of ["SENTRY_AUTH_TOKEN", "SENTRY_ORG", "SENTRY_PROJECT"]) {
    if (!env[key]) {
      throw new Error(`SENTRY_UPLOAD is true, so ${key} is required (source maps are not built silently without an upload).`);
    }
  }
  return {
    authToken: env.SENTRY_AUTH_TOKEN,
    org: env.SENTRY_ORG,
    project: env.SENTRY_PROJECT,
    // No data about the build goes to Sentry (the plugin sends its own errors and timings by default).
    telemetry: false,
    // The release comes from the runtime configuration; maps are matched by debug ID, so no release is created.
    release: { create: false, finalize: false, setCommits: false, deploy: false },
    // The plugin deletes what it uploaded from .next/static; the Dockerfile removes every other .map after the build.
    sourcemaps: { deleteSourcemapsAfterUpload: true, filesToDeleteAfterUpload: [".next/**/*.map"] },
    // Nothing but the maps: no wrapping of routes and server functions, no loaders at build time, no tunnel.
    buildTimeInstrumentation: false,
    routeManifestInjection: false,
    tunnelRoute: false,
    webpack: { autoInstrumentServerFunctions: false, autoInstrumentMiddleware: false, autoInstrumentAppDirectory: false },
    // The plugin's own default is also to throw; explicit, because a skipped upload must never pass silently.
    errorHandler: (error) => {
      throw error;
    },
  };
}

type WithSentryConfig = (config: NextConfig, options: SentryBuildOptions) => NextConfig;

/**
 * `config` with the three settings that build the maps with debug IDs and upload them after the compilation, when the
 * upload is on; the very same `config` otherwise. `withSentryConfig` also adds trace meta tags to the HTML, external
 * packages, loaders and variables that change how the store builds and renders, and it changes the config it gets, so it
 * runs on a copy and only those three settings are taken from its result. It is loaded only for the upload build: it pulls
 * in the Node.js SDK, which every process that reads this file (each build worker, `next dev`) would load too.
 */
export function withSourceMaps(config: NextConfig, env: Record<string, string | undefined>): NextConfig {
  const options = sentryBuildOptions(env);
  if (options === null) return config;
  const { withSentryConfig } = createRequire(__filename)("@sentry/nextjs/config") as { withSentryConfig: WithSentryConfig };
  const copy: NextConfig = {
    ...config,
    experimental: { ...config.experimental },
    compiler: { ...config.compiler },
    turbopack: { ...config.turbopack },
    env: { ...config.env },
  };
  const wrapped = withSentryConfig(copy, options);
  const upload = wrapped.compiler?.runAfterProductionCompile;
  if (wrapped.productionBrowserSourceMaps !== true || wrapped.turbopack?.debugIds !== true || typeof upload !== "function") {
    throw new Error("SENTRY_UPLOAD needs a Turbopack build: the source maps would not be built and uploaded otherwise.");
  }
  return {
    ...config,
    productionBrowserSourceMaps: true,
    turbopack: { ...config.turbopack, debugIds: true },
    compiler: { ...config.compiler, runAfterProductionCompile: upload },
  };
}

export default withSourceMaps(nextConfig, process.env);
