import type { Breadcrumb, Event } from "@sentry/nextjs";
import { describe, expect, it } from "vitest";
import { filterBreadcrumb, filterEvent, mask, REDACTED } from "./filter";

/**
 * The filter of what the store sends to Sentry (spec: docs/features/sentry/spec.md, D4 and the threat model, paths 1, 2,
 * 4 and 6): no headers but three, no cookies, body, user or address of the client, no query strings, no console
 * breadcrumbs, no arguments of the frames, and the texts are masked with the patterns of the log masking of Alloy
 * (infra/k8s/argocd/apps/monitoring.yaml), the same as the filter of the API.
 */

const EMAIL = "jan.kowalski@example.pl";
const JWT =
  "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTYiLCJlbWFpbCI6ImphbkBleGFtcGxlLnBsIn0.c2lnbmF0dXJlLXNlY3JldA";
const QUERY =
  "q=jan.kowalski%40example.pl&discountCode=RABAT-SECRET&returnTo=%2Fkonto&code=v-oidc-code&state=v-oidc-state";
const PLANTED_IN_QUERY = ["jan.kowalski", "RABAT-SECRET", "%2Fkonto", "v-oidc-code", "v-oidc-state"];

const expectNoneOf = (text: string, planted: string[]) => {
  for (const value of planted) {
    expect(text, `${value} came out of the filter`).not.toContain(value);
  }
};

describe("filterEvent: the request (path 1)", () => {
  it("keeps only the method, the address without its query and the headers User-Agent, Content-Type and Accept", () => {
    const event: Event = {
      request: {
        method: "POST",
        url: `http://localhost:3000/checkout?${QUERY}#fragment`,
        query_string: QUERY,
        data: { email: EMAIL, address: "ul. Długa 5" },
        cookies: { store_session: "v-session-cookie", store_cart: "v-cart-cookie" },
        env: { REMOTE_ADDR: "203.0.113.45" },
        headers: {
          Authorization: `Bearer ${JWT}`,
          cookie: "store_session=v-session-cookie; store_cart=v-cart-cookie; store_order_confirmation=v-confirmation",
          "Order-Access-Token": "v-order-access-token",
          "X-Forwarded-For": "203.0.113.45",
          Referer: `http://localhost:3000/koszyk?${QUERY}`,
          "user-agent": "Mozilla/5.0",
          "Content-Type": "application/json",
          accept: "text/html",
        },
      },
    };

    expect(filterEvent(event).request).toEqual({
      method: "POST",
      url: "http://localhost:3000/checkout",
      headers: { "user-agent": "Mozilla/5.0", "Content-Type": "application/json", accept: "text/html" },
    });
  });

  it("masks an address in the path of the request", () => {
    const event: Event = { request: { method: "GET", url: `http://localhost:3000/szukaj/${EMAIL}` } };
    expect(filterEvent(event).request?.url).toBe(`http://localhost:3000/szukaj/${REDACTED}`);
  });
});

