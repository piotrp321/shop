import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import type { RedisClient } from "./redis";
import { connectTestRedis } from "./test-support";
import { getFreshAccessToken, RefreshRejectedError, type TokenSet } from "./refresh";
import { createRedisSessionStore, type SessionData, type SessionStore } from "./session-store";

let redis: RedisClient;
let store: SessionStore;

beforeAll(async () => {
  redis = await connectTestRedis();
  store = createRedisSessionStore(redis);
});

afterAll(async () => {
  await redis?.close();
});

const sessionExpiringIn = (ms: number): SessionData => ({
  sub: "user-1",
  accessToken: "old-access",
  refreshToken: "old-refresh",
  idToken: "old-id",
  accessTokenExpiresAt: Date.now() + ms,
  refreshTokenExpiresAt: Date.now() + 1_800_000,
});

const newTokens: TokenSet = {
  accessToken: "new-access",
  refreshToken: "new-refresh",
  idToken: "new-id",
  expiresIn: 300,
  refreshExpiresIn: 1800,
};

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

describe("getFreshAccessToken on Redis", () => {
  it("refreshes once for five parallel requests and gives all of them the new token", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    const refresh = vi.fn(async () => {
      await sleep(200);
      return newTokens;
    });

    const tokens = await Promise.all(
      Array.from({ length: 5 }, () => getFreshAccessToken(id, { store, redis, refresh })),
    );

    expect(refresh).toHaveBeenCalledTimes(1);
    expect(refresh).toHaveBeenCalledWith("old-refresh");
    expect(tokens).toEqual(Array(5).fill("new-access"));

    const saved = await store.get(id);
    expect(saved).toMatchObject({ accessToken: "new-access", refreshToken: "new-refresh", idToken: "new-id", sub: "user-1" });
    expect(saved!.accessTokenExpiresAt).toBeGreaterThan(Date.now() + 290_000);
    expect(await redis.exists(`lock:refresh:${id}`)).toBe(0);
  });

  it("uses a token that is still valid for 300 s without refreshing", async () => {
    const id = await store.create(sessionExpiringIn(300_000));
    const refresh = vi.fn(async () => newTokens);

    expect(await getFreshAccessToken(id, { store, redis, refresh })).toBe("old-access");
    expect(refresh).not.toHaveBeenCalled();
  });

  it("gives up with null when the lock holder never saves the session", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    await redis.set(`lock:refresh:${id}`, "someone-else", { condition: "NX", expiration: { type: "PX", value: 5000 } });
    const refresh = vi.fn(async () => newTokens);
    const started = Date.now();

    const token = await getFreshAccessToken(id, { store, redis, refresh, pollIntervalMs: 50, waitTimeoutMs: 400 });

    expect(token).toBeNull();
    expect(refresh).not.toHaveBeenCalled();
    expect(Date.now() - started).toBeGreaterThanOrEqual(400);
    // Someone else's lock is left alone.
    expect(await redis.get(`lock:refresh:${id}`)).toBe("someone-else");
  });

  it("stops waiting as soon as the session disappears", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    await redis.set(`lock:refresh:${id}`, "someone-else", { condition: "NX", expiration: { type: "PX", value: 5000 } });
    const started = Date.now();
    setTimeout(() => void store.delete(id), 100);

    const token = await getFreshAccessToken(id, { store, redis, refresh: vi.fn(), pollIntervalMs: 50, waitTimeoutMs: 3000 });

    expect(token).toBeNull();
    expect(Date.now() - started).toBeLessThan(1000);
  });

  it("ends the session when Keycloak rejects the refresh token", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    const refresh = vi.fn(async () => {
      throw new RefreshRejectedError();
    });

    expect(await getFreshAccessToken(id, { store, redis, refresh })).toBeNull();
    expect(await store.get(id)).toBeNull();
    expect(await redis.exists(`lock:refresh:${id}`)).toBe(0);
  });

  it("keeps the session and releases the lock when the refresh fails for another reason", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    const refresh = vi.fn(async () => {
      throw new Error("connect ECONNREFUSED");
    });

    await expect(getFreshAccessToken(id, { store, redis, refresh })).rejects.toThrow("ECONNREFUSED");
    expect((await store.get(id))?.accessToken).toBe("old-access");
    expect(await redis.exists(`lock:refresh:${id}`)).toBe(0);
  });

  it("keeps the previous id token when the refresh response has none", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    const refresh = vi.fn(async () => ({ ...newTokens, idToken: undefined }));

    await getFreshAccessToken(id, { store, redis, refresh });

    expect((await store.get(id))?.idToken).toBe("old-id");
  });

  it("returns null for an expired refresh token without calling Keycloak", async () => {
    const id = await store.create(sessionExpiringIn(10_000));
    const refresh = vi.fn(async () => newTokens);
    // The clock is past the refresh token's expiry, the Redis key itself is still there.
    const now = () => Date.now() + 1_900_000;

    expect(await getFreshAccessToken(id, { store, redis, refresh, now })).toBeNull();
    expect(refresh).not.toHaveBeenCalled();
  });

  it("returns null for an unknown session", async () => {
    expect(await getFreshAccessToken("unknown", { store, redis, refresh: vi.fn() })).toBeNull();
  });
});
