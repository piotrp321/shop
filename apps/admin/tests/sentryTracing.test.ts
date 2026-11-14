import * as Sentry from "@sentry/vue";
import { createPinia, setActivePinia } from "pinia";
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { createApp, defineComponent } from "vue";
import { createMemoryHistory, createRouter } from "vue-router";
import { createApiClient } from "@/api/client";
import { initSentry } from "@/observability";
import { useAuthStore } from "@/stores/auth";

/**
 * The traces of the panel (spec: docs/features/sentry/spec.md, D7, D9 and the threat model, path 2): `sentry-trace` and
 * `baggage` go only to the API, and a transaction leaves without the query of its name, its spans and its data. A file of
 * its own: the SDK hooks `fetch` once per page for the first client that traces, so this file starts one client for all
 * its tests (sample rate 1) and the other files cannot give it a client with another rate. No network: a test transport
 * collects the envelopes, the DSN is made up.
 */

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: vi.fn() }) }));
vi.mock("@/api/client", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/api/client")>()), getApiClient: () => ({ GET: mocks.get }) }));

const API = "https://api.example.test";
const KEYCLOAK = "https://auth.example.test";
const SUB = "3f2c9a1e-7b4d-4c8e-9a6f-1d2e3c4b5a69";
const SEARCH = "q=jan.kowalski%40example.pl";
const PLANTED = /jan\.kowalski|example\.pl/;

interface Transaction {
  transaction?: string;
  user?: { id?: string };
  spans: Array<{ op?: string; description?: string }>;
}
type Envelope = [unknown, Array<[{ type: string }, Transaction]>];
const Blank = defineComponent({ render: () => null });
/** The generated client of the API, made after the SDK started, so it calls the `fetch` the SDK wrapped. */
const apiClient = (baseUrl: string) => createApiClient({ baseUrl, userManager: { getUser: async () => null, signinRedirect: async () => {} } });
const envelopes: Envelope[] = [];
const transport = (() => ({
  send: async (envelope: Envelope) => {
    envelopes.push(envelope);
    return {};
  },
  flush: async () => true,
})) as unknown as NonNullable<Sentry.BrowserOptions["transport"]>;

/** The fetch of the page, put in place before the SDK wraps it: records the headers of every call, answers 200. */
const calls: Array<{ url: string; headers: Headers }> = [];
vi.stubGlobal("fetch", (async (input: RequestInfo | URL, init?: RequestInit) => {
  const request = new Request(input, init);
  calls.push({ url: request.url, headers: request.headers });
  return new Response("{}", { status: 200 });
}) as typeof fetch);

beforeAll(() => {
  window.__ADMIN_CONFIG__ = {
    VITE_API_URL: API,
    VITE_KEYCLOAK_URL: KEYCLOAK,
    SENTRY_DSN: "https://public@o0.ingest.de.sentry.io/1",
    SENTRY_TRACES_SAMPLE_RATE: "1",
  };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: "/:rest(.*)*", component: Blank }] });
  expect(initSentry(createApp(Blank), router, { transport })).toBe(true);
});

afterAll(async () => {
  await Sentry.close();
  delete window.__ADMIN_CONFIG__;
});

beforeEach(() => {
  setActivePinia(createPinia());
  envelopes.length = 0;
  calls.length = 0;
  mocks.get.mockReset().mockResolvedValue({ data: { id: SUB, username: "jan.kowalski", roles: ["admin"] } });
});

describe("propagation of the trace (D7)", () => {
  it("adds sentry-trace and baggage to a call to the API and not to a call to Keycloak or to a host that starts like the API", async () => {
    await Sentry.startSpan({ name: "navigation", op: "navigation" }, async () => {
      await apiClient(API).GET("/api/v1/admin/me");
      await apiClient(KEYCLOAK).GET("/api/v1/admin/me");
      await apiClient("https://api.example.test.evil.example").GET("/api/v1/admin/me");
    });

    const [toApi, toKeycloak, toEvil] = calls;
    expect(toApi!.url).toBe(`${API}/api/v1/admin/me`);
    expect(toKeycloak!.url).toBe(`${KEYCLOAK}/api/v1/admin/me`);
    expect(toApi!.headers.get("sentry-trace")).toMatch(/^[0-9a-f]{32}-[0-9a-f]{16}-1$/);
    expect(toApi!.headers.get("baggage")).toContain("sentry-trace_id=");
    expect(toKeycloak!.headers.get("sentry-trace")).toBeNull();
    expect(toKeycloak!.headers.get("baggage")).toBeNull();
    expect(toEvil!.headers.get("sentry-trace")).toBeNull();
    expect(toEvil!.headers.get("baggage")).toBeNull();
  });
});

describe("transactions", () => {
  it("leave without the query in the name, the spans and the data (path 2), with the id of the staff member only (path 6)", async () => {
    await useAuthStore().load();
    // The pageload span of the page is active and ends later: a span of its own is a root and gives a transaction at once.
    await Sentry.withActiveSpan(null, () =>
      Sentry.startSpan({ name: `/orders?${SEARCH}`, op: "navigation" }, async () => {
        await apiClient(API).GET("/api/v1/admin/orders", { params: { query: { q: "jan.kowalski@example.pl" } } });
      }),
    );
    await Sentry.flush(2000);

    const [transaction] = envelopes.flatMap(([, items]) => items.filter(([header]) => header.type === "transaction").map(([, payload]) => payload));
    expect(transaction).toBeDefined();
    expect(transaction!.transaction).toBe("/orders");
    expect(transaction!.user).toEqual({ id: SUB });
    const http = transaction!.spans.find((span) => span.op === "http.client");
    expect(http?.description).toBe(`GET ${API}/api/v1/admin/orders`);
    expect(JSON.stringify(transaction)).not.toMatch(PLANTED);
  });
});