describe("filterEvent: the key of a guest cart in the paths (paths 1 and 2)", () => {
  // The identifier of a guest cart is the key of access to it (docs/features/cart/spec.md): it never leaves in a path.
  const CART = "3f2c9a1e-7b4d-4c8e-9a6f-1d2e3c4b5a69";
  const VARIANT = "0b9e8d7c-6a5b-4c3d-8e2f-1a0b9c8d7e6f";

  it("masks it in the address of the request and in the name of the transaction", () => {
    const event: Event = {
      type: "transaction",
      transaction: `GET /api/v1/guest-carts/${CART}/summary`,
      request: { method: "GET", url: `http://api/api/v1/guest-carts/${CART}/items/${VARIANT}?x=1` },
    };

    const filtered = filterEvent(event);

    expect(filtered.request?.url).toBe(`http://api/api/v1/guest-carts/${REDACTED}/items/${VARIANT}`);
    expect(filtered.transaction).toBe(`GET /api/v1/guest-carts/${REDACTED}/summary`);
  });

  it("masks it in the name and the data of an HTTP span and of the root span", () => {
    const event: Event = {
      type: "transaction",
      contexts: {
        trace: {
          trace_id: "771a43a4192642f0b136d5159a501700",
          span_id: "9c2a6db8c79068a2",
          op: "http.client",
          description: `DELETE http://api/api/v1/guest-carts/${CART}`,
          data: { "url.full": `http://api/api/v1/guest-carts/${CART}` },
        },
      },
      spans: [
        {
          span_id: "a1",
          trace_id: "t1",
          start_timestamp: 1,
          status: "ok",
          op: "http.client",
          description: `GET http://api/api/v1/guest-carts/${CART}/items?x=1`,
          data: {
            "http.url": `http://api/api/v1/guest-carts/${CART}/items`,
            "url.full": `http://api/api/v1/guest-carts/${CART}/items`,
            "url.path": `/api/v1/guest-carts/${CART}/items`,
            "http.target": `/api/v1/guest-carts/${CART}/items`,
            url: `http://api/api/v1/guest-carts/${CART}`,
            "otel.name": `GET /api/v1/guest-carts/${CART}`,
            "http.response.status_code": 200,
          },
        },
      ],
    };

    const filtered = filterEvent(event);

    expect(filtered.contexts?.trace?.description).toBe(`DELETE http://api/api/v1/guest-carts/${REDACTED}`);
    expect(filtered.contexts?.trace?.data).toEqual({ "url.full": `http://api/api/v1/guest-carts/${REDACTED}` });
    expect(filtered.spans?.[0]?.description).toBe(`GET http://api/api/v1/guest-carts/${REDACTED}/items`);
    expect(filtered.spans?.[0]?.data).toEqual({
      "http.url": `http://api/api/v1/guest-carts/${REDACTED}/items`,
      "url.full": `http://api/api/v1/guest-carts/${REDACTED}/items`,
      "url.path": `/api/v1/guest-carts/${REDACTED}/items`,
      "http.target": `/api/v1/guest-carts/${REDACTED}/items`,
      url: `http://api/api/v1/guest-carts/${REDACTED}`,
      "otel.name": `GET /api/v1/guest-carts/${REDACTED}`,
      "http.response.status_code": 200,
    });
  });

  it.each([
    ["http", "http", { method: "POST", url: `http://api/api/v1/guest-carts/${CART}/items` }],
    ["fetch", "http", { method: "GET", url: `http://api/api/v1/guest-carts/${CART}/summary` }],
    ["xhr", "http", { method: "PATCH", url: `http://api/api/v1/guest-carts/${CART}/items/${VARIANT}` }],
    ["navigation", "navigation", { from: `/api/v1/guest-carts/${CART}`, to: "/cart" }],
  ])("masks it in a %s breadcrumb", (category, type, data) => {
    const filtered = filterBreadcrumb({ category, type, message: `GET http://api/api/v1/guest-carts/${CART}`, data });
    expect(JSON.stringify(filtered)).not.toContain(CART);
    expect(filtered?.message).toBe(`GET http://api/api/v1/guest-carts/${REDACTED}`);
  });

  it("masks it in a text, but not the other segments of a path", () => {
    expect(mask(`Request to /api/v1/guest-carts/${CART}/items/${VARIANT} failed with status 404`)).toBe(
      `Request to /api/v1/guest-carts/${REDACTED}/items/${VARIANT} failed with status 404`,
    );
    expect(mask("POST http://api/api/v1/guest-carts")).toBe("POST http://api/api/v1/guest-carts");
    expect(mask("GET /api/v1/orders/0b9e8d7c-6a5b-4c3d-8e2f-1a0b9c8d7e6f/payment")).toBe(
      "GET /api/v1/orders/0b9e8d7c-6a5b-4c3d-8e2f-1a0b9c8d7e6f/payment",
    );
  });
});

