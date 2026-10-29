import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SESSION_COOKIE } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { sessionData, signedSessionCookie, testConfig, testDeps } from "@/auth/test-support";
import * as route from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
});

const logout = (headers: Record<string, string> = {}) =>
  route.POST(new NextRequest("http://0.0.0.0:3000/auth/logout", { method: "POST", headers }));

const clearedSessionCookie = (response: Response) =>
  response.headers.getSetCookie().find((c) => c.startsWith(`${SESSION_COOKIE}=;`) && c.includes("Max-Age=0"));

describe("POST /auth/logout", () => {
  it("answers GET with 405, Allow: POST and no-store, and keeps the session", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    const response = await route.GET();

    expect(response.status).toBe(405);
    expect(response.headers.get("allow")).toBe("POST");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.sessions.has(id)).toBe(true);
  });

  it("only handles GET (405) and POST", () => {
    expect(Object.keys(route).sort()).toEqual(["GET", "POST"]);
  });

  it("deletes the session and the cookie and ends the Keycloak session with id_token_hint", async () => {
    const id = await setup.deps.sessions.create(sessionData({ idToken: "id-token-7" }));
    const response = await logout({ cookie: signedSessionCookie(id), origin: testConfig.storeUrl });
    const location = new URL(response.headers.get("location")!);

    expect(response.status).toBe(303);
    expect(`${location.origin}${location.pathname}`).toBe(
      `${testConfig.keycloakPublicUrl}/realms/shop/protocol/openid-connect/logout`,
    );
    expect(location.searchParams.get("id_token_hint")).toBe("id-token-7");
    expect(location.searchParams.get("post_logout_redirect_uri")).toBe(`${testConfig.storeUrl}/`);
    expect(setup.sessions.has(id)).toBe(false);
    expect(clearedSessionCookie(response)).toBeDefined();
    expect(response.headers.get("cache-control")).toBe("private, no-store");
  });

  it("redirects to / and clears the cookie when there is no session", async () => {
    const response = await logout({ cookie: signedSessionCookie("expired") });

    expect(response.status).toBe(303);
    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(clearedSessionCookie(response)).toBeDefined();
  });

  it("still ends the local session when Keycloak cannot be discovered", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    setup.deps.oidc.mockRejectedValue(new Error("fetch failed"));
    const response = await logout({ cookie: signedSessionCookie(id) });

    expect(response.headers.get("location")).toBe(`${testConfig.storeUrl}/`);
    expect(setup.sessions.has(id)).toBe(false);
    expect(clearedSessionCookie(response)).toBeDefined();
  });

  it("allows a request without Origin and Sec-Fetch-Site (the session cookie is SameSite=Lax)", async () => {
    const id = await setup.deps.sessions.create(sessionData());
    const response = await logout({ cookie: signedSessionCookie(id) });

    expect(response.status).toBe(303);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it.each([["same-origin"], ["none"]])("allows a request without Origin and with Sec-Fetch-Site: %s", async (site) => {
    const id = await setup.deps.sessions.create(sessionData());
    const response = await logout({ cookie: signedSessionCookie(id), "sec-fetch-site": site });

    expect(response.status).toBe(303);
    expect(setup.sessions.has(id)).toBe(false);
  });

  it.each([
    ["cross-site without Origin", { "sec-fetch-site": "cross-site" }],
    ["same-site without Origin (another port of the same host, e.g. the admin panel)", { "sec-fetch-site": "same-site" }],
    ["cross-site with the store's Origin", { "sec-fetch-site": "cross-site", origin: testConfig.storeUrl }],
  ])("refuses a request that is %s", async (_name, headers) => {
    const id = await setup.deps.sessions.create(sessionData());
    const response = await logout({ cookie: signedSessionCookie(id), ...headers });

    expect(response.status).toBe(403);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(setup.sessions.has(id)).toBe(true);
  });

  it.each([["https://evil.com"], ["null"], ["http://localhost:3001"]])(
    "refuses a cross-origin request from %s and keeps the session",
    async (origin) => {
      const id = await setup.deps.sessions.create(sessionData());
      const response = await logout({ cookie: signedSessionCookie(id), origin });

      expect(response.status).toBe(403);
      expect(response.headers.get("cache-control")).toBe("private, no-store");
      expect(setup.sessions.has(id)).toBe(true);
      expect(response.headers.get("set-cookie")).toBeNull();
    },
  );
});
