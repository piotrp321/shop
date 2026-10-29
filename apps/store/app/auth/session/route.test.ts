import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { sessionData, signedSessionCookie, testDeps } from "@/auth/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
});

const session = (cookie?: string) =>
  GET(new NextRequest("http://0.0.0.0:3000/auth/session", { headers: cookie ? { cookie } : {} }));

const clearedSessionCookie = (response: Response) =>
  response.headers.getSetCookie().find((c) => c.startsWith(`${SESSION_COOKIE}=;`) && c.includes("Max-Age=0"));

describe("GET /auth/session", () => {
  it("returns null without a cookie and is never cached", async () => {
    const response = await session();

    expect(response.status).toBe(200);
    expect(await response.json()).toBeNull();
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.deps.fetchMe).not.toHaveBeenCalled();
  });

  it("returns the name from GET /api/v1/me, called with the session's access token", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    const response = await session(signedSessionCookie(id));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ name: "Jan" });
    expect(setup.deps.freshAccessToken).toHaveBeenCalledWith(id);
    expect(setup.deps.fetchMe).toHaveBeenCalledWith("access-9");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("never returns tokens or the session id", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    const body = await (await session(signedSessionCookie(id))).text();

    expect(body).not.toMatch(/access-1|refresh-1|id-token-1|user-1/);
    expect(body).not.toContain(id);
  });

  it("returns null and clears the cookie when the session is no longer in Redis", async () => {
    const response = await session(signedSessionCookie("expired"));

    expect(response.status).toBe(200);
    expect(await response.json()).toBeNull();
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.deps.fetchMe).not.toHaveBeenCalled();
  });

  it.each([
    ["unsigned", `${SESSION_COOKIE}=session-1`],
    ["signed with another secret", `${SESSION_COOKIE}=${signValue("session-1", "x".repeat(64))}`],
  ])("returns null and clears a cookie that is %s, without reading the session", async (_name, cookie) => {
    await setup.deps.sessions.create(sessionData());
    const response = await session(cookie);

    expect(await response.json()).toBeNull();
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(setup.deps.freshAccessToken).not.toHaveBeenCalled();
    expect(setup.sessions.size).toBe(1);
  });

  it("deletes the session and returns null when the API answers 401", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.fetchMe.mockResolvedValue({ kind: "unauthorized" });
    const response = await session(signedSessionCookie(id));

    expect(await response.json()).toBeNull();
    expect(setup.sessions.has(id)).toBe(false);
    expect(clearedSessionCookie(response)).toBeDefined();
  });

  it("keeps the session when the API answers 503 (it cannot check tokens)", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.fetchMe.mockResolvedValue({ kind: "unavailable" });
    const response = await session(signedSessionCookie(id));

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("keeps the session and answers 503 when the API or Redis cannot be reached", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.fetchMe.mockRejectedValue(new Error("fetch failed"));
    const response = await session(signedSessionCookie(id));

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.sessions.has(id)).toBe(true);
  });

  it("deletes the session and returns null when no fresh access token can be had", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockResolvedValue(null);
    const response = await session(signedSessionCookie(id));

    expect(await response.json()).toBeNull();
    expect(setup.sessions.has(id)).toBe(false);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(setup.deps.fetchMe).not.toHaveBeenCalled();
  });
});