describe("filterEvent: the commands of Redis (path 4)", () => {
  // The keys of Redis hold the address of the client (login rate limit) and the ids of sessions and logins: the spans
  // of Redis keep only the name of the command.
  type Span = NonNullable<Event["spans"]>[number];
  const span = (description: string, data: NonNullable<Span["data"]>): Span => ({
    span_id: "a1",
    trace_id: "t1",
    start_timestamp: 1,
    status: "ok",
    op: "db.query",
    description,
    data,
  });

  it("removes the arguments from the name and the statement of a span of Redis", () => {
    const event: Event = {
      type: "transaction",
      spans: [
        span("INCR login-rate:2001:db8:85a3:0::/64", {
          "db.system.name": "redis",
          "db.operation.name": "INCR",
          "db.query.text": "INCR login-rate:2001:db8:85a3:0::/64",
          "server.address": "redis",
          "server.port": 6379,
        }),
        span("GET session:abc", { "db.system": "redis", "db.statement": "GET session:abc" }),
        span("redis-SET", {
          "db.system.name": "redis",
          "db.operation.name": "SET",
          "db.query.text": 'SET login:v-login-id {"state":"v-state","codeVerifier":"v-verifier"} PX 600000',
          "cache.key": ["login:v-login-id"],
        }),
      ],
    };

    const filtered = filterEvent(event);

    expect(filtered.spans?.map(({ description, data }) => ({ description, data }))).toEqual([
      {
        description: "INCR",
        data: { "db.system.name": "redis", "db.operation.name": "INCR", "db.query.text": "INCR", "server.address": "redis", "server.port": 6379 },
      },
      { description: "GET", data: { "db.system": "redis", "db.statement": "GET" } },
      { description: "SET", data: { "db.system.name": "redis", "db.operation.name": "SET", "db.query.text": "SET" } },
    ]);
    expectNoneOf(JSON.stringify(filtered), ["2001:db8", "login-rate:", "session:abc", "v-login-id", "v-state", "v-verifier"]);
  });

  it("removes the arguments from a root span of Redis and the name of its transaction", () => {
    const event: Event = {
      type: "transaction",
      transaction: "GET session:abc",
      contexts: {
        trace: {
          trace_id: "771a43a4192642f0b136d5159a501700",
          span_id: "9c2a6db8c79068a2",
          op: "db.query",
          description: "GET session:abc",
          data: { "db.system.name": "redis", "db.operation.name": "GET", "db.query.text": "GET session:abc" },
        },
      },
    };

    const filtered = filterEvent(event);

    expect(filtered.transaction).toBe("GET");
    expect(filtered.contexts?.trace?.description).toBe("GET");
    expect(filtered.contexts?.trace?.data).toEqual({ "db.system.name": "redis", "db.operation.name": "GET", "db.query.text": "GET" });
  });

  it("keeps the statement of a span of another database", () => {
    const event: Event = {
      type: "transaction",
      spans: [span("SELECT id FROM cart WHERE id = $1", { "db.system.name": "postgresql", "db.query.text": "SELECT id FROM cart WHERE id = $1" })],
    };
    expect(filterEvent(event).spans?.[0]).toMatchObject({
      description: "SELECT id FROM cart WHERE id = $1",
      data: { "db.query.text": "SELECT id FROM cart WHERE id = $1" },
    });
  });
});

describe("filterEvent: identity (path 6)", () => {
  it("removes the user, his address and the extra data", () => {
    const event: Event = {
      user: { id: "customer-sub", email: EMAIL, ip_address: "203.0.113.45", username: "jankowalski" },
      extra: { order: { email: EMAIL } },
    };

    const filtered = filterEvent(event);

    expect(filtered.user).toBeUndefined();
    expect(filtered.extra).toBeUndefined();
  });
});

