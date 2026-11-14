import type { Breadcrumb, Event } from "@sentry/vue";
import { describe, expect, it } from "vitest";
import { filterBreadcrumb, filterEvent, mask, REDACTED } from "@/observability/filter";

/**
 * The filter of what the panel sends to Sentry (spec: docs/features/sentry/spec.md, D4, D9 and the threat model, paths 1,
 * 2, 4, 5 and 6): no headers but three, no cookies, body or address of the client, from the user only the id, no query
 * strings and fragments (the `q` of the order search is an e-mail, `code` and `state` come from /auth/callback), no
 * console breadcrumbs, no arguments of the frames, and the texts are masked with the patterns of the log masking of
 * Alloy (infra/k8s/argocd/apps/monitoring.yaml), the same as the filters of the store and the API.
 */

const EMAIL = "jan.kowalski@example.pl";
const JWT =
  "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTYiLCJlbWFpbCI6ImphbkBleGFtcGxlLnBsIn0.c2lnbmF0dXJlLXNlY3JldA";
const SUB = "3f2c9a1e-7b4d-4c8e-9a6f-1d2e3c4b5a69";
const SEARCH = `q=${EMAIL.replace("@", "%40")}&status=new`;
const CALLBACK = "code=v-oidc-code&state=v-oidc-state&session_state=v-session-state&iss=http%3A%2F%2Fauth.test";
// The query of the store test of the masks of query parameters (the masks are the same).
const QUERY =
  "q=jan.kowalski%40example.pl&discountCode=RABAT-SECRET&returnTo=%2Fkonto&code=v-oidc-code&state=v-oidc-state";
const PLANTED_IN_QUERY = ["jan.kowalski", "v-oidc-code", "v-oidc-state", "v-session-state", "auth.test"];

const expectNoneOf = (text: string, planted: string[]) => {
  for (const value of planted) {
    expect(text, `${value} came out of the filter`).not.toContain(value);
  }
};

type Span = NonNullable<Event["spans"]>[number];
const span = (op: string, description: string, data: NonNullable<Span["data"]>): Span => ({
  span_id: "a1",
  trace_id: "t1",
  start_timestamp: 1,
  status: "ok",
  op,
  description,
  data,
});

describe("filterEvent: the request (path 1)", () => {
  it("keeps only the method, the address without its query and fragment and the headers User-Agent, Content-Type and Accept", () => {
    const event: Event = {
      request: {
        method: "GET",
        url: `https://admin.example.test/orders?${SEARCH}#top`,
        query_string: SEARCH,
        data: { email: EMAIL, address: "ul. Długa 5" },
        cookies: { KEYCLOAK_SESSION: "v-keycloak-cookie" },
        env: { REMOTE_ADDR: "203.0.113.45" },
        headers: {
          Authorization: `Bearer ${JWT}`,
          cookie: "KEYCLOAK_SESSION=v-keycloak-cookie; other=1",
          "Order-Access-Token": "v-order-access-token",
          Referer: `https://admin.example.test/auth/callback?${CALLBACK}`,
          "user-agent": "Mozilla/5.0",
          "Content-Type": "application/json",
          accept: "text/html",
        },
      },
    };

    expect(filterEvent(event).request).toEqual({
      method: "GET",
      url: "https://admin.example.test/orders",
      headers: { "user-agent": "Mozilla/5.0", "Content-Type": "application/json", accept: "text/html" },
    });
  });

  it("masks an address in the path of the request", () => {
    const event: Event = { request: { method: "GET", url: `https://admin.example.test/orders/${EMAIL}` } };
    expect(filterEvent(event).request?.url).toBe(`https://admin.example.test/orders/${REDACTED}`);
  });
});

