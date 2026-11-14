import { afterEach, describe, expect, it, vi } from "vitest";
import nextConfig, { sentryBuildOptions, withSourceMaps } from "../next.config";

// Spec: docs/features/sentry/spec.md (P3, D10). Source maps of the store are built and uploaded only by the production
// build of the deploy workflow (SENTRY_UPLOAD=true); everything else of the build stays as it is.
const UPLOAD = { SENTRY_UPLOAD: "true", SENTRY_AUTH_TOKEN: "token", SENTRY_ORG: "worf", SENTRY_PROJECT: "store" };

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("sentryBuildOptions", () => {
  it("is null without the flag, whatever else is set", () => {
    expect(sentryBuildOptions({})).toBeNull();
    expect(sentryBuildOptions({ SENTRY_UPLOAD: "" })).toBeNull();
    expect(sentryBuildOptions({ SENTRY_UPLOAD: "false", SENTRY_AUTH_TOKEN: "token", SENTRY_ORG: "worf" })).toBeNull();
  });

  it("rejects a flag that is neither true nor false instead of silently skipping the upload", () => {
    expect(() => sentryBuildOptions({ ...UPLOAD, SENTRY_UPLOAD: "1" })).toThrow("SENTRY_UPLOAD");
    expect(() => sentryBuildOptions({ ...UPLOAD, SENTRY_UPLOAD: "TRUE" })).toThrow("SENTRY_UPLOAD");
  });

  it.each(["SENTRY_AUTH_TOKEN", "SENTRY_ORG", "SENTRY_PROJECT"])("fails when the flag is on and %s is missing or empty", (key) => {
    expect(() => sentryBuildOptions({ ...UPLOAD, [key]: undefined })).toThrow(key);
    expect(() => sentryBuildOptions({ ...UPLOAD, [key]: "" })).toThrow(key);
  });

  it("uploads with the organisation, the project and the token", () => {
    expect(sentryBuildOptions(UPLOAD)).toMatchObject({ authToken: "token", org: "worf", project: "store" });
  });

  it("sends no telemetry of the plugin and creates no release", () => {
    expect(sentryBuildOptions(UPLOAD)).toMatchObject({
      telemetry: false,
      release: { create: false, finalize: false, setCommits: false, deploy: false },
    });
  });

  it("deletes the uploaded maps of .next/static after the upload, and nothing else", () => {
    // The hook runs before Next traces the routes and copies the standalone output, which may list server maps: deleting
    // them there (filesToDeleteAfterUpload) could break that copy. The Dockerfile removes the rest when the build is done.
    const sourcemaps = sentryBuildOptions(UPLOAD)?.sourcemaps;

    expect(sourcemaps).toEqual({ deleteSourcemapsAfterUpload: true });
    expect(sourcemaps).not.toHaveProperty("filesToDeleteAfterUpload");
  });

  it("turns off the instrumentation of the build and the tunnel", () => {
    expect(sentryBuildOptions(UPLOAD)).toMatchObject({
      buildTimeInstrumentation: false,
      routeManifestInjection: false,
      tunnelRoute: false,
      webpack: { autoInstrumentServerFunctions: false, autoInstrumentMiddleware: false, autoInstrumentAppDirectory: false },
    });
  });

  it("stops the build when the upload fails", () => {
    const { errorHandler } = sentryBuildOptions(UPLOAD) ?? {};

    expect(() => errorHandler?.(new Error("401 Unauthorized"))).toThrow("401 Unauthorized");
  });
});

describe("withSourceMaps", () => {
  const base = { output: "standalone" as const, headers: async () => [] };

  it("returns the very same config without the flag", () => {
    expect(withSourceMaps(base, {})).toBe(base);
    expect(withSourceMaps(base, { SENTRY_UPLOAD: "false" })).toBe(base);
  });

  it("is the default export unchanged in a test run without the flag", () => {
    expect(nextConfig.headers).toBeTypeOf("function");
    expect(nextConfig.productionBrowserSourceMaps).toBeUndefined();
    expect(nextConfig.compiler).toBeUndefined();
    expect(nextConfig.turbopack).toBeUndefined();
  });

  it("with the flag builds the maps with debug IDs and uploads them after the compilation", () => {
    vi.stubEnv("TURBOPACK", "1");

    const config = withSourceMaps({ ...base }, UPLOAD);

    expect(config.productionBrowserSourceMaps).toBe(true);
    expect(config.turbopack?.debugIds).toBe(true);
    expect(config.compiler?.runAfterProductionCompile).toBeTypeOf("function");
  });

  it("with the flag changes nothing else of the build", () => {
    vi.stubEnv("TURBOPACK", "1");
    const original = { ...base, agentRules: false, experimental: { turbopackFileSystemCacheForDev: false } };

    const config = withSourceMaps(original, UPLOAD);

    // No trace meta tags in the HTML, no other external packages, no injected loaders or variables, no tunnel rewrites.
    expect(config.experimental).toEqual({ turbopackFileSystemCacheForDev: false });
    expect(config.serverExternalPackages).toBeUndefined();
    expect(config.env).toBeUndefined();
    expect(config.turbopack).toEqual({ debugIds: true });
    expect(config.rewrites).toBeUndefined();
    expect(config.webpack).toBeUndefined();
    const { productionBrowserSourceMaps, turbopack, compiler, ...rest } = config;
    expect([productionBrowserSourceMaps, turbopack, Object.keys(compiler ?? {})]).toEqual([true, { debugIds: true }, ["runAfterProductionCompile"]]);
    expect(rest).toEqual(original);
    // The config that came in is not touched.
    expect(original).toEqual({ ...base, agentRules: false, experimental: { turbopackFileSystemCacheForDev: false } });
  });

  it("with the flag fails when the bundler is not Turbopack (no maps would be uploaded)", () => {
    vi.stubEnv("TURBOPACK", "");

    expect(() => withSourceMaps({ ...base }, UPLOAD)).toThrow("Turbopack");
  });
});
