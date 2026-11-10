import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { LOGIN_COOKIE, readSignedValue } from "@/auth/cookies";
import { getAuthDeps } from "@/auth/deps";
import { memoryLoginRateLimiter, testConfig, testDeps } from "@/auth/test-support";
import { GET } from "./route";

vi.mock("@/auth/deps", () => ({ getAuthDeps: vi.fn() }));

let setup: ReturnType<typeof testDeps>;

beforeEach(() => {
  setup = testDeps();
  vi.mocked(getAuthDeps).mockResolvedValue(setup.deps);
});

const login = (query = "", headers: Record<string, string> = {}) =>
  GET(new NextRequest(`http://0.0.0.0:3000/auth/login${query}`, { headers }));

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

  describe("rate limit", () => {
    const LIMIT = 3;
    const message = "Zbyt wiele prób logowania. Spróbuj ponownie za kilka minut.";

    beforeEach(() => {
      setup.deps.config = { ...testConfig, loginRateLimit: LIMIT };
      setup.deps.loginRateLimiter = memoryLoginRateLimiter(437).limiter;
    });

    afterEach(() => {
      vi.unstubAllEnvs();
    });

    const exhaust = async (headers: Record<string, string> = {}) => {
      for (let i = 0; i < LIMIT; i++) {
        expect((await login("", headers)).status).toBe(303);
      }
    };

    it("lets exactly the limit of logins through and answers the next one with 429", async () => {
      await exhaust();
      const response = await login();

      expect(response.status).toBe(429);
      expect(response.headers.get("retry-after")).toBe("437");
      expect(response.headers.get("cache-control")).toBe("private, no-store");
      expect(response.headers.get("content-type")).toBe("text/plain; charset=utf-8");
      expect(await response.text()).toBe(message);
    });

    it("saves no login state and sets no cookie when the limit is exceeded", async () => {
      await exhaust();
      const created = setup.transactions.size;
      const response = await login("?returnTo=%2Fkonto");

      expect(response.status).toBe(429);
      expect(response.headers.get("set-cookie")).toBeNull();
      expect(response.headers.get("location")).toBeNull();
      expect(setup.transactions.size).toBe(created);
      expect(setup.deps.oidc).toHaveBeenCalledTimes(LIMIT);
    });

    it("counts by the address in CLIENT_IP_HEADER, each address on its own", async () => {
      vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
      await exhaust({ "CF-Connecting-IP": "203.0.113.7" });

      expect((await login("", { "CF-Connecting-IP": "203.0.113.7" })).status).toBe(429);
      expect((await login("", { "CF-Connecting-IP": "203.0.113.8" })).status).toBe(303);
    });

    it("counts an IPv6 address by its /64 prefix", async () => {
      vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
      await exhaust({ "CF-Connecting-IP": "2001:db8:1:2:aaaa::1" });

      expect((await login("", { "CF-Connecting-IP": "2001:db8:1:2:bbbb::2" })).status).toBe(429);
      expect((await login("", { "CF-Connecting-IP": "2001:db8:1:3::1" })).status).toBe(303);
    });

    it("shares one key when CLIENT_IP_HEADER is not set, whatever the headers say", async () => {
      await exhaust({ "CF-Connecting-IP": "203.0.113.7" });

      expect((await login("", { "CF-Connecting-IP": "203.0.113.8" })).status).toBe(429);
      expect((await login()).status).toBe(429);
    });

    it("shares one key when the header is missing", async () => {
      vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
      await exhaust();

      expect((await login()).status).toBe(429);
    });

    it.each([["203.0.113.7, 198.51.100.1"], ["not-an-ip"], ["203.0.113.7:443"]])(
      "does not trust the invalid address %s: it shares the key of requests without one",
      async (value) => {
        vi.stubEnv("CLIENT_IP_HEADER", "CF-Connecting-IP");
        await exhaust();

        expect((await login("", { "CF-Connecting-IP": value })).status).toBe(429);
      },
    );

    it("answers 503 without a transaction when the counter fails, like for any other Redis failure", async () => {
      setup.deps.loginRateLimiter = {
        hit: vi.fn().mockRejectedValue(new Error("The client is closed")),
      };
      const response = await login();

      expect(response.status).toBe(503);
      expect(response.headers.get("cache-control")).toBe("private, no-store");
      expect(response.headers.get("set-cookie")).toBeNull();
      expect(setup.transactions.size).toBe(0);
      expect(setup.deps.oidc).not.toHaveBeenCalled();
    });
  });
});