describe("filterEvent: query strings (path 2)", () => {
  it("removes the query from the name of a transaction", () => {
    const event: Event = { type: "transaction", transaction: `GET /auth/callback?${QUERY}` };
    expect(filterEvent(event).transaction).toBe("GET /auth/callback");
  });

  it("removes the query from the names and the data of HTTP spans, and the data about headers, cookies and bodies", () => {
    const event: Event = {
      type: "transaction",
      spans: [
        {
          span_id: "a1",
          trace_id: "t1",
          start_timestamp: 1,
          status: "ok",
          op: "http.client",
          description: `GET http://api/api/v1/cart?${QUERY}`,
          data: {
            "http.url": `http://api/api/v1/cart?${QUERY}`,
            "url.full": `http://api/api/v1/cart?${QUERY}#top`,
            "url.query": `?${QUERY}`,
            "http.query": `?${QUERY}`,
            "http.fragment": "top",
            "http.request.method": "GET",
            "http.request.header.authorization": `Bearer ${JWT}`,
            "http.request.header.cookie": "store_session=v-session-cookie",
            "http.response.body.data": `{"email":"${EMAIL}"}`,
            "http.response.status_code": 200,
            "client.address": "203.0.113.45",
          },
        },
      ],
    };

    const [span] = filterEvent(event).spans!;

    expect(span!.description).toBe("GET http://api/api/v1/cart");
    expect(span!.data).toEqual({
      "http.url": "http://api/api/v1/cart",
      "url.full": "http://api/api/v1/cart",
      "http.request.method": "GET",
      "http.response.status_code": 200,
    });
  });

  it("filters the root span of a transaction in its trace context: no query, no headers, no client", () => {
    const event: Event = {
      type: "transaction",
      transaction: "GET /p/[slug]",
      contexts: {
        trace: {
          trace_id: "771a43a4192642f0b136d5159a501700",
          span_id: "9c2a6db8c79068a2",
          op: "http.server",
          data: {
            "http.target": `/p/buty?${QUERY}`,
            "url.full": `http://localhost:3000/p/buty?${QUERY}`,
            "http.route": "/p/[slug]",
            "http.request.header.cookie": "store_session=v-session-cookie",
            "http.request.header.user_agent": "Mozilla/5.0",
            "http.client_ip": "203.0.113.45",
            "net.peer.ip": "203.0.113.45",
          },
        },
      },
    };

    expect(filterEvent(event).contexts?.trace).toEqual({
      trace_id: "771a43a4192642f0b136d5159a501700",
      span_id: "9c2a6db8c79068a2",
      op: "http.server",
      data: { "http.target": "/p/buty", "url.full": "http://localhost:3000/p/buty", "http.route": "/p/[slug]" },
    });
  });

  it("removes the query from the paths in the other contexts and masks their texts", () => {
    const event: Event = {
      contexts: {
        nextjs: { request_path: `/szukaj?${QUERY}`, router_kind: "App Router", router_path: "/szukaj", route_type: "render" },
        checkout: { note: `sent to ${EMAIL}` },
      },
    };

    const filtered = filterEvent(event);

    expect(filtered.contexts?.nextjs).toEqual({
      request_path: "/szukaj",
      router_kind: "App Router",
      router_path: "/szukaj",
      route_type: "render",
    });
    expect(filtered.contexts?.checkout).toEqual({ note: `sent to ${REDACTED}` });
  });

  it("removes the query from the addresses of the frames (an inline script of a page)", () => {
    const event: Event = {
      exception: {
        values: [
          {
            type: "TypeError",
            value: "x is undefined",
            stacktrace: { frames: [{ filename: `http://localhost:3000/checkout?${QUERY}`, abs_path: `http://localhost:3000/checkout?${QUERY}`, function: "onSubmit" }] },
          },
        ],
      },
    };

    const [frame] = filterEvent(event).exception!.values![0]!.stacktrace!.frames!;

    expect(frame).toEqual({ filename: "http://localhost:3000/checkout", abs_path: "http://localhost:3000/checkout", function: "onSubmit" });
  });
});