describe("filterEvent: query strings and fragments (path 2)", () => {
  it("removes the query of the order search and of /auth/callback from the name of a transaction", () => {
    expect(filterEvent({ type: "transaction", transaction: `/orders?${SEARCH}` }).transaction).toBe("/orders");
    expect(filterEvent({ type: "transaction", transaction: `/auth/callback?${CALLBACK}#x` }).transaction).toBe("/auth/callback");
  });

  it("removes the query and the fragment from the names and the data of the spans of fetch, XHR, navigation and resources", () => {
    const event: Event = {
      type: "transaction",
      spans: [
        span("http.client", `GET https://api.example.test/api/v1/admin/orders?${SEARCH}`, {
          url: `https://api.example.test/api/v1/admin/orders?${SEARCH}`,
          "http.url": `https://api.example.test/api/v1/admin/orders?${SEARCH}`,
          "url.full": `https://api.example.test/api/v1/admin/orders?${SEARCH}#top`,
          "url.path": "/api/v1/admin/orders",
          "url.query": `?${SEARCH}`,
          "http.query": `?${SEARCH}`,
          "http.fragment": "top",
          "url.fragment": "top",
          "http.method": "GET",
          "http.response.status_code": 200,
          "server.address": "api.example.test",
          "sentry.op": "http.client",
        }),
        span("resource.fetch", `https://api.example.test/api/v1/admin/orders?${SEARCH}`, {
          "http.response_transfer_size": 120,
          "http.decoded_response_content_length": 100,
        }),
        span("navigation", `https://admin.example.test/orders?${SEARCH}`, { "browser.name": "Chrome" }),
      ],
    };

    const [fetched, resource, navigation] = filterEvent(event).spans!;

    expect(fetched!.description).toBe("GET https://api.example.test/api/v1/admin/orders");
    expect(fetched!.data).toEqual({
      url: "https://api.example.test/api/v1/admin/orders",
      "http.url": "https://api.example.test/api/v1/admin/orders",
      "url.full": "https://api.example.test/api/v1/admin/orders",
      "url.path": "/api/v1/admin/orders",
      "http.method": "GET",
      "http.response.status_code": 200,
      "server.address": "api.example.test",
      "sentry.op": "http.client",
    });
    expect(resource!.description).toBe("https://api.example.test/api/v1/admin/orders");
    expect(navigation!.description).toBe("https://admin.example.test/orders");
    expectNoneOf(JSON.stringify(filterEvent(event)), PLANTED_IN_QUERY);
  });

  it("filters the root span of a transaction in its trace context: no query, no fragment, no headers", () => {
    const event: Event = {
      type: "transaction",
      transaction: "/auth/callback",
      contexts: {
        trace: {
          trace_id: "771a43a4192642f0b136d5159a501700",
          span_id: "9c2a6db8c79068a2",
          op: "pageload",
          description: `/auth/callback?${CALLBACK}`,
          data: {
            "sentry.op": "pageload",
            url: `https://admin.example.test/auth/callback?${CALLBACK}`,
            "url.full": `https://admin.example.test/auth/callback?${CALLBACK}`,
            "http.query": `?${CALLBACK}`,
            "http.request.header.cookie": "KEYCLOAK_SESSION=v-keycloak-cookie",
          },
        },
      },
    };

    expect(filterEvent(event).contexts?.trace).toEqual({
      trace_id: "771a43a4192642f0b136d5159a501700",
      span_id: "9c2a6db8c79068a2",
      op: "pageload",
      description: "/auth/callback",
      data: { "sentry.op": "pageload", url: "https://admin.example.test/auth/callback", "url.full": "https://admin.example.test/auth/callback" },
    });
  });

  it("removes the query and the fragment from the addresses of the frames", () => {
    const event: Event = {
      exception: {
        values: [
          {
            type: "TypeError",
            value: "x is undefined",
            stacktrace: {
              frames: [{ filename: `https://admin.example.test/auth/callback?${CALLBACK}`, abs_path: `https://admin.example.test/orders?${SEARCH}#a`, function: "onSubmit" }],
            },
          },
        ],
      },
    };

    const [frame] = filterEvent(event).exception!.values![0]!.stacktrace!.frames!;

    expect(frame).toEqual({ filename: "https://admin.example.test/auth/callback", abs_path: "https://admin.example.test/orders", function: "onSubmit" });
  });

  it.each([
    ["fetch", { method: "GET", url: `https://api.example.test/api/v1/admin/orders?${SEARCH}`, status_code: 200 }, { method: "GET", url: "https://api.example.test/api/v1/admin/orders", status_code: 200 }],
    ["xhr", { method: "GET", url: `https://api.example.test/api/v1/admin/me?x=1#a` }, { method: "GET", url: "https://api.example.test/api/v1/admin/me" }],
    ["navigation", { from: `/orders?${SEARCH}`, to: `/auth/callback?${CALLBACK}` }, { from: "/orders", to: "/auth/callback" }],
  ])("removes the query from the addresses of a %s breadcrumb", (category, data, expected) => {
    const filtered = filterBreadcrumb({ category, type: category === "navigation" ? "navigation" : "http", data });
    expect(filtered?.data).toEqual(expected);
  });

  it("removes the query from the message and the data of an HTTP breadcrumb, with the data about headers and bodies", () => {
    const breadcrumb: Breadcrumb = {
      category: "http",
      type: "http",
      message: `GET https://api.example.test/api/v1/admin/orders?${SEARCH}`,
      data: {
        url: `https://api.example.test/api/v1/admin/orders?${SEARCH}`,
        "http.query": `?${SEARCH}`,
        "http.request.header.authorization": `Bearer ${JWT}`,
        "http.response.body.data": `{"email":"${EMAIL}"}`,
        status_code: 401,
        note: "password=Hunter2",
      },
    };

    const filtered = filterBreadcrumb(breadcrumb);

    expect(filtered?.message).toBe("GET https://api.example.test/api/v1/admin/orders");
    expect(filtered?.data).toEqual({ url: "https://api.example.test/api/v1/admin/orders", status_code: 401, note: `password=${REDACTED}` });
  });

  it("a query of the planted values does not come out of any address of an event", () => {
    const event: Event = {
      request: { url: `https://admin.example.test/auth/callback?${CALLBACK}` },
      transaction: `/orders?${SEARCH}`,
      breadcrumbs: [{ category: "navigation", data: { from: `/orders?${SEARCH}`, to: `/auth/callback?${CALLBACK}` } }],
    };
    expectNoneOf(JSON.stringify(filterEvent(event)), PLANTED_IN_QUERY);
  });
});

