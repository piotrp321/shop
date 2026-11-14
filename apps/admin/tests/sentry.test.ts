import * as Sentry from "@sentry/vue";
import { flushPromises, mount } from "@vue/test-utils";
import { createPinia, setActivePinia } from "pinia";
import PrimeVue from "primevue/config";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createApp, defineComponent } from "vue";
import { createMemoryHistory, createRouter, RouterView } from "vue-router";
import AppLayout from "@/layout/AppLayout.vue";
import { createApiClient } from "@/api/client";
import { initSentry, setSentryUser } from "@/observability";
import { buildSentryOptions } from "@/observability/options";
import { primeVueOptions } from "@/primevue";
import { useAuthStore } from "@/stores/auth";

/**
 * The SDK of Sentry in the panel (spec: docs/features/sentry/spec.md, D1, D7, D9, P5 and the threat model, paths 1, 2, 4, 5
 * and 6): it starts only with a DSN, sends events with the id of the staff member and nothing of the query strings,
 * propagates `sentry-trace` and `baggage` only to the API, and has no props of components and no Pinia integration.
 * No network: a test transport collects the envelopes, the DSN is made up.
 *
 * The SDK wraps `fetch`, `history`, `console` and `window.onerror` once per page, in a dependency that Vitest does not
 * reload, and wraps what it finds there: the `fetch` of the tests is installed once, before the first `init`, and records
 * its calls in a list that is emptied for every test. Clients of earlier tests are closed and their handlers do nothing.
 */

const mocks = vi.hoisted(() => ({ signoutRedirect: vi.fn(), get: vi.fn() }));

vi.mock("@/auth/userManager", () => ({ getUserManager: () => ({ signoutRedirect: mocks.signoutRedirect }) }));
vi.mock("@/api/client", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/api/client")>()), getApiClient: () => ({ GET: mocks.get }) }));
// The real SDK, but `init` is observed: without a DSN it must not be called at all.
vi.mock("@sentry/vue", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@sentry/vue")>();
  return { ...actual, init: vi.fn(actual.init) };
});

const API = "https://api.example.test";
const KEYCLOAK = "https://auth.example.test";
const DSN = "https://public@o0.ingest.de.sentry.io/1";
const SUB = "3f2c9a1e-7b4d-4c8e-9a6f-1d2e3c4b5a69";
const SEARCH = "q=jan.kowalski%40example.pl";
const CALLBACK = "code=v-oidc-code&state=v-oidc-state";
const PLANTED = /jan\.kowalski|example\.pl|v-oidc-code|v-oidc-state/;
const ME = { id: SUB, username: "jan.kowalski", roles: ["admin"] };
const MINUTE = 60_000;

/** What the tests read of an event or a transaction. */
interface Payload {
  type?: string;
  transaction?: string;
  user?: { id?: string };
  request: { url: string; headers?: Record<string, string>; cookies?: unknown };
  exception?: { values: Array<{ value?: string }> };
  breadcrumbs: Array<{ category?: string }>;
}
type Envelope = [unknown, Array<[{ type: string }, Payload]>];
const envelopes: Envelope[] = [];
const transport = (() => ({
  send: async (envelope: Envelope) => {
    envelopes.push(envelope);
    return {};
  },
  flush: async () => true,
})) as unknown as NonNullable<Sentry.BrowserOptions["transport"]>;

/** The events and transactions sent so far (the envelopes of sessions and the like are not events). */
function sent(): Payload[] {
  return envelopes.flatMap(([, items]) =>
    items.filter(([header]) => header.type === "event" || header.type === "transaction").map(([, payload]) => payload),
  );
}

const Blank = defineComponent({ render: () => null });
const newRouter = () => createRouter({ history: createMemoryHistory(), routes: [{ path: "/:rest(.*)*", component: Blank }] });
/** The generated client of the API, made after the SDK started, so it calls the `fetch` the SDK wrapped. */
const apiClient = (baseUrl: string) => createApiClient({ baseUrl, userManager: { getUser: async () => null, signinRedirect: async () => {} } });

/** Starts the panel's SDK as main.ts does, with the runtime configuration given. */
function start(config: Record<string, string | undefined> = {}, overrides: Sentry.BrowserOptions = {}) {
  window.__ADMIN_CONFIG__ = { VITE_API_URL: `${API}/`, VITE_KEYCLOAK_URL: KEYCLOAK, SENTRY_DSN: DSN, ...config } as Window["__ADMIN_CONFIG__"];
  return initSentry(createApp(Blank), newRouter(), { transport, ...overrides });
}