describe("filterEvent: texts (path 4)", () => {
  it("masks the values of the exceptions and removes the arguments of the frames", () => {
    const event: Event = {
      exception: {
        values: [
          {
            type: "Error",
            value: `Failing row contains (1, ${EMAIL}, Jan Kowalski).`,
            stacktrace: { frames: [{ function: "placeOrder", vars: { email: EMAIL } }] },
          },
          { type: "Error", value: `Token Bearer ${JWT} was refused` },
        ],
      },
      threads: { values: [{ id: 1, stacktrace: { frames: [{ function: "handle", vars: { password: "Hunter2" } }] } }] },
    };

    const filtered = filterEvent(event);

    expect(filtered.exception?.values?.[0]?.value).toBe(`Failing row contains (${REDACTED}).`);
    expect(filtered.exception?.values?.[1]?.value).toBe(`Token Bearer ${REDACTED} was refused`);
    expect(filtered.exception?.values?.[0]?.stacktrace?.frames?.[0]).toEqual({ function: "placeOrder" });
    expect(filtered.threads?.values?.[0]?.stacktrace?.frames?.[0]).toEqual({ function: "handle" });
  });

  it("masks the message, the log entry and the tags", () => {
    const event: Event = {
      message: `The order of ${EMAIL} failed`,
      logentry: { message: "The order of %s failed", params: [EMAIL] },
      tags: { note: `password=Hunter2`, turbopack: true },
    };

    const filtered = filterEvent(event);

    expect(filtered.message).toBe(`The order of ${REDACTED} failed`);
    expect(filtered.logentry).toEqual({ message: "The order of %s failed", params: [REDACTED] });
    expect(filtered.tags).toEqual({ note: `password=${REDACTED}`, turbopack: true });
  });

  it("masks the descriptions of spans that are not HTTP", () => {
    const event: Event = {
      type: "transaction",
      spans: [{ span_id: "a1", trace_id: "t1", start_timestamp: 1, status: "ok", data: {}, op: "cache.get", description: `session:${JWT}` }],
    };
    expect(filterEvent(event).spans?.[0]?.description).toBe(`session:${REDACTED}`);
  });

  it("filters the breadcrumbs of an event", () => {
    const event: Event = {
      breadcrumbs: [
        { category: "console", level: "error", message: `Checkout failed for ${EMAIL}` },
        { category: "fetch", type: "http", data: { method: "GET", url: `http://api/api/v1/cart?${QUERY}`, status_code: 200 } },
      ],
    };

    expect(filterEvent(event).breadcrumbs).toEqual([
      { category: "fetch", type: "http", data: { method: "GET", url: "http://api/api/v1/cart", status_code: 200 } },
    ]);
  });
});

describe("filterBreadcrumb", () => {
  it("drops console breadcrumbs, of the browser and of Node", () => {
    expect(filterBreadcrumb({ category: "console", level: "log", message: `cart of ${EMAIL}`, data: { arguments: [EMAIL] } })).toBeNull();
    expect(filterBreadcrumb({ category: "console", level: "error", message: "Order BFF failed" })).toBeNull();
  });

  it.each([
    ["fetch", { method: "POST", url: `/api/checkout?${QUERY}`, status_code: 502, request_body_size: 120 }, { method: "POST", url: "/api/checkout", status_code: 502 }],
    ["xhr", { method: "GET", url: `http://localhost:3000/api/cart?${QUERY}` }, { method: "GET", url: "http://localhost:3000/api/cart" }],
    ["navigation", { from: `/szukaj?${QUERY}`, to: `/auth/callback?${QUERY}` }, { from: "/szukaj", to: "/auth/callback" }],
  ])("removes the query from the addresses of a %s breadcrumb", (category, data, expected) => {
    expect(filterBreadcrumb({ category, data })?.data).toEqual(expected);
  });

  it("removes the query from the message of an HTTP breadcrumb of Node and its data about the query, headers and cookies", () => {
    const breadcrumb: Breadcrumb = {
      category: "http",
      type: "http",
      message: `GET http://api/api/v1/me?${QUERY}`,
      data: {
        url: `http://api/api/v1/me?${QUERY}`,
        "http.query": `?${QUERY}`,
        "http.fragment": "top",
        "http.request.header.authorization": `Bearer ${JWT}`,
        "http.request.cookies": "store_session=v-session-cookie",
        status_code: 401,
        note: "password=Hunter2",
      },
    };

    const filtered = filterBreadcrumb(breadcrumb);

    expect(filtered?.message).toBe("GET http://api/api/v1/me");
    expect(filtered?.data).toEqual({ url: "http://api/api/v1/me", status_code: 401, note: `password=${REDACTED}` });
  });

  it("masks the message of another breadcrumb", () => {
    expect(filterBreadcrumb({ category: "ui.click", message: `button[data-email="${EMAIL}"]` })?.message).toBe(
      `button[data-email="${REDACTED}"]`,
    );
  });
});

