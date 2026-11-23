import { createHmac, hkdfSync } from "node:crypto";
import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE, signValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { RefreshUnavailableError, sessionFromTokens } from "@/auth/refresh";
import { sessionData, signedSessionCookie, testConfig, testDeps } from "@/auth/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
});

const session = (cookie?: string) =>
  GET(new NextRequest("http://0.0.0.0:3000/auth/session", { headers: cookie ? { cookie } : {} }));

/** The buyer tag of the checkout, computed here as the spec defines it, not by the code under test. */
const buyerOf = (sub: string) =>
  createHmac("sha256", Buffer.from(hkdfSync("sha256", testConfig.sessionSecret, Buffer.alloc(0), "checkout-buyer", 32))).update(sub).digest("base64url");

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

  it("returns the name and the e-mail from GET /api/v1/me, called with the session's access token, and the buyer tag of its sub", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-9" }));
    const response = await session(signedSessionCookie(id));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ name: "Jan", email: "jan.kowalski@example.com", buyer: buyerOf("user-1") });
    expect(setup.deps.freshAccessToken).toHaveBeenCalledWith(id);
    expect(setup.deps.fetchMe).toHaveBeenCalledWith("access-9");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
  });

  it("never returns tokens, the sub or the session id", async () => {
    const sub = "0d4b7e52-6c1a-4f0e-9d3b-2a1c5e7f9b10";
    const id = await setup.deps.sessions.create(sessionData({ sub }));
    const body = await (await session(signedSessionCookie(id))).text();

    expect(Object.keys(JSON.parse(body))).toEqual(["name", "email", "buyer"]);
    expect(body).not.toMatch(/access-1|refresh-1|id-token-1/);
    expect(body).not.toContain(sub);
    expect(body).not.toContain(id);
  });

  it("gives the same buyer tag to two sessions of the same sub and another one to another sub", async () => {
    const first = await setup.deps.sessions.create(sessionData({ sub: "user-1", accessToken: "access-1" }));
    const second = await setup.deps.sessions.create(sessionData({ sub: "user-1", accessToken: "access-2" }));
    const other = await setup.deps.sessions.create(sessionData({ sub: "user-2", accessToken: "access-3" }));
    const buyer = async (id: string) => ((await (await session(signedSessionCookie(id))).json()) as { buyer: string }).buyer;

    expect(await buyer(first)).toBe(buyerOf("user-1"));
    expect(await buyer(second)).toBe(buyerOf("user-1"));
    expect(await buyer(other)).toBe(buyerOf("user-2"));
  });

  it("gives the same buyer tag after the access token was refreshed during the request", async () => {
    const id = await setup.deps.sessions.create(sessionData({ accessToken: "access-1" }));
    const before = ((await (await session(signedSessionCookie(id))).json()) as { buyer: string }).buyer;
    // The refresh as getFreshAccessToken does it: new tokens, the sub of the session kept.
    setup.deps.freshAccessToken.mockImplementationOnce(async (sessionId: string) => {
      const current = (await setup.deps.sessions.get(sessionId))!;
      const updated = sessionFromTokens(current.sub, { accessToken: "access-2", refreshToken: "refresh-2", expiresIn: 300, refreshExpiresIn: 1800 }, Date.now(), current);
      await setup.deps.sessions.update(sessionId, updated);
      return updated.accessToken;
    });
    const response = await session(signedSessionCookie(id));

    expect(setup.deps.fetchMe).toHaveBeenLastCalledWith("access-2");
    expect(((await response.json()) as { buyer: string }).buyer).toBe(before);
  });

  it("returns null and clears the cookie when the session is gone after its token was read", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockImplementationOnce(async (sessionId: string) => {
      const token = (await setup.deps.sessions.get(sessionId))!.accessToken;
      await setup.deps.sessions.delete(sessionId);
      return token;
    });
    const response = await session(signedSessionCookie(id));

    expect(await response.json()).toBeNull();
    expect(clearedSessionCookie(response)).toBeDefined();
  });

  it("keeps the session and answers 503 when Redis fails while the sub is read", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    const log = vi.spyOn(console, "error").mockImplementation(() => {});
    // The read of the token goes through; Redis fails at the read of the sub after it.
    const get = setup.deps.sessions.get;
    setup.deps.sessions.get = vi.fn(get).mockImplementationOnce(get).mockRejectedValueOnce(new Error("Redis is down"));
    const response = await session(signedSessionCookie(id));
    setup.deps.sessions.get = get;

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(JSON.stringify(log.mock.calls)).not.toMatch(/user-1|access-1|refresh-1/);
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

  it("keeps the session and answers 503 when the token cannot be refreshed right now", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.freshAccessToken.mockRejectedValue(new RefreshUnavailableError());
    const response = await session(signedSessionCookie(id));

    expect(response.status).toBe(503);
    expect(setup.sessions.has(id)).toBe(true);
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.deps.fetchMe).not.toHaveBeenCalled();
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