/** The fetch of the page, put in place once before the SDK wraps it: records the headers of every call, answers 200. */
const calls: Array<{ url: string; headers: Headers }> = [];
vi.stubGlobal("fetch", (async (input: RequestInfo | URL, init?: RequestInit) => {
  const request = new Request(input, init);
  calls.push({ url: request.url, headers: request.headers });
  return new Response("{}", { status: 200 });
}) as typeof fetch);

beforeEach(() => {
  setActivePinia(createPinia());
  envelopes.length = 0;
  calls.length = 0;
  mocks.get.mockReset().mockResolvedValue({ data: ME });
  mocks.signoutRedirect.mockReset().mockResolvedValue(undefined);
  vi.mocked(Sentry.init).mockClear();
});

afterEach(async () => {
  await Sentry.close();
  Sentry.getCurrentScope().setClient(undefined);
  // setUser(null) on the isolation scope only: the SDK of the panel sets the user there, and a user "cleared" on the
  // current scope would overwrite the id of the isolation scope in the events of the next test.
  Sentry.getIsolationScope().setUser(null).clearBreadcrumbs();
  window.history.replaceState({}, "", "/");
  delete window.__ADMIN_CONFIG__;
  vi.useRealTimers();
});

describe("without a DSN", () => {
  it.each([[{ SENTRY_DSN: "" }], [{ SENTRY_DSN: "  " }], [{ SENTRY_DSN: undefined }]])(
    "does not call the SDK at all (%j)",
    async (config) => {
      
      expect(start(config)).toBe(false);

      expect(Sentry.init).not.toHaveBeenCalled();
      expect(Sentry.getClient()).toBeUndefined();
    },
  );

  it("leaves the panel working when the DSN is malformed: the SDK sends nothing and nothing throws", () => {
    expect(() => start({ SENTRY_DSN: "not-a-dsn" })).not.toThrow();

    Sentry.captureException(new Error("boom"));
    expect(sent()).toEqual([]);
  });

  it("sets no user: setSentryUser is a no-op", async () => {
        start({ SENTRY_DSN: "" });

    setSentryUser(SUB);

    expect(Sentry.getIsolationScope().getUser()?.id).toBeUndefined();
  });
});

