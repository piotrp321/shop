import { afterEach, describe, expect, it, vi } from "vitest";
import { readSentryConfig, readUrl } from "@/config";

afterEach(() => {
  delete window.__ADMIN_CONFIG__;
  vi.unstubAllEnvs();
});

describe("readUrl", () => {
  it("prefers window.__ADMIN_CONFIG__ over import.meta.env", () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: "https://api.example.test" };
    vi.stubEnv("VITE_API_URL", "http://localhost:8000");

    expect(readUrl("VITE_API_URL")).toBe("https://api.example.test");
  });

  it("falls back to import.meta.env when window.__ADMIN_CONFIG__ has no such key", () => {
    window.__ADMIN_CONFIG__ = { VITE_KEYCLOAK_URL: "https://auth.example.test" };
    vi.stubEnv("VITE_API_URL", "http://localhost:8000");

    expect(readUrl("VITE_API_URL")).toBe("http://localhost:8000");
  });

  it("falls back to import.meta.env when window.__ADMIN_CONFIG__ is not defined", () => {
    vi.stubEnv("VITE_ADMIN_URL", "http://localhost:5173");

    expect(readUrl("VITE_ADMIN_URL")).toBe("http://localhost:5173");
  });

  it("throws naming the key when neither source has it", () => {
    window.__ADMIN_CONFIG__ = {};
    vi.stubEnv("VITE_KEYCLOAK_URL", "");

    expect(() => readUrl("VITE_KEYCLOAK_URL")).toThrow("VITE_KEYCLOAK_URL");
  });

  it("drops trailing slashes from the value of either source", () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: "https://api.example.test//" };
    vi.stubEnv("VITE_ADMIN_URL", "http://localhost:5173/");

    expect(readUrl("VITE_API_URL")).toBe("https://api.example.test");
    expect(readUrl("VITE_ADMIN_URL")).toBe("http://localhost:5173");
  });

  it("reads explicit sources when given (no global state)", () => {
    expect(readUrl("VITE_API_URL", { runtime: { VITE_API_URL: "https://a.test/" }, env: {} })).toBe("https://a.test");
    expect(() => readUrl("VITE_API_URL", { runtime: undefined, env: {} })).toThrow("VITE_API_URL");
  });
});

describe("readSentryConfig", () => {
  const DSN = "https://public@o0.ingest.de.sentry.io/1";

  it.each([[undefined], [{}], [{ SENTRY_DSN: "" }], [{ SENTRY_DSN: "  " }], [{ SENTRY_DSN: "\u00a0" }]])(
    "is null without a DSN (%j): the SDK stays off",
    (runtime) => {
      expect(readSentryConfig(runtime)).toBeNull();
    },
  );

  it("reads the four keys of window.__ADMIN_CONFIG__ by default", () => {
    window.__ADMIN_CONFIG__ = {
      SENTRY_DSN: DSN,
      SENTRY_ENVIRONMENT: "demo",
      SENTRY_RELEASE: "abc123",
      SENTRY_TRACES_SAMPLE_RATE: "0.1",
    };

    expect(readSentryConfig()).toEqual({ dsn: DSN, environment: "demo", release: "abc123", tracesSampleRate: 0.1 });
  });

  it("is null when window.__ADMIN_CONFIG__ has no DSN, whatever the Vite environment holds", () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: "https://api.example.test" };
    vi.stubEnv("SENTRY_DSN", DSN);
    vi.stubEnv("VITE_SENTRY_DSN", DSN);

    expect(readSentryConfig()).toBeNull();
  });

  it("has no environment and release when they are missing or blank, and a sample rate of 0", () => {
    expect(readSentryConfig({ SENTRY_DSN: DSN, SENTRY_ENVIRONMENT: "", SENTRY_RELEASE: " " })).toEqual({
      dsn: DSN,
      environment: null,
      release: null,
      tracesSampleRate: 0,
    });
  });

  it.each([
    ["0", 0],
    ["1", 1],
    ["0.25", 0.25],
    ["", 0],
    ["abc", 0],
    ["-0.1", 0],
    ["1.5", 0],
    ["NaN", 0],
    ["Infinity", 0],
    ["1e-1", 0],
    ["0x1", 0],
  ])("takes the sample rate %j as %s (anything but a number from 0 to 1 is 0)", (rate, expected) => {
    expect(readSentryConfig({ SENTRY_DSN: DSN, SENTRY_TRACES_SAMPLE_RATE: rate })?.tracesSampleRate).toBe(expected);
  });
});
