import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// No Redis: the dependencies are only built, the test never touches sessions.
vi.mock("./redis", () => ({
  createRedisClient: () => ({ connect: () => Promise.resolve(), isReady: true }),
}));

import { getAuthDeps } from "./deps";

const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { "Content-Type": "application/json" } });

let requested: URL[];

beforeEach(() => {
  requested = [];
  vi.stubEnv("STORE_URL", "http://store.test");
  vi.stubEnv("API_INTERNAL_URL", "http://api.test");
  // The catalog address is set too: the cart, the merge and the login must never use it.
  vi.stubEnv("API_CATALOG_URL", "http://catalog.test");
  vi.stubEnv("REDIS_URL", "redis://redis.test:6379");
  vi.stubEnv("KEYCLOAK_PUBLIC_URL", "http://keycloak.public.test");
  vi.stubEnv("KEYCLOAK_INTERNAL_URL", "http://keycloak.test");
  vi.stubEnv("STORE_CLIENT_SECRET", "client-secret");
  vi.stubEnv("STORE_SESSION_SECRET", "s".repeat(32));
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: Request | string | URL) => {
      requested.push(new URL(input instanceof Request ? input.url : String(input)));
      return json({ id: "1", email: "jan@example.com", name: "Jan" });
    }),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("auth and cart dependencies", () => {
  it("call the API directly at API_INTERNAL_URL, never through the catalog address", async () => {
    const deps = await getAuthDeps();

    await deps.api().GET("/api/v1/guest-carts/{cartId}", { params: { path: { cartId: "0192f0c4-7c1a-7c4e-9a6e-000000000001" } } });
    await deps.api("access-1").POST("/api/v1/me/cart/merge", { body: { guestCartId: "0192f0c4-7c1a-7c4e-9a6e-000000000001" } });
    await deps.fetchMe("access-1");

    expect(requested.map((url) => url.origin)).toEqual(["http://api.test", "http://api.test", "http://api.test"]);
    expect(requested.map((url) => url.pathname)).toEqual([
      "/api/v1/guest-carts/0192f0c4-7c1a-7c4e-9a6e-000000000001",
      "/api/v1/me/cart/merge",
      "/api/v1/me",
    ]);
  });
});
