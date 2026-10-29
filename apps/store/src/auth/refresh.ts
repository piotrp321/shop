import { randomBytes } from "node:crypto";
import type { RedisClient } from "./redis";
import type { SessionData, SessionStore } from "./session-store";

/** Tokens from the token endpoint. Lifetimes in seconds, as Keycloak sends them. */
export type TokenSet = {
  accessToken: string;
  refreshToken: string;
  idToken?: string;
  expiresIn: number;
  /** Keycloak's `refresh_expires_in`. */
  refreshExpiresIn?: number;
};

/** Keycloak refused the refresh token (`invalid_grant`): the customer is signed out. */
export class RefreshRejectedError extends Error {
  constructor() {
    super("The refresh token was rejected.");
    this.name = "RefreshRejectedError";
  }
}

/**
 * The refresh could not be done now (Keycloak unreachable, the lock holder failed) but the session is still
 * valid: answer 503 and keep the session.
 */
export class RefreshUnavailableError extends Error {
  constructor() {
    super("The access token could not be refreshed right now.");
    this.name = "RefreshUnavailableError";
  }
}

/** Refresh when the access token expires in less than this. */
export const REFRESH_THRESHOLD_MS = 30_000;
const LOCK_TTL_MS = 5000;
const POLL_INTERVAL_MS = 100;
const WAIT_TIMEOUT_MS = 5000;

// Deletes the lock only while it still holds our token, so an expired lock taken over by someone else survives.
const RELEASE_LOCK = `if redis.call("GET", KEYS[1]) == ARGV[1] then return redis.call("DEL", KEYS[1]) else return 0 end`;

/** Session data after a token response. Without a refresh lifetime the session ends with the access token. */
export function sessionFromTokens(sub: string, tokens: TokenSet, now: number, previous?: SessionData): SessionData {
  const accessTokenExpiresAt = now + tokens.expiresIn * 1000;
  return {
    sub,
    accessToken: tokens.accessToken,
    refreshToken: tokens.refreshToken,
    idToken: tokens.idToken ?? previous?.idToken ?? "",
    accessTokenExpiresAt,
    refreshTokenExpiresAt: tokens.refreshExpiresIn ? now + tokens.refreshExpiresIn * 1000 : accessTokenExpiresAt,
  };
}

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * The session's access token, refreshed first when it expires within 30 s. Only one request refreshes a given
 * session (`SET lock:refresh:<id> <token> NX PX 5000`); the others read the session every 100 ms until its expiry
 * changes and use the new token.
 *
 * Returns null when there is no usable session: unknown id, expired or rejected refresh token, or a lock holder
 * that is stuck (lock still held, no new token within 5 s). Throws, leaving the session as it was, when the
 * refresh cannot be done now: `prepareRefresh` (OIDC discovery, done before taking the lock so that it does not
 * eat the lock's 5 s) or the refresh itself fails for another reason than a rejected token, and
 * {@link RefreshUnavailableError} for waiters that see the lock released without a new token.
 */
export async function getFreshAccessToken(
  sessionId: string,
  deps: {
    store: SessionStore;
    redis: RedisClient;
    refresh: (refreshToken: string) => Promise<TokenSet>;
    prepareRefresh?: () => Promise<unknown>;
    now?: () => number;
    pollIntervalMs?: number;
    waitTimeoutMs?: number;
  },
): Promise<string | null> {
  const { store, redis } = deps;
  const now = deps.now ?? Date.now;

  const session = await store.get(sessionId);
  if (!session) {
    return null;
  }
  if (session.accessTokenExpiresAt - now() > REFRESH_THRESHOLD_MS) {
    return session.accessToken;
  }
  if (session.refreshTokenExpiresAt <= now()) {
    return null;
  }

  await deps.prepareRefresh?.();

  const lockKey = `lock:refresh:${sessionId}`;
  const lockToken = randomBytes(16).toString("hex");
  const locked = await redis.set(lockKey, lockToken, { condition: "NX", expiration: { type: "PX", value: LOCK_TTL_MS } });

  if (locked !== "OK") {
    return waitForRefresh(sessionId, lockKey, session.accessTokenExpiresAt, deps);
  }

  try {
    // Another request may have refreshed between our first read and taking the lock.
    const current = await store.get(sessionId);
    if (!current) {
      return null;
    }
    if (current.accessTokenExpiresAt !== session.accessTokenExpiresAt) {
      return current.accessToken;
    }

    let tokens: TokenSet;
    try {
      tokens = await deps.refresh(current.refreshToken);
    } catch (error) {
      if (error instanceof RefreshRejectedError) {
        await store.delete(sessionId);
        return null;
      }
      throw error;
    }

    const updated = sessionFromTokens(current.sub, tokens, now(), current);
    await store.update(sessionId, updated);
    return updated.accessToken;
  } finally {
    // A failed release must not fail the request: the lock expires on its own within 5 s.
    await redis.eval(RELEASE_LOCK, { keys: [lockKey], arguments: [lockToken] }).catch((error: unknown) => {
      console.error(`Refresh lock release failed: ${error instanceof Error ? error.message : typeof error}`);
    });
  }
}

async function waitForRefresh(
  sessionId: string,
  lockKey: string,
  staleExpiry: number,
  deps: { store: SessionStore; redis: RedisClient; pollIntervalMs?: number; waitTimeoutMs?: number },
): Promise<string | null> {
  const interval = deps.pollIntervalMs ?? POLL_INTERVAL_MS;
  const attempts = Math.ceil((deps.waitTimeoutMs ?? WAIT_TIMEOUT_MS) / interval);
  for (let attempt = 0; attempt < attempts; attempt++) {
    await sleep(interval);
    // The lock is checked before the session: the holder saves the session before it releases the lock, so a
    // released lock followed by an unchanged session means the holder gave up without new tokens.
    const locked = (await deps.redis.exists(lockKey)) === 1;
    const session = await deps.store.get(sessionId);
    if (!session) {
      return null;
    }
    if (session.accessTokenExpiresAt !== staleExpiry) {
      return session.accessToken;
    }
    if (!locked) {
      throw new RefreshUnavailableError();
    }
  }
  // The lock is still held after 5 s without a new token: the holder is stuck, the session counts as signed out.
  return null;
}
