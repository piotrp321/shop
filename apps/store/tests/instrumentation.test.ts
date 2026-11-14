import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The wiring of `instrumentation.ts` (spec: docs/features/sentry/spec.md, D1, D2, D8): the SDK of the server starts only
 * in the Node.js runtime and only with a DSN; without it the module of the SDK is not even loaded. The SDK itself is
 * tested in src/observability/server.test.ts.
 */

const loaded = vi.hoisted(() => vi.fn());
const initServerSentry = vi.hoisted(() => vi.fn());
const captureServerRequestError = vi.hoisted(() => vi.fn());

vi.mock("@/observability/server", () => {
  loaded();
  return { initServerSentry, captureServerRequestError };
});

const { onRequestError, register } = await import("../instrumentation");

const request = { path: "/p/buty?discountCode=X", method: "GET", headers: {} };
const context = { routerKind: "App Router", routePath: "/p/[slug]", routeType: "render", renderSource: "server-rendering", revalidateReason: undefined } as const;

beforeEach(() => {
  loaded.mockClear();
  initServerSentry.mockClear();
  captureServerRequestError.mockClear();
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("instrumentation", () => {
  it("does not load the SDK without a DSN", async () => {
    vi.stubEnv("NEXT_RUNTIME", "nodejs");
    vi.stubEnv("SENTRY_DSN", "");

    await register();
    await onRequestError(new Error("x"), request, context);

    expect(loaded).not.toHaveBeenCalled();
    expect(initServerSentry).not.toHaveBeenCalled();
    expect(captureServerRequestError).not.toHaveBeenCalled();
  });

  it("does not start the SDK in the Edge runtime", async () => {
    vi.stubEnv("NEXT_RUNTIME", "edge");
    vi.stubEnv("SENTRY_DSN", "https://public@o1.ingest.de.sentry.io/2");

    await register();
    await onRequestError(new Error("x"), request, context);

    expect(initServerSentry).not.toHaveBeenCalled();
    expect(captureServerRequestError).not.toHaveBeenCalled();
  });

  it("starts the SDK with the configuration of the variables and reports request errors through it", async () => {
    vi.stubEnv("NEXT_RUNTIME", "nodejs");
    vi.stubEnv("SENTRY_DSN", "https://public@o1.ingest.de.sentry.io/2");
    vi.stubEnv("SENTRY_ENVIRONMENT", "demo");
    vi.stubEnv("SENTRY_RELEASE", "abc123");
    vi.stubEnv("SENTRY_TRACES_SAMPLE_RATE", "0.1");
    const error = new Error("x");

    await register();
    await onRequestError(error, request, context);

    expect(initServerSentry).toHaveBeenCalledWith(
      { dsn: "https://public@o1.ingest.de.sentry.io/2", environment: "demo", release: "abc123", tracesSampleRate: 0.1 },
      process.env,
    );
    expect(captureServerRequestError).toHaveBeenCalledWith(error, request, context);
  });
});
