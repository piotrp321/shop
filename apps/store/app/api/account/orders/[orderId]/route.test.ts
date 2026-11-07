import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { RefreshUnavailableError } from "@/auth/refresh";
import { sessionData, signedSessionCookie } from "@/auth/test-support";
import { accountTestDeps, detail, ORDER_ID, problem, quiet } from "@/account/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof accountTestDeps>;

beforeEach(() => {
  setup = accountTestDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
  quiet();
});

const PATH = `/api/v1/me/orders/${ORDER_ID}`;
const NOT_FOUND = "Nie znaleźliśmy zamówienia.";
const read = (orderId: string, cookie?: string) =>
  GET(new NextRequest(`http://0.0.0.0:3000/api/account/orders/${orderId}`, { headers: cookie ? { cookie } : {} }), {
    params: Promise.resolve({ orderId }),
  });

const signedIn = async (accessToken = "access-9") => {
  const id = await setup.deps.sessions.create(sessionData({ accessToken }));
  return { id, cookie: signedSessionCookie(id) };
};

const clearedSessionCookie = (response: Response) =>
  response.headers.getSetCookie().find((cookie) => cookie.startsWith(`${SESSION_COOKIE}=;`) && cookie.includes("Max-Age=0"));

describe("GET /api/account/orders/[orderId]", () => {
  it("answers the order of the API as it is, called with the token of the session, and is never cached", async () => {
    const { cookie } = await signedIn("access-9");
    setup.answer(PATH, () => Response.json(detail()));

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(detail());
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.requests).toEqual([{ method: "GET", path: PATH, search: "", authorization: "Bearer access-9" }]);
  });

  it("answers 401 without a session and does not ask the API", async () => {
    const response = await read(ORDER_ID);

    expect(response.status).toBe(401);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.requests).toEqual([]);
  });

  it("answers 401, deletes the session and clears the cookie when the token cannot be refreshed", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken = vi.fn(async () => null);

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(401);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(await setup.deps.sessions.get(id)).toBeNull();
  });

  it("answers 401, deletes the session and clears the cookie when the API refuses the token", async () => {
    const { id, cookie } = await signedIn();
    setup.answer(PATH, () => problem(401));

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(401);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(await setup.deps.sessions.get(id)).toBeNull();
  });

  it("answers 404 with the text of the store when the API does not know the order (also another customer's)", async () => {
    const { id, cookie } = await signedIn();
    setup.answer(PATH, () => problem(404, "Zamówienie nie istnieje."));

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
  });

  it.each(["not-a-uuid", "..%2Fme", "R-100001", "0199A0B0-0000-7000-8000-0000000000AA%00"])("answers 404 for %s without asking the API", async (orderId) => {
    const { cookie } = await signedIn();

    const response = await read(orderId, cookie);

    expect(response.status).toBe(404);
    expect(await response.json()).toEqual({ detail: NOT_FOUND });
    expect(setup.requests).toEqual([]);
  });

  it("checks the session before the id", async () => {
    expect((await read("not-a-uuid")).status).toBe(401);
  });

  it.each([
    ["503", () => problem(503)],
    ["500", () => problem(500)],
    ["a body that is no order", () => Response.json({ unexpected: true })],
    ["a network failure", () => Promise.reject(new TypeError("fetch failed"))],
  ])("answers the generic 503 for %s and keeps the session", async (_name, respond) => {
    const { id, cookie } = await signedIn();
    setup.answer(PATH, respond as () => Response);

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(503);
    expect(await response.text()).toBe("{}");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
  });

  it("answers 503 and keeps the session when the token cannot be refreshed right now", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken = vi.fn(async () => {
      throw new RefreshUnavailableError();
    });

    const response = await read(ORDER_ID, cookie);

    expect(response.status).toBe(503);
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
  });
});
