import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { RefreshUnavailableError } from "@/auth/refresh";
import { sessionData, signedSessionCookie } from "@/auth/test-support";
import { accountTestDeps, listItem, problem, quiet } from "@/account/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof accountTestDeps>;

beforeEach(() => {
  setup = accountTestDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
  quiet();
});

const LIST = "/api/v1/me/orders";
const list = (query = "", cookie?: string) =>
  GET(new NextRequest(`http://0.0.0.0:3000/api/account/orders${query}`, { headers: cookie ? { cookie } : {} }));

const signedIn = async (accessToken = "access-9") => {
  const id = await setup.deps.sessions.create(sessionData({ accessToken }));
  return { id, cookie: signedSessionCookie(id) };
};

const clearedSessionCookie = (response: Response) =>
  response.headers.getSetCookie().find((cookie) => cookie.startsWith(`${SESSION_COOKIE}=;`) && cookie.includes("Max-Age=0"));

describe("GET /api/account/orders", () => {
  it("answers the page of the API as it is, called with the token of the session, and is never cached", async () => {
    const { cookie } = await signedIn("access-9");
    const page = { items: [listItem()], nextCursor: "cursor-1" };
    setup.answer(LIST, () => Response.json(page));

    const response = await list("", cookie);

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(page);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.requests).toEqual([{ method: "GET", path: LIST, search: "", authorization: "Bearer access-9" }]);
  });

  it("passes the cursor on and nothing else of the query", async () => {
    const { cookie } = await signedIn();
    setup.answer(LIST, () => Response.json({ items: [], nextCursor: null }));

    await list("?after=abc_DEF-123&perPage=50&customerId=someone-else", cookie);

    expect(setup.requests.map((request) => request.search)).toEqual(["?after=abc_DEF-123"]);
  });

  it("answers 401 without the cookie and does not ask the API", async () => {
    const response = await list();

    expect(response.status).toBe(401);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.requests).toEqual([]);
  });

  it.each([
    ["unsigned", `${SESSION_COOKIE}=session-1`],
    ["signed with another secret", `${SESSION_COOKIE}=${signValue("session-1", "x".repeat(64))}`],
  ])("answers 401 and clears a cookie that is %s", async (_name, cookie) => {
    await setup.deps.sessions.create(sessionData());

    const response = await list("", cookie);

    expect(response.status).toBe(401);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(setup.requests).toEqual([]);
  });

  it("answers 401, deletes the session and clears the cookie when its token cannot be refreshed", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken = vi.fn(async () => null);

    const response = await list("", cookie);

    expect(response.status).toBe(401);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(await setup.deps.sessions.get(id)).toBeNull();
    expect(setup.requests).toEqual([]);
  });

  it("answers 401, deletes the session and clears the cookie when the API refuses the token", async () => {
    const { id, cookie } = await signedIn();
    setup.answer(LIST, () => problem(401));

    const response = await list("", cookie);

    expect(response.status).toBe(401);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(await setup.deps.sessions.get(id)).toBeNull();
  });

  it("answers 400 for a cursor the API refuses", async () => {
    const { cookie } = await signedIn();
    setup.answer(LIST, () => problem(400));

    const response = await list("?after=garbage", cookie);

    expect(response.status).toBe(400);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it.each([
    ["503", () => problem(503)],
    ["500", () => problem(500)],
    ["a body that is no page", () => Response.json({ unexpected: true })],
    ["a network failure", () => Promise.reject(new TypeError("fetch failed"))],
  ])("answers the generic 503 for %s and keeps the session", async (_name, respond) => {
    const { id, cookie } = await signedIn();
    setup.answer(LIST, respond as () => Response);

    const response = await list("", cookie);

    expect(response.status).toBe(503);
    expect(await response.text()).toBe("{}");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
  });

  it("answers 503 and keeps the session when the token cannot be refreshed right now", async () => {
    const { id, cookie } = await signedIn();
    setup.deps.freshAccessToken = vi.fn(async () => {
      throw new RefreshUnavailableError();
    });

    const response = await list("", cookie);

    expect(response.status).toBe(503);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(await setup.deps.sessions.get(id)).not.toBeNull();
  });

  it("never puts the token or the session id in the answer", async () => {
    const { id, cookie } = await signedIn("access-9");
    setup.answer(LIST, () => Response.json({ items: [listItem()], nextCursor: null }));

    const body = await (await list("", cookie)).text();

    expect(body).not.toMatch(/access-9|refresh-1|user-1/);
    expect(body).not.toContain(id);
  });
});
