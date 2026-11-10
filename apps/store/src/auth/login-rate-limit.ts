import { isIP } from "node:net";
import { clientIpHeader } from "@/checkout/client-ip";
import type { RedisClient } from "./redis";

/** A fixed window: the first hit of a key opens it, `EXPIRE` ends it. */
export const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 600;

/** The key of every request that has no usable address of its own (not an address, so it cannot collide with one). */
const SHARED_KEY = "shared";

/** Optional whitespace around a header value (RFC 9110), ASCII only: anything else makes the value invalid. */
const OPTIONAL_WHITESPACE = /^[ \t]+|[ \t]+$/g;

const hextets = (address: string): number[] => {
  // A dotted IPv4 tail (`::ffff:1.2.3.4`) is two hextets.
  const tail = address.match(/^(.*:)(\d+)\.(\d+)\.(\d+)\.(\d+)$/);
  const text = tail
    ? `${tail[1]}${((Number(tail[2]) << 8) | Number(tail[3])).toString(16)}:${((Number(tail[4]) << 8) | Number(tail[5])).toString(16)}`
    : address;
  const [head, rest] = text.split("::");
  const left = head === "" ? [] : head.split(":");
  const right = rest === undefined || rest === "" ? [] : rest.split(":");
  const zeros = rest === undefined ? 0 : 8 - left.length - right.length;
  return [...left, ...Array<string>(zeros).fill("0"), ...right].map((part) => parseInt(part, 16));
};

/** IPv4 as it is; IPv6 by its /64 prefix (a host usually holds a whole /64); IPv4-mapped IPv6 as its IPv4 address. */
function addressKey(address: string): string | null {
  const version = isIP(address);
  if (version === 4) {
    return address;
  }
  if (version !== 6 || address.includes("%")) {
    return null;
  }
  const parts = hextets(address);
  if (parts.slice(0, 5).every((part) => part === 0) && parts[5] === 0xffff) {
    return [parts[6] >> 8, parts[6] & 255, parts[7] >> 8, parts[7] & 255].join(".");
  }
  return `${parts.slice(0, 4).map((part) => part.toString(16)).join(":")}::/64`;
}

/**
 * The key of a login start: the address in the header named by `CLIENT_IP_HEADER` when it holds one valid IP address
 * (D2 and D4 of docs/features/hardening/spec.md), otherwise one key shared by all such requests. Without the variable
 * the header is never read, so a forged one counts for nothing.
 */
export function loginRateLimitKey(headers: Headers, env: Record<string, string | undefined> = process.env): string {
  const name = clientIpHeader(env);
  const value = name === null ? null : headers.get(name);
  return (value === null ? null : addressKey(value.replace(OPTIONAL_WHITESPACE, ""))) ?? SHARED_KEY;
}

export type LoginRateLimitHit = {
  /** The hits of the key in the current window, this one included. */
  count: number;
  /** Seconds to the end of the window. */
  retryAfterSeconds: number;
};

export interface LoginRateLimiter {
  /** Counts a login start. Rejects when the counter cannot be reached. */
  hit(key: string): Promise<LoginRateLimitHit>;
}

const counterKey = (key: string) => `login-rate:${key}`;

export function createRedisLoginRateLimiter(redis: RedisClient): LoginRateLimiter {
  return {
    async hit(key) {
      // One transaction: the window opens with the first hit (`NX`: an open window is never extended), and a counter
      // left without expiry gets a window again. The count is not capped, so a flood only grows a number.
      const [count, , ttl] = await redis
        .multi()
        .incr(counterKey(key))
        .expire(counterKey(key), LOGIN_RATE_LIMIT_WINDOW_SECONDS, "NX")
        .ttl(counterKey(key))
        .exec();
      return { count: Number(count), retryAfterSeconds: Math.max(1, Number(ttl)) };
    },
  };
}
