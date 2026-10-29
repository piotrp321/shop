import { afterAll, beforeAll, describe, expect, it } from "vitest";
import type { RedisClient } from "./redis";
import { connectTestRedis } from "./test-support";
import { createRedisLoginTransactionStore, createRedisSessionStore, type SessionData } from "./session-store";

let redis: RedisClient;

beforeAll(async () => {
  redis = await connectTestRedis();
});

afterAll(async () => {
  await redis?.close();
});

const session = (now: number): SessionData => ({
  sub: "user-1",
  accessToken: "access-1",
  refreshToken: "refresh-1",
  idToken: "id-1",
  accessTokenExpiresAt: now + 300_000,
  refreshTokenExpiresAt: now + 1_800_000,
});

describe("Redis session store", () => {
  it("creates a session under a long random id and reads it back", async () => {
    const store = createRedisSessionStore(redis);
    const data = session(Date.now());
    const first = await store.create(data);
    const second = await store.create(data);

    expect(first).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(second).not.toBe(first);
    expect(await store.get(first)).toEqual(data);
  });

  it("expires the session together with the refresh token", async () => {
    const store = createRedisSessionStore(redis);
    const id = await store.create(session(Date.now()));
    const ttl = await redis.pTTL(`session:${id}`);

    expect(ttl).toBeGreaterThan(1_790_000);
    expect(ttl).toBeLessThanOrEqual(1_800_000);
  });

  it("updates the data and the expiry", async () => {
    const store = createRedisSessionStore(redis);
    const now = Date.now();
    const id = await store.create(session(now));
    const updated = { ...session(now), accessToken: "access-2", refreshTokenExpiresAt: now + 600_000 };
    await store.update(id, updated);

    expect(await store.get(id)).toEqual(updated);
    expect(await redis.pTTL(`session:${id}`)).toBeLessThanOrEqual(600_000);
  });

  it("does not bring back a deleted session on update", async () => {
    const store = createRedisSessionStore(redis);
    const id = await store.create(session(Date.now()));
    await store.delete(id);
    await store.update(id, session(Date.now()));

    expect(await store.get(id)).toBeNull();
    expect(await redis.exists(`session:${id}`)).toBe(0);
  });

  it.each([["not JSON", "{"], ["wrong shape", JSON.stringify({ sub: "x" })], ["JSON null", "null"]])(
    "treats a stored value that is %s as no session",
    async (_name, value) => {
      await redis.set("session:corrupted", value, { expiration: { type: "EX", value: 60 } });
      expect(await createRedisSessionStore(redis).get("corrupted")).toBeNull();
    },
  );

  it("returns null for an unknown id", async () => {
    expect(await createRedisSessionStore(redis).get("unknown")).toBeNull();
  });
});

describe("Redis login transaction store", () => {
  const transaction = { state: "s", nonce: "n", codeVerifier: "v", returnTo: "/?page=2" };

  it("keeps a transaction for ten minutes", async () => {
    const id = await createRedisLoginTransactionStore(redis).create(transaction);
    const ttl = await redis.pTTL(`login:${id}`);

    expect(id).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(ttl).toBeGreaterThan(590_000);
    expect(ttl).toBeLessThanOrEqual(600_000);
  });

  it("hands a transaction out only once", async () => {
    const store = createRedisLoginTransactionStore(redis);
    const id = await store.create(transaction);

    expect(await store.take(id)).toEqual(transaction);
    expect(await store.take(id)).toBeNull();
  });

  it("hands a transaction out once even to concurrent callers", async () => {
    const store = createRedisLoginTransactionStore(redis);
    const id = await store.create(transaction);
    const results = await Promise.all([store.take(id), store.take(id), store.take(id)]);

    expect(results.filter((result) => result !== null)).toHaveLength(1);
  });
});