describe("filterEvent: the arguments of the frames (path 5)", () => {
  it("removes the variables of the frames of exceptions and threads", () => {
    const event: Event = {
      exception: { values: [{ type: "Error", value: "x", stacktrace: { frames: [{ function: "search", vars: { q: EMAIL } }] } }] },
      threads: { values: [{ id: 1, stacktrace: { frames: [{ function: "handle", vars: { password: "Hunter2" } }] } }] },
    };

    const filtered = filterEvent(event);

    expect(filtered.exception?.values?.[0]?.stacktrace?.frames?.[0]).toEqual({ function: "search" });
    expect(filtered.threads?.values?.[0]?.stacktrace?.frames?.[0]).toEqual({ function: "handle" });
  });
});

describe("filterEvent: identity (path 6)", () => {
  it("keeps only the id of the user: no login, e-mail, name or address", () => {
    const event: Event = {
      user: { id: SUB, email: EMAIL, ip_address: "203.0.113.45", username: "jankowalski", segment: "admin" },
    };
    expect(filterEvent(event).user).toEqual({ id: SUB });
  });

  it("drops a user without a text id, and the extra data", () => {
    expect(filterEvent({ user: { ip_address: "{{auto}}", email: EMAIL } }).user).toBeUndefined();
    expect(filterEvent({ user: { id: 12 as unknown as string } }).user).toBeUndefined();
    expect(filterEvent({ user: { id: SUB }, extra: { order: { email: EMAIL } } }).extra).toBeUndefined();
  });

  it("filters a transaction the same way", () => {
    const event: Event = { type: "transaction", user: { id: SUB, email: EMAIL, ip_address: "203.0.113.45" } };
    expect(filterEvent(event).user).toEqual({ id: SUB });
  });
});

