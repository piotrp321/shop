import { describe, expect, it } from "vitest";
import { apiPropagationTargets, browserSentryConfig, readSentryConfig } from "./config";

const DSN = "https://public@o1.ingest.de.sentry.io/2";

describe("readSentryConfig", () => {
  it.each([[undefined], [""], ["   "], [" "]])("is null without a DSN (%j): the SDK stays off", (dsn) => {
    expect(readSentryConfig({ SENTRY_DSN: dsn, SENTRY_ENVIRONMENT: "demo", SENTRY_TRACES_SAMPLE_RATE: "1" })).toBeNull();
  });

  it("reads the DSN, the environment, the release and the sample rate", () => {
    expect(
      readSentryConfig({ SENTRY_DSN: ` ${DSN} `, SENTRY_ENVIRONMENT: " demo ", SENTRY_RELEASE: "abc123", SENTRY_TRACES_SAMPLE_RATE: "0.1" }),
    ).toEqual({ dsn: DSN, environment: "demo", release: "abc123", tracesSampleRate: 0.1 });
  });

  it("has no environment and release when they are not set or empty, and a sample rate of 0", () => {
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
    [" 0.5 ", 0.5],
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

describe("browserSentryConfig", () => {
  it("is null without a DSN", () => {
    expect(browserSentryConfig({ SENTRY_ENVIRONMENT: "demo", SENTRY_RELEASE: "abc123" })).toBeNull();
  });

  it("gives the browser the DSN, the environment and the release, never the sample rate", () => {
    expect(browserSentryConfig({ SENTRY_DSN: DSN, SENTRY_ENVIRONMENT: "demo", SENTRY_RELEASE: "abc123", SENTRY_TRACES_SAMPLE_RATE: "1" })).toEqual({
      dsn: DSN,
      environment: "demo",
      release: "abc123",
    });
  });
});

describe("apiPropagationTargets", () => {
  const targets = apiPropagationTargets({ API_INTERNAL_URL: "http://api", API_CATALOG_URL: "http://varnish:8080/" });
  const propagates = (url: string) => targets.some((target) => target.test(url));

  it.each(["http://api", "http://api/", "http://api/api/v1/me", "http://api?x=1", "http://varnish:8080/api/v1/products"])(
    "propagates to the API at %s",
    (url) => {
      expect(propagates(url)).toBe(true);
    },
  );

  it.each([
    "http://keycloak:8080/realms/shop/.well-known/openid-configuration",
    "http://api.evil.example/",
    "http://apikey/",
    "http://api:8080/",
    "https://api/",
    "http://varnish/api/v1/products",
    "http://varnish:80800/",
    "http://evil.example/?u=http://api/",
    "redis://redis:6379",
  ])("does not propagate to %s", (url) => {
    expect(propagates(url)).toBe(false);
  });

  it("has no target for an address that is not set or not a URL", () => {
    expect(apiPropagationTargets({ API_INTERNAL_URL: "", API_CATALOG_URL: "not a url" })).toEqual([]);
    expect(apiPropagationTargets({})).toEqual([]);
  });
});