describe("the options of the SDK", () => {
  const config = { dsn: DSN, environment: "demo", release: "abc123", tracesSampleRate: 0.1 };
  const build = async () => {
        return buildSentryOptions(config, API, newRouter());
  };

  it("passes the DSN, the environment, the release and the sample rate", async () => {
    expect(await build()).toMatchObject({ dsn: DSN, environment: "demo", release: "abc123", tracesSampleRate: 0.1 });
  });

  it("does not attach the props of components and collects no data of the user", async () => {
    expect(await build()).toMatchObject({
      attachProps: false,
      dataCollection: { userInfo: false, cookies: false, httpBodies: [], urlQueryParams: false, stackFrameVariables: false },
    });
  });

  it("has the tracing of the router and no Pinia integration and no Replay", async () => {
    const names = ((await build()).integrations as Array<{ name: string }>).map(({ name }) => name);
    expect(names).toEqual(["BrowserTracing"]);
    expect(names.join()).not.toMatch(/pinia|replay/i);
  });

  it("starts a client with the tracing of the router and without an integration of Pinia, Replay or Feedback", () => {
    start();
    const client = Sentry.getClient()!;

    expect((client.getOptions() as { attachProps?: boolean }).attachProps).toBe(false);
    expect(client.getIntegrationByName("BrowserTracing")).toBeDefined();
    for (const name of ["Pinia", "Replay", "Feedback"]) {
      expect(client.getIntegrationByName(name)).toBeUndefined();
    }
  });

  it("sends transactions through beforeSendTransaction (a transaction per page, not streamed spans)", async () => {
    expect((await build()).traceLifecycle).toBe("static");
  });

  it("filters events, transactions and breadcrumbs with the one filter", async () => {
    const { beforeSend, beforeSendTransaction, beforeBreadcrumb } = await build();
    expect(beforeSend).toBeTypeOf("function");
    expect(beforeSendTransaction).toBe(beforeSend);
    expect(beforeBreadcrumb).toBeTypeOf("function");
  });

  it("propagates the trace only to the API: anchored, not to a host that starts like it, not to Keycloak", async () => {
    const targets = (await build()).tracePropagationTargets as RegExp[];
    const propagates = (url: string) => targets.some((target) => target.test(url));

    expect(targets).toHaveLength(1);
    expect(propagates(`${API}/api/v1/admin/me`)).toBe(true);
    expect(propagates(API)).toBe(true);
    expect(propagates(`${API}?x=1`)).toBe(true);
    expect(propagates("https://api.example.test.evil.example/api")).toBe(false);
    expect(propagates("https://api.example.testing/api")).toBe(false);
    expect(propagates("https://evil.example/?next=https://api.example.test/")).toBe(false);
    expect(propagates(`${KEYCLOAK}/realms/shop/protocol/openid-connect/token`)).toBe(false);
  });

  it("escapes the characters of the address of the API in the pattern", async () => {
        const [target] = buildSentryOptions(config, "http://localhost:8000/v1", newRouter()).tracePropagationTargets as RegExp[];
    expect(target!.test("http://localhost:8000/v1/me")).toBe(true);
    expect(target!.test("http://localhost:8000/v1x/me")).toBe(false);
    expect(target!.test("http://localhostX8000/v1/me")).toBe(false);
  });

  it("leaves the environment and the release out when they are not configured", async () => {
        const bare = buildSentryOptions({ ...config, environment: null, release: null }, API, newRouter());
    expect(bare.environment).toBeUndefined();
    expect(bare.release).toBeUndefined();
  });

  it("does not use the plugin of Pinia, Replay or Feedback of the SDK anywhere in the panel", () => {
    const sources = Object.values(
      import.meta.glob<string>(["../src/main.ts", "../src/observability/*.ts", "../src/stores/*.ts"], { query: "?raw", import: "default", eager: true }),
    );
    expect(sources.length).toBeGreaterThanOrEqual(5);
    for (const source of sources) {
      expect(source).not.toMatch(/createSentryPiniaPlugin|replayIntegration|feedbackIntegration/);
    }
  });
});

describe("events with a DSN", () => {
  it("starts the SDK once, with the environment and the release", async () => {
    
    expect(start({ SENTRY_ENVIRONMENT: "demo", SENTRY_RELEASE: "abc123" })).toBe(true);

    expect(Sentry.init).toHaveBeenCalledTimes(1);
    expect(Sentry.getClient()?.getOptions()).toMatchObject({ environment: "demo", release: "abc123" });
  });

  it("sends an unhandled exception with the id of the staff member only and without the query of the page (paths 2 and 6)", async () => {
        start();
    await useAuthStore().load();
    window.history.replaceState({}, "", `/auth/callback?${CALLBACK}&${SEARCH}#fragment`);

    // What the browser does with an exception that nothing caught.
    window.onerror!("boom", "http://localhost:3000/assets/index.js", 1, 1, new Error("boom"));
    await Sentry.flush(2000);

    const [event] = sent().filter((payload) => payload.exception);
    expect(event).toBeDefined();
    expect(event!.exception?.values[0]?.value).toBe("boom");
    expect(event!.user).toEqual({ id: SUB });
    expect(event!.request.url).toBe("http://localhost:3000/auth/callback");
    expect(JSON.stringify(envelopes)).not.toMatch(PLANTED);
  });

  it("sends no headers but three, no cookies and no address (path 1)", async () => {
        start();
    document.cookie = "KEYCLOAK_SESSION=v-keycloak-cookie";

    Sentry.captureException(new Error("boom"));
    await Sentry.flush(2000);

    const [event] = sent();
    const headers = Object.keys(event!.request.headers ?? {}).map((name) => name.toLowerCase());
    expect(headers.filter((name) => !["user-agent", "content-type", "accept"].includes(name))).toEqual([]);
    expect(event!.request.cookies).toBeUndefined();
    expect(JSON.stringify(event)).not.toContain("v-keycloak-cookie");
    expect(event!.user).toBeUndefined();
  });

  it("keeps the query of the navigation and of the calls to the API out of the breadcrumbs, and the console", async () => {
        start();
    window.history.pushState({}, "", `/orders?${SEARCH}`);
    await apiClient(API).GET("/api/v1/admin/orders", { params: { query: { q: "jan.kowalski@example.pl" } } });
    console.error("Search failed for jan.kowalski@example.pl");

    Sentry.captureException(new Error("boom"));
    await Sentry.flush(2000);

    const [event] = sent();
    const categories = (event!.breadcrumbs as Array<{ category?: string }>).map(({ category }) => category);
    expect(categories).toEqual(expect.arrayContaining(["navigation", "fetch"]));
    expect(categories).not.toContain("console");
    expect(JSON.stringify(event)).not.toMatch(PLANTED);
  });

  it("masks an e-mail in the message of an exception (path 4)", async () => {
        start();

    Sentry.captureException(new Error("Order of jan.kowalski@example.pl failed"));
    await Sentry.flush(2000);

    expect(sent()[0]!.exception?.values[0]?.value).toBe("Order of [REDACTED] failed");
  });
});

