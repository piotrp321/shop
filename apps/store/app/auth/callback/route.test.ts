import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, type MockInstance, vi } from "vitest";
import { LOGIN_COOKIE, readSignedValue, SESSION_COOKIE, signValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import type { OidcClient } from "@/auth/oidc";
import { sessionData, signedSessionCookie, testConfig, testDeps } from "@/auth/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;
let exchangeCode: MockInstance<OidcClient["exchangeCode"]>;

const tokens = {
  accessToken: "new-access",
  refreshToken: "new-refresh",
  idToken: "new-id",
  expiresIn: 300,
  refreshExpiresIn: 1800,
};

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
  exchangeCode = vi.spyOn(setup.oidc, "exchangeCode").mockResolvedValue({ sub: "user-7", tokens });
});

async function startTransaction(returnTo = "/?page=2") {
  const id = await setup.deps.transactions.create({ state: "state-1", nonce: "nonce-1", codeVerifier: "verifier-1", returnTo });
  return `${LOGIN_COOKIE}=${signValue(id, testConfig.sessionSecret)}`;
}

const callback = (query: string, cookie?: string) =>
  GET(new NextRequest(`http://0.0.0.0:3000/auth/callback${query}`, { headers: cookie ? { cookie } : {} }));

const setCookies = (response: Response) => response.headers.getSetCookie();
const cookieNamed = (response: Response, name: string) => setCookies(response).find((c) => c.startsWith(`${name}=`));

describe("GET /auth/callback", () => {
  it("creates a session, sets the session cookie and returns to returnTo", async () => {
    const response = await callback("?code=abc&state=state-1", await startTransaction());

    expect(response.status).toBe(303);
    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/?page=2`);
    expect(response.headers.get("cache-control")).toBe("private, no-store");

    const [[id, session]] = [...setup.sessions];
    expect(session).toMatchObject({ sub: "user-7", accessToken: "new-access", refreshToken: "new-refresh", idToken: "new-id" });
    expect(session.accessTokenExpiresAt).toBeGreaterThan(Date.now() + 290_000);
    expect(session.refreshTokenExpiresAt).toBeGreaterThan(Date.now() + 1_790_000);

    const cookie = cookieNamed(response, SESSION_COOKIE)!;
    const value = decodeURIComponent(cookie.split(";")[0].slice(SESSION_COOKIE.length + 1));
    expect(readSignedValue(value, testConfig.sessionSecret)).toBe(id);
    expect(cookie).toMatch(/HttpOnly/i);
    expect(cookie).toMatch(/SameSite=lax/i);
    expect(cookie).toContain("Path=/");
    expect(cookie).not.toMatch(/Secure/i);
    // A browser-session cookie: Redis decides how long the session lives.
    expect(cookie).not.toMatch(/Max-Age|Expires/i);
    // Neither tokens nor session data leave the server.
    expect(setCookies(response).join("\n")).not.toMatch(/new-access|new-refresh|new-id|user-7/);
    expect(response.headers.get("location")).not.toMatch(/new-access|new-refresh|new-id/);
  });

  it("exchanges the code with the checks from the transaction and the callback query", async () => {
    await callback("?code=abc&state=state-1&iss=x", await startTransaction());

    expect(exchangeCode).toHaveBeenCalledTimes(1);
    const [query, checks] = exchangeCode.mock.calls[0];
    expect(query.toString()).toBe("code=abc&state=state-1&iss=x");
    expect(checks).toEqual({ state: "state-1", nonce: "nonce-1", codeVerifier: "verifier-1" });
  });

  it("clears the login cookie", async () => {
    const response = await callback("?code=abc&state=state-1", await startTransaction());
    const cookie = cookieNamed(response, LOGIN_COOKIE)!;

    expect(cookie).toContain("Max-Age=0");
    expect(cookie).toContain("Path=/auth/callback");
  });

  it("starts a new session id and ends the one the browser had before (session fixation)", async () => {
    const previous = await setup.deps.sessions.create(sessionData());
    const cookies = `${await startTransaction()}; ${signedSessionCookie(previous)}`;

    await callback("?code=abc&state=state-1", cookies);

    expect(setup.sessions.has(previous)).toBe(false);
    expect(setup.sessions.size).toBe(1);
    expect([...setup.sessions.keys()][0]).not.toBe(previous);
  });

  it("redirects to / without a session when the state does not match", async () => {
    const response = await callback("?code=abc&state=other", await startTransaction());

    expect(response.status).toBe(303);
    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(exchangeCode).not.toHaveBeenCalled();
    expect(setup.sessions.size).toBe(0);
    expect(cookieNamed(response, SESSION_COOKIE)).toBeUndefined();
  });

  it("uses the transaction only once, even after a mismatched state", async () => {
    const cookie = await startTransaction();
    await callback("?code=abc&state=other", cookie);
    const response = await callback("?code=abc&state=state-1", cookie);

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(setup.sessions.size).toBe(0);
  });

  it("redirects to / without a session when the transaction is not in Redis", async () => {
    const response = await callback(
      "?code=abc&state=state-1",
      `${LOGIN_COOKIE}=${signValue("expired-login", testConfig.sessionSecret)}`,
    );

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(exchangeCode).not.toHaveBeenCalled();
    expect(setup.sessions.size).toBe(0);
  });

  it.each([
    ["no login cookie", undefined],
    ["an unsigned login cookie", `${LOGIN_COOKIE}=login-1`],
    ["a login cookie signed with another secret", `${LOGIN_COOKIE}=${signValue("login-1", "x".repeat(64))}`],
  ])("redirects to / without a session for %s", async (_name, cookie) => {
    await setup.deps.transactions.create({ state: "state-1", nonce: "n", codeVerifier: "v", returnTo: "/" });
    const response = await callback("?code=abc&state=state-1", cookie);

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(exchangeCode).not.toHaveBeenCalled();
    expect(setup.sessions.size).toBe(0);
  });

  it("redirects to / without a session when the code exchange or the id_token check fails", async () => {
    exchangeCode.mockRejectedValue(new Error("unexpected ID Token nonce claim value"));
    const response = await callback("?code=abc&state=state-1", await startTransaction("/?page=2"));

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(setup.sessions.size).toBe(0);
  });

  it("redirects to / when Keycloak sends an error instead of a code", async () => {
    const response = await callback("?error=access_denied&state=state-1", await startTransaction());

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(exchangeCode).not.toHaveBeenCalled();
    expect(setup.sessions.size).toBe(0);
  });

  it("sanitises returnTo again before redirecting", async () => {
    const response = await callback("?code=abc&state=state-1", await startTransaction("//evil.com"));
    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
  });

  it.each([["/%2F%2Fevil.com"], ["/%5Cevil.com"]])("keeps the encoded returnTo %s as a path on the store", async (returnTo) => {
    const response = await callback("?code=abc&state=state-1", await startTransaction(returnTo));
    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}${returnTo}`);
  });

  it("builds the redirect from STORE_URL, not from the Host header", async () => {
    const request = new NextRequest("http://evil.com/auth/callback?code=abc&state=state-1", {
      headers: { cookie: await startTransaction("/"), host: "evil.com" },
    });
    const response = await GET(request);

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
  });
});