describe("filterEvent: texts (path 4)", () => {
  it("masks the values of the exceptions", () => {
    const event: Event = {
      exception: {
        values: [
          { type: "Error", value: `Failing row contains (1, ${EMAIL}, Jan Kowalski).` },
          { type: "Error", value: `Token Bearer ${JWT} was refused` },
        ],
      },
    };

    const filtered = filterEvent(event);

    expect(filtered.exception?.values?.[0]?.value).toBe(`Failing row contains (${REDACTED}).`);
    expect(filtered.exception?.values?.[1]?.value).toBe(`Token Bearer ${REDACTED} was refused`);
  });

  it("masks the message, the log entry, the tags and the other contexts", () => {
    const event: Event = {
      message: `The order of ${EMAIL} failed`,
      logentry: { message: "The order of %s failed", params: [EMAIL] },
      tags: { note: "password=Hunter2", transaction: "orders" },
      contexts: { browser: { name: "Chrome", version: "142.0.0" }, order: { note: `sent to ${EMAIL}` } },
    };

    const filtered = filterEvent(event);

    expect(filtered.message).toBe(`The order of ${REDACTED} failed`);
    expect(filtered.logentry).toEqual({ message: "The order of %s failed", params: [REDACTED] });
    expect(filtered.tags).toEqual({ note: `password=${REDACTED}`, transaction: "orders" });
    expect(filtered.contexts?.browser).toEqual({ name: "Chrome", version: "142.0.0" });
    expect(filtered.contexts?.order).toEqual({ note: `sent to ${REDACTED}` });
  });

  it("masks the descriptions of spans that are not HTTP", () => {
    const event: Event = { type: "transaction", spans: [span("ui.vue.mount", `component ${EMAIL}`, {})] };
    expect(filterEvent(event).spans?.[0]?.description).toBe(`component ${REDACTED}`);
  });

  it("filters the breadcrumbs of an event: console ones go, the rest is masked", () => {
    const event: Event = {
      breadcrumbs: [
        { category: "console", level: "error", message: `Search failed for ${EMAIL}` },
        { category: "ui.click", message: `button[data-email="${EMAIL}"]` },
        { category: "fetch", type: "http", data: { method: "GET", url: `https://api.example.test/api/v1/admin/orders?${SEARCH}`, status_code: 200 } },
      ],
    };

    expect(filterEvent(event).breadcrumbs).toEqual([
      { category: "ui.click", message: `button[data-email="${REDACTED}"]` },
      { category: "fetch", type: "http", data: { method: "GET", url: "https://api.example.test/api/v1/admin/orders", status_code: 200 } },
    ]);
  });
});

describe("filterBreadcrumb", () => {
  it("drops console breadcrumbs", () => {
    expect(filterBreadcrumb({ category: "console", level: "log", message: `search ${EMAIL}`, data: { arguments: [EMAIL] } })).toBeNull();
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
 * The lines of the corpus of the log masking (infra/k8s/tests/log-corpus: store.log, api.log and postgres.log: a message of
 * the API reaches the panel in its errors; the container of the panel sees only apps/admin, so they are copied here
 * unchanged, like in the tests of the filters of the store and the API): what is planted in them does not come
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
    const event: Event = { request: { url: `http://localhost:5173/?${QUERY}` }, transaction: `/orders?${QUERY}` };
    const filtered = filterEvent(event);
    expectNoneOf(JSON.stringify(filtered), ["jan.kowalski", "RABAT-SECRET", "%2Fkonto", "v-oidc-code", "v-oidc-state"]);
  });
});
