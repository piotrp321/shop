import { expect, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { solidPng } from "../support/catalog-admin";
import { loginStaff, waitForBearerToken } from "../support/staff-login";
import { urls } from "../support/urls";

// Size and time limits of the request body of the API (docs/features/abuse-limits/spec.md, L8 and D10), at the Caddy of the
// API container. These tests call the API directly (no store). The limits are binary: 1 MiB for every route, 71 MiB for
// the POST of the product photo upload (above post_max_size, 70M).
//
// The problem document of a 413 is written by Caddy before PHP runs, so it has no X-Correlation-Id (the application sets it).
// A chunked body over the limit is refused by FrankenPHP while PHP reads it, as plain text, so only its status is checked.

const MIB = 1024 * 1024;
const ORDERS = `${urls.api}/api/v1/orders`;
const UPLOAD = `${urls.api}/api/v1/admin/product-images`;
const PROBLEM = "application/problem+json";
const TOO_LARGE_DETAIL = "Treść żądania jest za duża.";
const API_HOST = new URL(urls.api).hostname;
const API_PORT = Number(new URL(urls.api).port || 80);

/** A JSON object of exactly `bytes` bytes (ASCII). */
function jsonOfSize(bytes: number): string {
  const overhead = JSON.stringify({ x: "" }).length;
  return JSON.stringify({ x: "a".repeat(bytes - overhead) });
}

/** The same body as a stream of 64 KiB pieces: no Content-Length, so it is sent chunked. */
function chunked(body: string): ReadableStream<Uint8Array> {
  const bytes = new TextEncoder().encode(body);
  let offset = 0;
  return new ReadableStream<Uint8Array>({
    pull(controller) {
      if (offset >= bytes.length) {
        controller.close();
        return;
      }
      controller.enqueue(bytes.subarray(offset, offset + 64 * 1024));
      offset += 64 * 1024;
    },
  });
}

async function postChunked(url: string, body: string): Promise<Response> {
  const init = { method: "POST", headers: { "Content-Type": "application/json" }, body: chunked(body), duplex: "half" } as RequestInit;
  return fetch(url, init);
}

async function expectTooLargeProblem(response: { status(): number; headers(): Record<string, string>; json(): Promise<unknown> }) {
  expect(response.status()).toBe(413);
  expect(response.headers()["content-type"]).toContain(PROBLEM);
  expect(await response.json()).toMatchObject({ status: 413, detail: TOO_LARGE_DETAIL });
  expect(response.headers()["x-correlation-id"], "PHP did not run").toBeUndefined();
}

async function expectValidationProblem(response: { status(): number; headers(): Record<string, string>; json(): Promise<unknown> }) {
  expect(response.status()).toBe(422);
  expect(response.headers()["x-correlation-id"], "the application answered").toBeTruthy();
  expect(await response.json()).toMatchObject({ violations: expect.any(Array) });
}

test("a JSON body of 1 MiB reaches the validation, one byte more is a 413 problem from Caddy", async ({ request }) => {
  const headers = { "Content-Type": "application/json" };
  await expectValidationProblem(await request.post(ORDERS, { headers, data: jsonOfSize(MIB) }));
  await expectTooLargeProblem(await request.post(ORDERS, { headers, data: jsonOfSize(MIB + 1) }));
  await expectTooLargeProblem(await request.post(ORDERS, { headers, data: jsonOfSize(100 * MIB) }));
});

test("a chunked body without Content-Length is limited to 1 MiB too", async () => {
  expect((await postChunked(ORDERS, jsonOfSize(MIB))).status).toBe(422);
  const refused = await postChunked(ORDERS, jsonOfSize(MIB + 1));
  expect(refused.status).toBe(413);
  expect(refused.headers.get("x-correlation-id"), "PHP did not run").toBeNull();
  expect((await fetch(`${urls.api}/health`)).status, "the API still answers").toBe(200);
});

test("PUT and PATCH of other routes, and any method of the upload path variants, are limited to 1 MiB", async ({ request }) => {
  const headers = { "Content-Type": "application/json" };
  const data = jsonOfSize(2 * MIB);
  for (const method of ["put", "patch"] as const) {
    await expectTooLargeProblem(await request[method](ORDERS, { headers, data }));
  }
  // Only POST on the exact path gets the large limit: the others stop at 1 MiB, before the authentication that would answer 401.
  const variants: Array<[string, "post" | "put" | "patch"]> = [
    [UPLOAD, "put"],
    [UPLOAD, "patch"],
    [`${UPLOAD}/x`, "post"],
    [`${UPLOAD}/`, "post"],
    [`${urls.api}/api/v1/admin/Product-Images`, "post"],
    [`${urls.api}/API/v1/admin/product-images`, "post"],
    [`${urls.api}//api/v1/admin/product-images`, "post"],
    [`${urls.api}/index.php/api/v1/admin/product-images`, "post"],
  ];
  for (const [url, method] of variants) {
    const response = await request[method](url, { headers, data });
    expect(response.status(), `${method.toUpperCase()} ${url}`).toBe(413);
  }
  // The exact path takes the same body up to the authentication.
  const exact = await request.post(UPLOAD, { headers, data });
  expect(exact.status()).toBe(401);
});

test("a Content-Length lower than the body does not let a larger body in", async () => {
  // The server reads only the 10 bytes of the declared length; what follows is another, malformed request.
  const sent = `POST /api/v1/orders HTTP/1.1\r\nHost: ${API_HOST}\r\nContent-Type: application/json\r\nContent-Length: 10\r\n\r\n` + jsonOfSize(2 * MIB);
  const answer = await rawExchange(sent, 20_000);
  const statuses = [...answer.matchAll(/^HTTP\/1\.1 (\d{3})/gm)].map((match) => Number(match[1]));
  expect(statuses[0], answer.slice(0, 300)).toBe(400);
  expect(statuses.filter((status) => status >= 500 || (status >= 200 && status < 300)), "no success and no 5xx").toEqual([]);
  expect((await fetch(`${urls.api}/health`)).status).toBe(200);
});

test("the upload of a photo: 20 MiB passes, a request over post_max_size is the 422 of the controller, over 71 MiB a 413", async ({ page, request }) => {
  test.setTimeout(180_000);
  const bearer = waitForBearerToken(page);
  await loginStaff(page, staff.marketing);
  const headers = { Authorization: `Bearer ${await bearer}` };
  const upload = (buffer: Uint8Array) =>
    request.post(UPLOAD, { headers, multipart: { file: { name: "photo.png", mimeType: "image/png", buffer: Buffer.from(buffer) } }, timeout: 150_000 });

  // A valid PNG followed by zeros: decoders stop at IEND, so the file is an image of 20 MiB (under upload_max_filesize, 64M).
  const image = solidPng(30, 120, 60);
  const large = new Uint8Array(20 * MIB);
  large.set(image);
  const accepted = await upload(large);
  expect(accepted.status(), await accepted.text()).toBe(201);

  // The body is above post_max_size (70M) and below the limit of Caddy (71 MiB): the controller answers, as before.
  const overPhp = await upload(new Uint8Array(70 * MIB + 1));
  expect(overPhp.status()).toBe(422);
  expect(await overPhp.json()).toMatchObject({ detail: "Plik jest za duży." });

  // Over the limit of Caddy: refused before PHP.
  const overCaddy = await upload(new Uint8Array(71 * MIB));
  await expectTooLargeProblem(overCaddy);
});

test("a body sent slower than 60 s is cut, and the API answers /health afterwards", async () => {
  test.setTimeout(150_000);
  // More connections than PHP threads (twice the CPUs by default): each sends the headers and one byte of the body, then waits.
  const connections = 40;
  const started = Date.now();
  const closedAt = await Promise.all(Array.from({ length: connections }, () => holdOpen(`POST /api/v1/orders HTTP/1.1\r\nHost: ${API_HOST}\r\nContent-Type: application/json\r\nContent-Length: 1000\r\n\r\n{`)));
  // /health waits for a free thread, so at the latest until the stalled bodies are cut.
  const health = await fetch(`${urls.api}/health`, { signal: AbortSignal.timeout(100_000) });
  const healthAfter = (Date.now() - started) / 1000;
  expect(health.status).toBe(200);
  const seconds = closedAt.map((at) => (at - started) / 1000);
  expect(Math.max(...seconds), `the stalled connections closed after ${seconds.map((s) => s.toFixed(1)).join(", ")} s`).toBeLessThan(75);
  expect(healthAfter, `/health answered after ${healthAfter.toFixed(1)} s`).toBeLessThan(75);
  const fast = Date.now();
  expect((await fetch(`${urls.api}/health`)).status).toBe(200);
  expect((Date.now() - fast) / 1000, "no stalled request is left").toBeLessThan(5);
});

// The raw connections need node:net, whose typings (@types/node) this package does not have.
type Socket = {
  write(data: string): void;
  setEncoding(encoding: string): void;
  on(event: string, handler: (...args: any[]) => void): Socket;
  destroy(): void;
};
type Net = { connect(port: number, host: string, connected?: () => void): Socket };

async function net(): Promise<Net> {
  return (await import("node:net" as string)) as Net;
}

/** Sends `data` and returns what the server answers until it closes the connection (or `waitMs` pass). */
async function rawExchange(data: string, waitMs: number): Promise<string> {
  const { connect } = await net();
  return new Promise((resolve) => {
    let received = "";
    const socket = connect(API_PORT, API_HOST, () => socket.write(data));
    socket.setEncoding("utf8");
    socket.on("data", (chunk: string) => (received += chunk));
    socket.on("error", () => resolve(received));
    socket.on("close", () => resolve(received));
    setTimeout(() => {
      socket.destroy();
      resolve(received);
    }, waitMs);
  });
}

/** Sends `data` and keeps the connection silent; resolves with the time the server closes it. */
async function holdOpen(data: string): Promise<number> {
  const { connect } = await net();
  return new Promise((resolve) => {
    const socket = connect(API_PORT, API_HOST, () => socket.write(data));
    socket.on("data", () => undefined);
    socket.on("error", () => resolve(Date.now()));
    socket.on("close", () => resolve(Date.now()));
  });
}
