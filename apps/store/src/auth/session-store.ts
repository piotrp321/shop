import { randomBytes } from "node:crypto";
import { LOGIN_TRANSACTION_TTL_SECONDS } from "./cookies";
import type { RedisClient } from "./redis";

/** A signed-in customer's session. Lives only in Redis; the browser gets nothing but its id. */
export type SessionData = {
  sub: string;
  accessToken: string;
  refreshToken: string;
  idToken: string;
  /** Epoch milliseconds. */
  accessTokenExpiresAt: number;
  /** Epoch milliseconds; also when the Redis key expires. */
  refreshTokenExpiresAt: number;
};

export interface SessionStore {
  create(data: SessionData): Promise<string>;
  get(id: string): Promise<SessionData | null>;
  /** Replaces the data of an existing session; a session deleted in the meantime stays deleted. */
  update(id: string, data: SessionData): Promise<void>;
  delete(id: string): Promise<void>;
}

/** What `/auth/callback` needs to finish the login started by `/auth/login`. */
export type LoginTransaction = { state: string; nonce: string; codeVerifier: string; returnTo: string };

export interface LoginTransactionStore {
  create(transaction: LoginTransaction): Promise<string>;
  /** Returns the transaction and deletes it in the same step: every transaction is used at most once. */
  take(id: string): Promise<LoginTransaction | null>;
}

/** 256 random bits, base64url. */
const randomId = () => randomBytes(32).toString("base64url");

const sessionKey = (id: string) => `session:${id}`;
const loginKey = (id: string) => `login:${id}`;

function parse<T>(value: string | null, isValid: (data: Record<string, unknown>) => boolean): T | null {
  if (value === null) {
    return null;
  }
  try {
    const data: unknown = JSON.parse(value);
    return typeof data === "object" && data !== null && isValid(data as Record<string, unknown>) ? (data as T) : null;
  } catch {
    return null;
  }
}

const isSession = (data: Record<string, unknown>) =>
  ["sub", "accessToken", "refreshToken", "idToken"].every((key) => typeof data[key] === "string") &&
  ["accessTokenExpiresAt", "refreshTokenExpiresAt"].every((key) => Number.isFinite(data[key]));

const isTransaction = (data: Record<string, unknown>) =>
  ["state", "nonce", "codeVerifier", "returnTo"].every((key) => typeof data[key] === "string");

export function createRedisSessionStore(redis: RedisClient, options: { now?: () => number } = {}): SessionStore {
  const now = options.now ?? Date.now;
  // The key lives as long as the refresh token; an already expired one still gets a positive TTL and is gone at once.
  const ttl = (data: SessionData) => ({ type: "PX" as const, value: Math.max(1, data.refreshTokenExpiresAt - now()) });

  return {
    async create(data) {
      const id = randomId();
      const result = await redis.set(sessionKey(id), JSON.stringify(data), { condition: "NX", expiration: ttl(data) });
      if (result !== "OK") {
        throw new Error("Session id collision.");
      }
      return id;
    },
    async get(id) {
      return parse<SessionData>(await redis.get(sessionKey(id)), isSession);
    },
    async update(id, data) {
      await redis.set(sessionKey(id), JSON.stringify(data), { condition: "XX", expiration: ttl(data) });
    },
    async delete(id) {
      await redis.del(sessionKey(id));
    },
  };
}

export function createRedisLoginTransactionStore(redis: RedisClient): LoginTransactionStore {
  return {
    async create(transaction) {
      const id = randomId();
      await redis.set(loginKey(id), JSON.stringify(transaction), {
        condition: "NX",
        expiration: { type: "EX", value: LOGIN_TRANSACTION_TTL_SECONDS },
      });
      return id;
    },
    async take(id) {
      return parse<LoginTransaction>(await redis.getDel(loginKey(id)), isTransaction);
    },
  };
}
