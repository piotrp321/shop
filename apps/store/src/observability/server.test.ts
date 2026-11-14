import { createServer, type IncomingHttpHeaders, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { gunzipSync } from "node:zlib";
import * as Sentry from "@sentry/nextjs";
import { getTracer } from "next/dist/server/lib/trace/tracer";
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { createApiClient } from "@/api/client";
import { discoverOidc } from "@/auth/oidc";
import { testConfig } from "@/auth/test-support";
import { onRequestError, register } from "../../instrumentation";

/**
 * The SDK of the server of the store as `instrumentation.ts` starts it, with a fake DSN that points at a local
 * collector of envelopes (no traffic to Sentry): one event per request error without the query and the cookies (path 1,
 * 2, 6), no continuation of an incoming trace (path 9) and `sentry-trace`/`baggage` only to the API (D7).
 */

type Envelope = { header: Record<string, unknown>; items: Array<{ type: string; payload: Record<string, unknown> }> };
type Recorded = { url: string; headers: IncomingHttpHeaders };

const QUERY = "discountCode=RABAT-SECRET&returnTo=%2Fkonto&code=v-oidc-code&state=v-oidc-state&q=jan.kowalski%40example.pl";
const PLANTED = ["RABAT-SECRET", "%2Fkonto", "v-oidc-code", "v-oidc-state", "jan.kowalski", "v-session-cookie", "v-cart-cookie", "v-order-access-token", "v-bearer-token-1234567890"];
const INCOMING_TRACE_ID = "0123456789abcdef0123456789abcdef";

let envelopes: Envelope[];
let apiRequests: Recorded[];
let keycloakRequests: Recorded[];
const servers: Server[] = [];

async function listen(handler: Parameters<typeof createServer>[1]): Promise<string> {
  const server = createServer(handler);
  servers.push(server);
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  return `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
}

function parseEnvelope(body: Buffer, encoding: string | undefined): Envelope {
  const lines = (encoding === "gzip" ? gunzipSync(body) : body).toString("utf8").split("\n").filter(Boolean);
  const items: Envelope["items"] = [];
  for (let index = 1; index + 1 < lines.length; index += 2) {
    items.push({ type: (JSON.parse(lines[index]!) as { type: string }).type, payload: JSON.parse(lines[index + 1]!) });
  }
  return { header: JSON.parse(lines[0]!), items };
}

const items = (type: string) => envelopes.flatMap((envelope) => envelope.items.filter((item) => item.type === type));

let apiUrl: string;
let catalogUrl: string;
let keycloakUrl: string;

beforeAll(async () => {
  const collector = await listen((request, response) => {
    const chunks: Buffer[] = [];
    request.on("data", (chunk: Buffer) => chunks.push(chunk));
    request.on("end", () => {
      envelopes.push(parseEnvelope(Buffer.concat(chunks), request.headers["content-encoding"]));
      response.writeHead(200).end("{}");
    });
  });
  // The fake API and Keycloak run in this process, whose server drops incoming trace headers: they read what was sent.
  const record = (list: () => Recorded[]) =>
    listen((request, response) => {
      const headers: IncomingHttpHeaders = {};
      for (let index = 0; index + 1 < request.rawHeaders.length; index += 2) {
        headers[request.rawHeaders[index]!.toLowerCase()] = request.rawHeaders[index + 1];
      }
      list().push({ url: request.url ?? "", headers });
      response.writeHead(200, { "content-type": "application/json" }).end("{}");
    });
  apiUrl = await record(() => apiRequests);
  catalogUrl = (await record(() => apiRequests)).replace("127.0.0.1", "localhost");
  keycloakUrl = await record(() => keycloakRequests);

  vi.stubEnv("NEXT_RUNTIME", "nodejs");
  vi.stubEnv("SENTRY_DSN", `${collector.replace("://", "://public@")}/1`);
  vi.stubEnv("SENTRY_ENVIRONMENT", "test-environment");
  vi.stubEnv("SENTRY_RELEASE", "test-release");
  vi.stubEnv("SENTRY_TRACES_SAMPLE_RATE", "0");
  vi.stubEnv("API_INTERNAL_URL", apiUrl);
  vi.stubEnv("API_CATALOG_URL", catalogUrl);
  await register();
});

afterAll(async () => {
  await Sentry.close(2000);
  vi.unstubAllEnvs();
  await Promise.all(servers.map((server) => new Promise((resolve) => server.close(resolve))));
});

beforeEach(() => {
  envelopes = [];
  apiRequests = [];
  keycloakRequests = [];
});

const flushed = async () => {
  await Sentry.flush(2000);
};

describe("register", () => {
  it("starts the SDK of the server with the DSN, the environment and the release", () => {
    const options = Sentry.getClient()?.getOptions();
    expect(options?.dsn).toContain("public@127.0.0.1");
    expect(options?.environment).toBe("test-environment");
    expect(options?.release).toBe("test-release");
    expect(options?.tracesSampleRate).toBe(0);
  });
});

describe("onRequestError", () => {
  const renderError = () =>
    onRequestError(
      new Error("Rendering /p/buty failed for jan.kowalski@example.pl"),
      {
        path: `/p/buty?${QUERY}`,
        method: "GET",
        headers: {
          cookie: "store_session=v-session-cookie; store_cart=v-cart-cookie",
          authorization: "Bearer v-bearer-token-1234567890",
          "order-access-token": "v-order-access-token",
          "x-forwarded-for": "203.0.113.45",
          referer: `http://localhost:3000/szukaj?${QUERY}`,
          "user-agent": "Mozilla/5.0",
          accept: "text/html",
        },
      },
      { routerKind: "App Router", routePath: "/p/[slug]", routeType: "render", renderSource: "server-rendering", revalidateReason: undefined },
    );

  it("sends exactly one event for an error of rendering a page, without the query, the cookies, the user and the IP", async () => {
    await renderError();
    await flushed();

    const events = items("event");
    expect(events).toHaveLength(1);
    const event = events[0]!.payload as Sentry.Event;
    expect(event.release).toBe("test-release");
    expect(event.environment).toBe("test-environment");
    expect(event.exception?.values?.[0]?.value).toBe("Rendering /p/buty failed for [REDACTED]");
    expect(event.request?.headers).toEqual({ "user-agent": "Mozilla/5.0", accept: "text/html" });
    expect(event.request?.cookies).toBeUndefined();
    expect(event.contexts?.nextjs).toMatchObject({ request_path: "/p/buty", router_path: "/p/[slug]" });
    expect(event.user).toBeUndefined();
    const sent = JSON.stringify(envelopes);
    for (const value of [...PLANTED, "203.0.113.45"]) {
      expect(sent, `${value} was sent to Sentry`).not.toContain(value);
    }
  });

  it("does not turn console.error of a handled error into an event or a breadcrumb", async () => {
    const quiet = vi.spyOn(console, "error").mockImplementation(() => {});
    console.error("Checkout BFF failed with 502 for jan.kowalski@example.pl", new Error("upstream"));
    quiet.mockRestore();
    await flushed();
    expect(items("event")).toHaveLength(0);

    await renderError();
    await flushed();
    const [event] = items("event");
    expect((event!.payload as Sentry.Event).breadcrumbs?.some((breadcrumb) => breadcrumb.category === "console") ?? false).toBe(false);
  });
});

