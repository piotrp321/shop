import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LOGIN_COOKIE, readSignedValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { testConfig, testDeps } from "@/auth/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
});

const login = (query = "") => GET(new NextRequest(`http://0.0.0.0:3000/auth/login${query}`));

describe("GET /auth/login", () => {
  it("redirects to the Keycloak authorization endpoint at its public URL with PKCE S256, state and nonce", async () => {
    const response = await login("?returnTo=%2F%3Fcategory%3Dx%26page%3D2");
    const location = new URL(response.headers.get("location")!);

    expect(response.status).toBe(303);
    expect(`${location.origin}${location.pathname}`).toBe(
      `${testConfig.keycloakPublicUrl}/realms/shop/protocol/openid-connect/auth`,
    );
    expect(location.searchParams.get("client_id")).toBe("store");
    expect(location.searchParams.get("response_type")).toBe("code");
    expect(location.searchParams.get("scope")).toBe("openid");
    expect(location.searchParams.get("redirect_uri")).toBe(`${testConfig.storeUrl}/auth/callback`);
    expect(location.searchParams.get("code_challenge_method")).toBe("S256");
    expect(location.searchParams.get("code_challenge")).toMatch(/^[A-Za-z0-9_-]{43}$/);

    const [[, transaction]] = [...setup.transactions];
    expect(location.searchParams.get("state")).toBe(transaction.state);
    expect(location.searchParams.get("nonce")).toBe(transaction.nonce);
    expect(transaction.returnTo).toBe("/?category=x&page=2");
    // Only the challenge leaves the server, never the verifier.
    expect(location.search).not.toContain(transaction.codeVerifier);
  });

  it("uses new random values on every login", async () => {
    await login();
    await login();
    const [first, second] = [...setup.transactions.values()];

    expect(first.state).not.toBe(second.state);
    expect(first.nonce).not.toBe(second.nonce);
    expect(first.codeVerifier).not.toBe(second.codeVerifier);
    expect(first.state.length).toBeGreaterThanOrEqual(43);
  });

  it("binds the transaction to a short signed cookie that only the callback receives", async () => {
    const response = await login();
    const cookie = response.headers.get("set-cookie")!;
    const value = decodeURIComponent(cookie.match(new RegExp(`^${LOGIN_COOKIE}=([^;]+)`))![1]);

    expect(readSignedValue(value, testConfig.sessionSecret)).toBe([...setup.transactions.keys()][0]);
    expect(cookie).toContain("Path=/auth/callback");
    expect(cookie).toContain("Max-Age=600");
    expect(cookie).toMatch(/HttpOnly/i);
    expect(cookie).toMatch(/SameSite=lax/i);
  });

  it.each([
    ["//evil.com"],
    ["/\\evil.com"],
    ["https://evil.com"],
    ["javascript:alert(1)"],
    ["%2F%2Fevil.com"],
    [""],
  ])("stores the invalid returnTo %s as /", async (returnTo) => {
    await login(`?returnTo=${encodeURIComponent(returnTo)}`);
    expect([...setup.transactions.values()][0].returnTo).toBe("/");
  });

  it("stores a missing returnTo as /", async () => {
    await login();
    expect([...setup.transactions.values()][0].returnTo).toBe("/");
  });

  it("is never cached", async () => {
    expect((await login()).headers.get("cache-control")).toBe("private, no-store");
  });

  it("answers 503 without a transaction when Keycloak cannot be discovered", async () => {
    setup.deps.oidc.mockRejectedValue(new Error("fetch failed"));
    const response = await login();

    expect(response.status).toBe(503);
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("set-cookie")).toBeNull();
    expect(setup.transactions.size).toBe(0);
  });
});
