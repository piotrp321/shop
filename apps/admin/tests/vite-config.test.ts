import { beforeEach, describe, expect, it, vi } from "vitest";

const sentryVitePlugin = vi.hoisted(() => vi.fn((options: unknown) => ({ name: "sentry-mock", options })));
vi.mock("@sentry/vite-plugin", () => ({ sentryVitePlugin }));

import { sentrySourceMaps } from "../vite.config";

const UPLOAD = { SENTRY_UPLOAD: "true", SENTRY_AUTH_TOKEN: "token", SENTRY_ORG: "worf", SENTRY_PROJECT: "admin" };

beforeEach(() => {
  sentryVitePlugin.mockClear();
});

describe("sentrySourceMaps", () => {
  it("adds nothing and emits no map without the upload flag", () => {
    expect(sentrySourceMaps({})).toEqual({ plugins: [], sourcemap: false });
    expect(sentrySourceMaps({ SENTRY_UPLOAD: "false", SENTRY_AUTH_TOKEN: "token" })).toEqual({ plugins: [], sourcemap: false });
    expect(sentrySourceMaps({ SENTRY_UPLOAD: "" })).toEqual({ plugins: [], sourcemap: false });
    expect(sentryVitePlugin).not.toHaveBeenCalled();
  });

  it("emits hidden maps and uploads them with the organisation, the project and the token when the flag is on", () => {
    const result = sentrySourceMaps(UPLOAD);

    expect(result.sourcemap).toBe("hidden");
    expect(result.plugins).toHaveLength(1);
    expect(sentryVitePlugin).toHaveBeenCalledOnce();
    expect(sentryVitePlugin.mock.calls[0]![0]).toMatchObject({ authToken: "token", org: "worf", project: "admin" });
  });

  it("sends no telemetry of the plugin and creates no release", () => {
    sentrySourceMaps(UPLOAD);

    expect(sentryVitePlugin.mock.calls[0]![0]).toMatchObject({
      telemetry: false,
      release: { inject: false, create: false, finalize: false, setCommits: false, deploy: false },
    });
  });

  it("deletes the maps after the upload", () => {
    sentrySourceMaps(UPLOAD);

    expect(sentryVitePlugin.mock.calls[0]![0]).toMatchObject({
      sourcemaps: { deleteSourcemapsAfterUpload: true, filesToDeleteAfterUpload: ["./dist/**/*.map"] },
    });
  });

  it("stops the build when the upload fails", () => {
    sentrySourceMaps(UPLOAD);
    const { errorHandler } = sentryVitePlugin.mock.calls[0]![0] as { errorHandler: (error: Error) => void };

    expect(() => errorHandler(new Error("401 Unauthorized"))).toThrow("401 Unauthorized");
  });

  it.each(["SENTRY_AUTH_TOKEN", "SENTRY_ORG", "SENTRY_PROJECT"])("fails when the flag is on and %s is missing or empty", (key) => {
    expect(() => sentrySourceMaps({ ...UPLOAD, [key]: undefined })).toThrow(key);
    expect(() => sentrySourceMaps({ ...UPLOAD, [key]: "" })).toThrow(key);
    expect(sentryVitePlugin).not.toHaveBeenCalled();
  });

  it("rejects a flag that is neither true nor false instead of silently skipping the upload", () => {
    expect(() => sentrySourceMaps({ ...UPLOAD, SENTRY_UPLOAD: "1" })).toThrow("SENTRY_UPLOAD");
    expect(() => sentrySourceMaps({ ...UPLOAD, SENTRY_UPLOAD: "TRUE" })).toThrow("SENTRY_UPLOAD");
  });
});