describe("an incoming trace (path 9)", () => {
  const incoming = {
    "sentry-trace": `${INCOMING_TRACE_ID}-0123456789abcdef-1`,
    baggage: `sentry-trace_id=${INCOMING_TRACE_ID},sentry-sampled=true,sentry-sample_rate=1,sentry-sample_rand=0.01,sentry-public_key=public`,
    traceparent: `00-${INCOMING_TRACE_ID}-0123456789abcdef-01`,
  };

  it("is not continued by a request to the server, which starts its own trace with its own sampling", async () => {
    let seen: string | undefined;
    let seenByNext: string | undefined;
    const store = await listen((_request, response) => {
      seen = Sentry.getTraceData()["sentry-trace"];
      seenByNext = getTracer().withPropagatedContext(_request.headers, () =>
        Sentry.startSpan({ name: "GET /p/[slug]", op: "http.server" }, () => Sentry.getTraceData()["sentry-trace"]),
      );
      response.writeHead(200, { "content-type": "application/json" }).end("{}");
    });

    const response = await createApiClient({ baseUrl: store }).GET("/api/v1/categories", { headers: incoming });
    expect(response.response.status).toBe(200);
    await flushed();

    expect(seen).toMatch(/^[0-9a-f]{32}-/);
    expect(seen).not.toContain(INCOMING_TRACE_ID);
    expect(seenByNext).not.toContain(INCOMING_TRACE_ID);
    expect(seenByNext).toMatch(/-0$/);
    expect(items("transaction")).toHaveLength(0);
  });
});

describe("outgoing requests (D7)", () => {
  it("carry sentry-trace and baggage to the API, direct and through Varnish, and never to Keycloak", async () => {
    await Sentry.startSpan({ name: "GET /checkout", op: "http.server", forceTransaction: true }, async () => {
      await createApiClient({ baseUrl: apiUrl }).GET("/api/v1/categories");
      await createApiClient({ baseUrl: catalogUrl }).GET("/api/v1/categories");
      await discoverOidc({ ...testConfig, keycloakInternalUrl: keycloakUrl }).catch(() => undefined);
    });

    expect(apiRequests).toHaveLength(2);
    for (const request of apiRequests) {
      expect(request.headers["sentry-trace"]).toMatch(/^[0-9a-f]{32}-[0-9a-f]{16}-0$/);
      expect(request.headers.baggage).toContain("sentry-trace_id=");
    }
    expect(keycloakRequests).toHaveLength(1);
    expect(keycloakRequests[0]!.url).toBe("/realms/shop/.well-known/openid-configuration");
    expect(keycloakRequests[0]!.headers["sentry-trace"]).toBeUndefined();
    expect(keycloakRequests[0]!.headers.baggage).toBeUndefined();
    expect(keycloakRequests[0]!.headers.traceparent).toBeUndefined();
  });
});
