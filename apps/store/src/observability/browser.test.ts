// @vitest-environment jsdom
import type { Breadcrumb, Event } from "@sentry/nextjs";
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The SDK of the browser of the store (spec: docs/features/sentry/spec.md, P2, D4, D8) on the browser build of
 * `@sentry/nextjs` (the one the bundler takes for the browser; Vitest would take the server build) with a fake DSN and a
 * transport that keeps the envelopes: errors only (no traces, no Replay, no sessions), filtered (paths 1, 2, 4, 6).
 */

vi.mock("@sentry/nextjs", async () => {
  const browserBuild = "../../node_modules/@sentry/nextjs/build/esm/index.client.js";
  return import(/* @vite-ignore */ browserBuild);
});

const Sentry = await import("@sentry/nextjs");
const { initBrowserSentry, reportBrowserError } = await import("./browser");

const EMAIL = "jan.kowalski@example.pl";
const QUERY = "discountCode=RABAT-SECRET&returnTo=%2Fkonto&code=v-oidc-code&state=v-oidc-state&q=jan.kowalski%40example.pl";

type Envelope = [Record<string, unknown>, Array<[{ type?: string }, unknown]>];

const envelopes: Envelope[] = [];
const events = () =>
  envelopes.flatMap((envelope) => envelope[1].filter(([header]) => header.type === "event").map(([, payload]) => payload as Event));
const sentTypes = () => envelopes.flatMap((envelope) => envelope[1].map(([header]) => header.type));

const transport = () => ({
  send: async (envelope: unknown) => {
    envelopes.push(envelope as Envelope);
    return {};
  },
  flush: async () => true,
});

beforeAll(() => {
  window.history.replaceState(null, "", `/checkout?${QUERY}#adres`);
  document.cookie = "store_cart=v-cart-cookie";
  initBrowserSentry({ dsn: "https://public@o1.ingest.de.sentry.io/2", environment: "demo", release: "abc123" }, { transport });
});

afterAll(async () => {
  await Sentry.close();
});

beforeEach(() => {
  envelopes.length = 0;
});

describe("initBrowserSentry", () => {
  it("starts the browser SDK with the DSN, the environment and the release, without traces", () => {
    const options = Sentry.getClient()?.getOptions();
    expect(Sentry.getClient()?.constructor.name).toBe("BrowserClient");
    expect(options?.dsn).toBe("https://public@o1.ingest.de.sentry.io/2");
    expect(options?.environment).toBe("demo");
    expect(options?.release).toBe("abc123");
    expect(options?.tracesSampleRate).toBeUndefined();
    expect(options?.tracesSampler).toBeUndefined();
  });

  it("has no tracing, Replay or session integration: errors only", () => {
    const names = Sentry.getClient()?.getOptions().integrations.map((integration) => integration.name) ?? [];
    expect(names).toContain("GlobalHandlers");
    for (const name of ["BrowserTracing", "Replay", "ReplayCanvas", "BrowserSession", "Feedback"]) {
      expect(names).not.toContain(name);
    }
  });
});

describe("reportBrowserError", () => {
  it("sends one filtered event: no query, no cookies, no Referer, no user, masked texts", async () => {
    console.log(`cart of ${EMAIL}`);
    Sentry.setUser({ id: "x", email: EMAIL, ip_address: "203.0.113.45" });

    reportBrowserError(new TypeError(`Cannot read the order of ${EMAIL}`));
    await Sentry.flush(1000);
    Sentry.setUser(null);

    expect(events()).toHaveLength(1);
    const [event] = events();
    expect(event!.exception?.values?.[0]?.value).toBe("Cannot read the order of [REDACTED]");
    expect(event!.request?.url).toBe("http://localhost:3000/checkout");
    expect(Object.keys(event!.request?.headers ?? {}).map((name) => name.toLowerCase())).toEqual(["user-agent"]);
    expect(event!.request?.cookies).toBeUndefined();
    expect(event!.user).toBeUndefined();
    expect(event!.breadcrumbs?.some((breadcrumb: Breadcrumb) => breadcrumb.category === "console") ?? false).toBe(false);
    const sent = JSON.stringify(envelopes);
    for (const value of ["RABAT-SECRET", "v-oidc-code", "v-oidc-state", "jan.kowalski", "v-cart-cookie", "203.0.113.45", "adres"]) {
      expect(sent, `${value} was sent to Sentry`).not.toContain(value);
    }
  });

  it("sends nothing but the event: no session, no transaction", async () => {
    reportBrowserError(new Error("x"));
    await Sentry.flush(1000);
    expect(sentTypes()).toEqual(["event"]);
  });

  it("filters an unhandled error caught by the SDK in the same way", async () => {
    // The handler of the SDK on window.onerror, called as the browser would (a dispatched ErrorEvent would also reach
    // the handler of Vitest).
    const error = new Error(`token=v-secret-token for ${EMAIL}`);
    window.onerror?.(error.message, `http://localhost:3000/checkout?${QUERY}`, 1, 1, error);
    await Sentry.flush(1000);

    const [event] = events();
    expect(event!.exception?.values?.[0]?.value).toBe("token=[REDACTED] for [REDACTED]");
    expect(event!.request?.url).toBe("http://localhost:3000/checkout");
  });
});