describe("mask", () => {
  it("leaves Polish text and a query string without secrets alone", () => {
    const text = "Nie udało się wczytać katalogu: /szukaj?sort=price_asc&page=2 (zażółć gęślą jaźń)";
    expect(mask(text)).toBe(text);
  });

  it("masks the query parameters of secrets in a text (code, token, session) but keeps the others", () => {
    expect(mask(`redirect to /auth/callback?${QUERY}`)).toBe(
      `redirect to /auth/callback?q=${REDACTED}&discountCode=RABAT-SECRET&returnTo=%2Fkonto&code=${REDACTED}&state=v-oidc-state`,
    );
  });

  it.each([
    ["an e-mail with Polish letters", "Mail do zażółć.gęślą@przyklad.pl nie wyszedł", `Mail do ${REDACTED} nie wyszedł`],
    ["an e-mail written with %40", "customer anna%40example.com", `customer ${REDACTED}`],
    ["a JWT", `id_token ${JWT}`, `id_token ${REDACTED}`],
    ["a Bearer token", "Authorization failed: bearer abc123DEF456ghi789", `Authorization failed: bearer ${REDACTED}`],
    ["a password in an address", "redis://:RedisP4ssw0rd@redis:6379 refused", `redis://:${REDACTED}@redis:6379 refused`],
    ["a key of a secret in JSON", '{"refresh_token":"v-refresh","status":400}', `{"refresh_token":"${REDACTED}","status":400}`],
    ["a key of a secret in an escaped JSON", '{\\"access_token\\":\\"v-escaped\\"}', `{\\"access_token\\":\\"${REDACTED}\\"}`],
    ["a number under a key of a secret", '{"password":12345678}', `{"password":${REDACTED}}`],
    ["a list of secrets", '{"cookie":["a=1","shop_session=zzSECRET"]}', `{"cookie":["${REDACTED}"]}`],
    ["the parameters of DBAL", 'with params ["jan@example.pl", 12]', `with params [${REDACTED}]`],
    ["a key of PostgreSQL", "duplicate key Key (email)=(jan@example.pl) already exists", `duplicate key Key (email)=(${REDACTED}) already exists`],
    ["a row of PostgreSQL cut before its end", "Failing row contains (1, Jan Kowalski, ul. Dłu", `Failing row contains (${REDACTED}`],
    ["a DETAIL line of PostgreSQL", "Unique violation\nDETAIL:  Key (email)=(Jan (x) Kowalski) already exists.\npassword=Hunter2", `Unique violation\nDETAIL:  ${REDACTED}\npassword=${REDACTED}`],
    ["an IPv4 address", "from 203.0.113.45 port 1", `from ${REDACTED} port 1`],
    ["an IPv6 address", "from 2001:db8:85a3::8a2e:370:7334 and ::1", `from ${REDACTED} and ${REDACTED}`],
  ])("masks %s", (_name, text, expected) => {
    expect(mask(text)).toBe(expected);
  });

  it("masks a long text without separators in linear time", () => {
    const started = performance.now();
    const masked = mask(`${"a".repeat(200_000)} ${"b@".repeat(50_000)} ${"x=".repeat(50_000)}`);
    expect(performance.now() - started).toBeLessThan(1_000);
    expect(masked.length).toBeGreaterThan(0);
  });
});

/**
 * The lines of the corpus of the log masking (infra/k8s/tests/log-corpus: store.log, and api.log and postgres.log
 * because a message of the API reaches the store in its errors; the container of the store sees only apps/store, so
 * they are copied here unchanged, like in the test of the filter of the API): what is planted in them does not come
 * out, what is needed to diagnose stays.
 */
