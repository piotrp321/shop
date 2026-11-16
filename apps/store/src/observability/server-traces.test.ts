import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { gunzipSync } from "node:zlib";
import * as Sentry from "@sentry/nextjs";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { createApiClient } from "@/api/client";
import { register } from "../../instrumentation";

/**
 * The traces of the server of the store with every request sampled (SENTRY_TRACES_SAMPLE_RATE=1), on a fake DSN that
 * points at a local collector: a transaction leaves only through the filter (`beforeSendTransaction`), so its name,
 * its request and the names and data of its spans have no query and no headers (paths 1 and 2).
 */

type Item = { type: string; payload: unknown };

const EMAIL = "jan.kowalski@example.pl";
const PLANTED = ["RABAT-SECRET", "v-oidc-code", "v-oidc-state", "jan.kowalski", "v-session-cookie", "v-bearer-token-1234567890"];

const items: Item[] = [];
const servers: Server[] = [];

async function listen(handler: Parameters<typeof createServer>[1]): Promise<string> {
  const server = createServer(handler);
  servers.push(server);
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  return `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
}

let apiUrl: string;
let storeUrl: string;

beforeAll(async () => {
  const collector = await listen((request, response) => {
    const chunks: Buffer[] = [];
    request.on("data", (chunk: Buffer) => chunks.push(chunk));
    request.on("end", () => {
      const body = Buffer.concat(chunks);
      const lines = (request.headers["content-encoding"] === "gzip" ? gunzipSync(body) : body).toString("utf8").split("\n").filter(Boolean);
      for (let index = 1; index + 1 < lines.length; index += 2) {
        items.push({ type: (JSON.parse(lines[index]!) as { type: string }).type, payload: JSON.parse(lines[index + 1]!) });
      }
      response.writeHead(200).end("{}");
    });
  });
  apiUrl = await listen((_request, response) => {
    response.writeHead(200, { "content-type": "application/json" }).end("{}");
  });
  // A page of the store: the root span of Next.js, with the address of the request, and a read of the API.
  storeUrl = await listen((request, response) => {
    void Sentry.startSpan(
      { name: "GET /szukaj", op: "http.server", attributes: { "http.target": request.url, "url.full": `http://localhost:3000${request.url}` } },
      async () => {
        await createApiClient({ baseUrl: apiUrl }).GET("/api/v1/products", { params: { query: { q: EMAIL } } });
        response.writeHead(200, { "content-type": "application/json" }).end("{}");
      },
    );
  });

  vi.stubEnv("NEXT_RUNTIME", "nodejs");
  vi.stubEnv("SENTRY_DSN", `${collector.replace("://", "://public@")}/1`);
  vi.stubEnv("SENTRY_TRACES_SAMPLE_RATE", "1");
  vi.stubEnv("API_INTERNAL_URL", apiUrl);
  vi.stubEnv("API_CATALOG_URL", apiUrl);
  await register();
});

afterAll(async () => {
  await Sentry.close(2000);
  vi.unstubAllEnvs();
  await Promise.all(servers.map((server) => new Promise((resolve) => server.close(resolve))));
});

describe("a sampled request of the server", () => {
  it("sends one transaction through the filter: no query in its name, request, spans and their data, no headers", async () => {
    expect(Sentry.getClient()?.getOptions().tracesSampleRate).toBe(1);

    const response = await createApiClient({ baseUrl: storeUrl }).GET("/api/v1/categories", {
      params: { query: { discountCode: "RABAT-SECRET", code: "v-oidc-code", state: "v-oidc-state" } as never },
      headers: { cookie: "store_session=v-session-cookie", authorization: "Bearer v-bearer-token-1234567890" },
    });
    expect(response.response.status).toBe(200);
    await Sentry.flush(2000);

    const transactions = items.filter((item) => item.type === "transaction");
    expect(transactions).toHaveLength(1);
    expect(items.filter((item) => item.type === "span")).toHaveLength(0);
    const transaction = transactions[0]!.payload as Sentry.Event;
    expect(transaction.transaction).toBe("GET /szukaj");
    expect(transaction.contexts?.trace?.data?.["http.target"]).toBe("/api/v1/categories");
    const client = transaction.spans?.find((span) => span.op === "http.client");
    expect(client?.description).toBe(`GET ${apiUrl.replace("127.0.0.1", "[REDACTED]")}/api/v1/products`);
    const sent = JSON.stringify(items);
    for (const value of PLANTED) {
      expect(sent, `${value} was sent to Sentry`).not.toContain(value);
    }
  });
});
