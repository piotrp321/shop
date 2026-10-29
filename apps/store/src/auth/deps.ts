import { type AuthConfig, readAuthConfig } from "./config";
import { fetchMe, type MeResult } from "./me";
import { createOidcClient, discoverOidc, type OidcClient } from "./oidc";
import { createRedisClient } from "./redis";
import { getFreshAccessToken } from "./refresh";
import {
  createRedisLoginTransactionStore,
  createRedisSessionStore,
  type LoginTransactionStore,
  type SessionStore,
} from "./session-store";

export type { MeResult };

/** Everything the `/auth/*` route handlers need; replaced by in-memory doubles in their tests. */
export type AuthDeps = {
  config: AuthConfig;
  sessions: SessionStore;
  transactions: LoginTransactionStore;
  /** Discovered on first use; a failed discovery is retried on the next call. */
  oidc: () => Promise<OidcClient>;
  freshAccessToken: (sessionId: string) => Promise<string | null>;
  fetchMe: (accessToken: string) => Promise<MeResult>;
};

const CONNECT_WAIT_MS = 2000;

function createAuthDeps(): AuthDeps {
  const config = readAuthConfig();
  const redis = createRedisClient(config.redisUrl);
  // Connects in the background and reconnects on its own. Until then commands fail fast (see createRedisClient).
  const connected = redis.connect().then(
    () => undefined,
    () => undefined,
  );
  const ready = () =>
    redis.isReady ? Promise.resolve() : Promise.race([connected, new Promise((r) => setTimeout(r, CONNECT_WAIT_MS))]);

  const sessions = createRedisSessionStore(redis);
  const transactions = createRedisLoginTransactionStore(redis);
  const withRedis =
    <A extends unknown[], R>(operation: (...args: A) => Promise<R>) =>
    async (...args: A) => {
      await ready();
      return operation(...args);
    };

  let oidc: Promise<OidcClient> | undefined;
  const getOidc = () => {
    oidc ??= discoverOidc(config).then(
      (configuration) => createOidcClient(configuration, config),
      (error: unknown) => {
        oidc = undefined;
        throw error;
      },
    );
    return oidc;
  };

  return {
    config,
    sessions: {
      create: withRedis(sessions.create),
      get: withRedis(sessions.get),
      update: withRedis(sessions.update),
      delete: withRedis(sessions.delete),
    },
    transactions: { create: withRedis(transactions.create), take: withRedis(transactions.take) },
    oidc: getOidc,
    freshAccessToken: withRedis((sessionId: string) =>
      getFreshAccessToken(sessionId, {
        store: sessions,
        redis,
        refresh: async (refreshToken) => (await getOidc()).refresh(refreshToken),
      }),
    ),
    fetchMe: (accessToken) => fetchMe(config.apiInternalUrl, accessToken),
  };
}

// One instance per server process; kept on globalThis so that `next dev` reloads do not open new connections.
const holder = globalThis as typeof globalThis & { storeAuthDeps?: AuthDeps };

export async function getAuthDeps(): Promise<AuthDeps> {
  holder.storeAuthDeps ??= createAuthDeps();
  return holder.storeAuthDeps;
}
