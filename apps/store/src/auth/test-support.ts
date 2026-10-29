// In-memory doubles for the route handler tests. Never imported by application code.
import { allowInsecureRequests, Configuration } from "openid-client";
import { vi } from "vitest";
import type { AuthConfig } from "./config";
import { SESSION_COOKIE, signValue } from "./cookies";
import type { AuthDeps, MeResult } from "./deps";
import { createOidcClient } from "./oidc";
import { createRedisClient, type RedisClient } from "./redis";
import type { LoginTransaction, LoginTransactionStore, SessionData, SessionStore } from "./session-store";

export const testConfig: AuthConfig = {
  storeUrl: "http://localhost:3000",
  apiInternalUrl: "http://api",
  redisUrl: "redis://redis:6379",
  keycloakPublicUrl: "http://localhost:8080",
  keycloakInternalUrl: "http://keycloak:8080",
  clientSecret: "test-client-secret",
  sessionSecret: "t".repeat(64),
};

export function memorySessionStore() {
  const sessions = new Map<string, SessionData>();
  let next = 0;
  const store: SessionStore = {
    async create(data) {
      const id = `session-${++next}`;
      sessions.set(id, data);
      return id;
    },
    async get(id) {
      return sessions.get(id) ?? null;
    },
    async update(id, data) {
      if (sessions.has(id)) sessions.set(id, data);
    },
    async delete(id) {
      sessions.delete(id);
    },
  };
  return { store, sessions };
}

export function memoryTransactionStore() {
  const transactions = new Map<string, LoginTransaction>();
  let next = 0;
  const store: LoginTransactionStore = {
    async create(transaction) {
      const id = `login-${++next}`;
      transactions.set(id, transaction);
      return id;
    },
    async take(id) {
      const transaction = transactions.get(id) ?? null;
      transactions.delete(id);
      return transaction;
    },
  };
  return { store, transactions };
}

/** A real openid-client configuration with Keycloak's metadata, so URLs are built by the library itself. */
export function testOidcConfiguration(config: AuthConfig = testConfig): Configuration {
  const realm = `${config.keycloakPublicUrl}/realms/shop`;
  const internal = `${config.keycloakInternalUrl}/realms/shop`;
  const configuration = new Configuration(
    {
      issuer: realm,
      authorization_endpoint: `${realm}/protocol/openid-connect/auth`,
      token_endpoint: `${internal}/protocol/openid-connect/token`,
      jwks_uri: `${internal}/protocol/openid-connect/certs`,
      end_session_endpoint: `${realm}/protocol/openid-connect/logout`,
    },
    "store",
    config.clientSecret,
  );
  // The local URLs are plain HTTP, as in development.
  allowInsecureRequests(configuration);
  return configuration;
}

export const sessionData = (overrides: Partial<SessionData> = {}): SessionData => ({
  sub: "user-1",
  accessToken: "access-1",
  refreshToken: "refresh-1",
  idToken: "id-token-1",
  accessTokenExpiresAt: Date.now() + 300_000,
  refreshTokenExpiresAt: Date.now() + 1_800_000,
  ...overrides,
});

export function testDeps() {
  const sessions = memorySessionStore();
  const transactions = memoryTransactionStore();
  const oidc = createOidcClient(testOidcConfiguration(), testConfig);
  const deps = {
    config: testConfig,
    sessions: sessions.store,
    transactions: transactions.store,
    oidc: vi.fn(async () => oidc),
    freshAccessToken: vi.fn(async (id: string) => (await sessions.store.get(id))?.accessToken ?? null),
    fetchMe: vi.fn(async (): Promise<MeResult> => ({ kind: "ok", name: "Jan" })),
  } satisfies AuthDeps;
  return { deps, oidc, sessions: sessions.sessions, transactions: transactions.transactions };
}

export const signedSessionCookie = (id: string) => `${SESSION_COOKIE}=${signValue(id, testConfig.sessionSecret)}`;

/**
 * Database 1 of the stack's Redis, apart from the running store's sessions (database 0). Fails with a clear
 * message instead of a hook timeout when Redis is not running.
 */
export async function connectTestRedis(): Promise<RedisClient> {
  const url = process.env.REDIS_URL;
  if (!url) {
    throw new Error("REDIS_URL is not set: the integration tests run in the store container (make -C apps/store test).");
  }
  const redis = createRedisClient(url, { database: 1 });
  const unreachable = new Promise<never>((_, reject) =>
    setTimeout(() => reject(new Error(`Redis at ${url} is not reachable. Is the redis service running?`)), 3000),
  );
  try {
    await Promise.race([redis.connect(), unreachable]);
  } catch (error) {
    redis.destroy();
    throw error;
  }
  return redis;
}
