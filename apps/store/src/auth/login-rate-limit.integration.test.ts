import { randomUUID } from "node:crypto";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { createRedisLoginRateLimiter } from "./login-rate-limit";
import { createRedisClient, type RedisClient } from "./redis";
import { connectTestRedis } from "./test-support";

let redis: RedisClient;

beforeAll(async () => {
  redis = await connectTestRedis();
});

afterAll(async () => {
  await redis?.close();
});

const uniqueKey = () => `test-${randomUUID()}`;

describe("Redis login rate limiter", () => {
  it("counts the hits of one key from 1", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();

    expect((await limiter.hit(key)).count).toBe(1);
    expect((await limiter.hit(key)).count).toBe(2);
    expect((await limiter.hit(key)).count).toBe(3);
  });

  it("counts every key on its own", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const [first, second] = [uniqueKey(), uniqueKey()];
    await limiter.hit(first);
    await limiter.hit(first);

    expect((await limiter.hit(second)).count).toBe(1);
  });

  it("opens a fixed window of 10 minutes at the first hit and does not extend it", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();
    await limiter.hit(key);
    // The window is already running: a later hit must not move its end.
    await redis.expire(`login-rate:${key}`, 100);
    const later = await limiter.hit(key);

    expect(later.count).toBe(2);
    expect(later.retryAfterSeconds).toBeGreaterThan(0);
    expect(later.retryAfterSeconds).toBeLessThanOrEqual(100);
  });

  it("answers with the seconds left of the window", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();
    const first = await limiter.hit(key);

    expect(first.retryAfterSeconds).toBeGreaterThan(595);
    expect(first.retryAfterSeconds).toBeLessThanOrEqual(600);
    expect(await redis.ttl(`login-rate:${key}`)).toBeLessThanOrEqual(600);
  });

  it("gives a counter left without expiry (a process that died between the commands) a window again", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();
    await redis.set(`login-rate:${key}`, "7");

    const hit = await limiter.hit(key);

    expect(hit.count).toBe(8);
    expect(hit.retryAfterSeconds).toBeGreaterThan(595);
    expect(await redis.ttl(`login-rate:${key}`)).toBeGreaterThan(0);
  });

  it("starts a new window when the key has expired", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();
    await limiter.hit(key);
    await limiter.hit(key);
    await redis.del(`login-rate:${key}`);

    expect((await limiter.hit(key)).count).toBe(1);
  });

  it("writes nothing but its counter", async () => {
    const limiter = createRedisLoginRateLimiter(redis);
    const key = uniqueKey();
    await limiter.hit(key);

    expect(await redis.keys(`*${key}*`)).toEqual([`login-rate:${key}`]);
  });

  it("rejects when Redis is not reachable, so that the route answers 503", async () => {
    const offline = createRedisClient("redis://127.0.0.1:1");
    const limiter = createRedisLoginRateLimiter(offline);

    await expect(limiter.hit(uniqueKey())).rejects.toThrow();
  });
});