describe("the user of the events (P5)", () => {
  /** The user of the next event: a new exception with a message of its own, so that no other event is taken for it. */
  let counter = 0;
  async function userOfNextEvent(): Promise<unknown> {
    const message = `after-${++counter}`;
    Sentry.captureException(new Error(message));
    await Sentry.flush(2000);
    return sent().find((payload) => payload.exception?.values[0]?.value === message)!.user;
  }

  it("is the id of the staff member after /me is loaded, never the login", async () => {
    start();
    await useAuthStore().load();

    expect(await userOfNextEvent()).toEqual({ id: SUB });
  });

  it("is not set when /me fails", async () => {
    start();
    mocks.get.mockResolvedValue({ error: { status: 503 }, response: { status: 503 } });
    await useAuthStore().load();

    expect(await userOfNextEvent()).toBeUndefined();
  });

  it("is gone after the logout", async () => {
    start();
    const auth = useAuthStore();
    await auth.load();
    expect(await userOfNextEvent()).toEqual({ id: SUB });

    await auth.logout();

    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
    expect(await userOfNextEvent()).toBeUndefined();
  });

  it("is gone also when the sign-out fails: the identity does not stay on events of a session that is being ended", async () => {
    start();
    mocks.signoutRedirect.mockRejectedValue(new Error("Keycloak unreachable"));
    const auth = useAuthStore();
    await auth.load();

    await auth.logout();

    expect(auth.logoutFailed).toBe(true);
    expect(await userOfNextEvent()).toBeUndefined();
  });

  it("is back when /me is loaded again after a failed sign-out", async () => {
    start();
    mocks.signoutRedirect.mockRejectedValue(new Error("Keycloak unreachable"));
    const auth = useAuthStore();
    await auth.load();
    await auth.logout();

    await auth.load();

    expect(await userOfNextEvent()).toEqual({ id: SUB });
  });

  it("is gone after the logout of an idle staff member (15 minutes without activity)", async () => {
    window.__ADMIN_CONFIG__ = { VITE_API_URL: API, VITE_KEYCLOAK_URL: KEYCLOAK, SENTRY_DSN: DSN };
    const app = createApp(Blank);
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        {
          path: "/",
          component: AppLayout,
          children: ["dashboard", "products", "product-new", "prices", "promotions", "discount-codes", "inventory", "system", "orders"].map((name) => ({
            path: name === "dashboard" ? "" : name === "product-new" ? "products/new" : name,
            name,
            component: Blank,
          })),
        },
      ],
    });
    expect(initSentry(app, router, { transport })).toBe(true);
    vi.useFakeTimers();
    await router.push("/");
    const wrapper = mount(RouterView, { global: { plugins: [createPinia(), router, [PrimeVue, primeVueOptions]] } });
    await flushPromises();
    expect(Sentry.getIsolationScope().getUser()).toEqual({ id: SUB });

    await vi.advanceTimersByTimeAsync(15 * MINUTE);
    vi.useRealTimers();
    expect(mocks.signoutRedirect).toHaveBeenCalledTimes(1);
    Sentry.captureException(new Error("after idle"));
    await Sentry.flush(2000);

    const event = sent().find((payload) => payload.exception?.values[0]?.value === "after idle");
    expect(event).toBeDefined();
    expect(event!.user).toBeUndefined();
    wrapper.unmount();
  });
});