const CORPUS: Array<[string, string, string[], string[]]> = [
  [
    "store: the callback of the login",
    "GET /auth/callback?code=v-code-store&state=xyz 302 in 45ms",
    ["v-code-store"],
    ["GET /auth/callback?", "302 in 45ms"],
  ],
  [
    "store: an error of a call to the API",
    "⨯ Error: Request to the API failed with status 401 for jan.kowalski@example.pl from 203.0.113.45 (Authorization: Bearer abc123DEF456ghi789)",
    ["jan.kowalski", "example.pl", "203.0.113.45", "abc123DEF456ghi789"],
    ["failed with status 401"],
  ],
  [
    "store: an unhandled error with cookies",
    'Unhandled error for customer anna%40example.com: fetch failed {"cookie":"PHPSESSID=7c5d2e9f1a3b4c6d8e0f; shop_session=zzTOPSECRETsession","session":"v-session-store"}',
    ["anna%40", "example.com", "7c5d2e9f1a3b4c6d8e0f", "zzTOPSECRETsession", "v-session-store"],
    ["fetch failed"],
  ],
  [
    "api: a Doctrine exception with parameters and DETAIL",
    '{"message":"An exception occurred while executing a statement: INSERT INTO customer (email, name, address, total) VALUES (?, ?, ?, ?) with params [\\"jan.kowalski@example.pl\\", \\"Jan Kowalski\\", \\"ul. Długa 5, 00-001 Warszawa\\", 129.99]:\\n\\nSQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \\"uniq_customer_email\\"\\nDETAIL:  Key (email)=(jan.kowalski@example.pl) already exists.","context":{"exception":{"class":"Doctrine\\\\DBAL\\\\Exception\\\\UniqueConstraintViolationException"}},"level":400,"level_name":"ERROR","channel":"doctrine","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
    ["jan.kowalski", "kowalski@", "example.pl", "Jan Kowalski", "ul. Długa 5", "00-001 Warszawa", "129.99"],
    ['"level_name":"ERROR"', '"channel":"doctrine"', "UniqueConstraintViolationException", "duplicate key value violates unique constraint", "SQLSTATE[23505]", "Key (email)="],
  ],
  [
    "api: the secrets of an authentication",
    `{"message":"Authentication failed","context":{"username":"jankowalski_login","password":"Hunter2-S3cretPw","passwd":"v-passwd-1","secret":"v-secret-1","token":"v-token-1","access_token":"v-access-token-1","refresh_token":"v-refresh-token-1","id_token":"${JWT}","code":"v-code-1","session":"v-session-1","client_secret":"v-client-secret-1","api_key":"v-api-key-1","authorization":"Bearer abc123DEF456ghi789","cookie":"PHPSESSID=7c5d2e9f1a3b4c6d8e0f; shop_session=zzTOPSECRETsession"},"level":300,"level_name":"WARNING","channel":"security","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}`,
    ["jankowalski_login", "Hunter2-S3cretPw", "v-passwd-1", "v-secret-1", "v-token-1", "v-access-token-1", "v-refresh-token-1", JWT, "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9", "v-code-1", "v-session-1", "v-client-secret-1", "v-api-key-1", "abc123DEF456ghi789", "7c5d2e9f1a3b4c6d8e0f", "zzTOPSECRETsession"],
    ['"message":"Authentication failed"', '"channel":"security"', '"level_name":"WARNING"'],
  ],
  [
    "api: a request with secrets in the query, addresses and headers",
    '{"message":"Request finished","context":{"method":"GET","uri":"/api/v1/orders?page=2&code=v-code-query&token=v-token-query&access_token=v-at-query&api_key=v-ak-query&session=v-session-query&client_secret=v-cs-query&refresh_token=v-rt-query&id_token=v-it-query&password=v-pw-query&passwd=v-passwd-query&secret=v-secret-query&authorization=v-auth-query&cookie=v-cookie-query","status_code":403,"client_ip":"203.0.113.45","forwarded_for":"198.51.100.7, 2001:db8:85a3::8a2e:370:7334, fe80::1ff:fe23:4567:890a, ::1","headers":{"Authorization":["Bearer abc123DEF456ghi789"],"Cookie":["PHPSESSID=7c5d2e9f1a3b4c6d8e0f; shop_session=zzTOPSECRETsession"],"Set-Cookie":["shop_session=zzSETCOOKIEsession; Path=/; HttpOnly"],"Proxy-Authorization":["Basic dXNlcjpwYXNzd29yZDEyMw=="],"User-Agent":["Mozilla/5.0"]},"customer":"anna%40example.com"},"level":400,"level_name":"ERROR","channel":"request","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
    ["v-code-query", "v-token-query", "v-at-query", "v-ak-query", "v-session-query", "v-cs-query", "v-rt-query", "v-it-query", "v-pw-query", "v-passwd-query", "v-secret-query", "v-auth-query", "v-cookie-query", "203.0.113.45", "198.51.100.7", "2001:db8:85a3", "8a2e:370:7334", "fe80::", "1ff:fe23", "abc123DEF456ghi789", "7c5d2e9f1a3b4c6d8e0f", "zzTOPSECRETsession", "zzSETCOOKIEsession", "dXNlcjpwYXNzd29yZDEyMw==", "anna%40", "example.com"],
    ['"message":"Request finished"', '"method":"GET"', '"uri":"/api/v1/orders?page=2&', '"status_code":403', '"channel":"request"'],
  ],
  [
    "api: an e-mail with Polish letters and a password in an address",
    '{"message":"Sending the order mail to zażółć.gęślą@przyklad.pl failed: smtp://app:SmtpP4ssw0rd@mailpit:1025 refused","context":{},"level":400,"level_name":"ERROR","channel":"mailer","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
    ["gęślą", "przyklad.pl", "SmtpP4ssw0rd"],
    ['"channel":"mailer"', "failed: smtp://"],
  ],
  [
    "api: tokens in an escaped JSON string",
    '{"message":"Keycloak returned 400: {\\"error\\":\\"invalid_grant\\",\\"access_token\\":\\"v-escaped-token\\",\\"refresh_token\\":\\"v-escaped-refresh\\",\\"status\\":400}","context":{},"level":400,"level_name":"ERROR","channel":"http_client","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
    ["v-escaped-token", "v-escaped-refresh"],
    ['"channel":"http_client"', "Keycloak returned 400", "invalid_grant"],
  ],
  [
    "api: lists of values, keys in camelCase and a number",
    '{"message":"Request headers","context":{"headers":{"Set-Cookie":["a=1; Path=/","PHPSESSID=abc999secret; Path=/"],"Cookie":["x=1","shop_session=cookie2secretvalue"],"Authorization":["Bearer short1","Basic Zm9vOmJhcjJzZWNyZXQ="]},"accessToken":"camelAccessTokenVal","clientSecret":"camelClientSecretVal","newPassword":"camelNewPasswordVal","sessionId":"camelSessionIdVal","password":12345678,"status_code":429},"level":300,"level_name":"WARNING","channel":"request","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
    ["abc999secret", "cookie2secretvalue", "Zm9vOmJhcjJzZWNyZXQ=", "short1", "camelAccessTokenVal", "camelClientSecretVal", "camelNewPasswordVal", "camelSessionIdVal", "12345678"],
    ['"message":"Request headers"', '"status_code":429', '"channel":"request"'],
  ],
  [
    "postgres: DETAIL of a unique violation",
    "2026-11-11 10:00:00.123 UTC [87] DETAIL:  Key (email)=(jan.kowalski@example.pl) already exists.",
    ["jan.kowalski", "kowalski@", "example.pl", "Key (email)=", "already exists"],
    ["[87] DETAIL:"],
  ],
  [
    "postgres: STATEMENT",
    "2026-11-11 10:00:00.123 UTC [87] STATEMENT:  INSERT INTO customer (email, name) VALUES ('jan.kowalski@example.pl', 'Jan Kowalski')",
    ["INSERT INTO customer (email, name) VALUES", "jan.kowalski", "Jan Kowalski"],
    ["STATEMENT:"],
  ],
  [
    "postgres: a connection from an IPv6 address",
    "2026-11-11 10:00:02.000 UTC [89] LOG:  connection received: host=2001:db8:85a3::8a2e:370:7334 port=51235",
    ["2001:db8:85a3", "8a2e:370:7334"],
    ["LOG:  connection received: host="],
  ],
];

describe("mask: the corpus of the log masking", () => {
  it.each(CORPUS)("%s", (_name, line, planted, kept) => {
    const masked = mask(line);
    expectNoneOf(masked, planted);
    expect(masked).not.toMatch(/(?<![:\w])::1(?![\w:])/);
    for (const fragment of kept) {
      expect(masked, `${fragment} was masked`).toContain(fragment);
    }
  });

  it("an exception with a line of the corpus loses what is planted in it", () => {
    const [, line, planted] = CORPUS[1]!;
    const event: Event = { exception: { values: [{ type: "Error", value: line }] }, message: line };
    const filtered = filterEvent(event);
    expectNoneOf(filtered.exception!.values![0]!.value!, planted);
    expectNoneOf(filtered.message!, planted);
  });

  it("a query of the planted values does not come out of any address", () => {
    const event: Event = { request: { url: `http://localhost:3000/?${QUERY}` }, transaction: `/szukaj?${QUERY}` };
    const filtered = filterEvent(event);
    expectNoneOf(JSON.stringify(filtered), PLANTED_IN_QUERY);
  });
});
