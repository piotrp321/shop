/** Server-side configuration of the login (BFF). URLs come without a trailing slash. */
export type AuthConfig = {
  /** Public browser-facing URL of the store: redirect URIs, returnTo checks, cookie flags. */
  storeUrl: string;
  apiInternalUrl: string;
  redisUrl: string;
  /** Keycloak as the browser sees it; also the token issuer. */
  keycloakPublicUrl: string;
  /** Keycloak over the compose network: discovery, keys, token endpoint. */
  keycloakInternalUrl: string;
  clientSecret: string;
  /** Key of the HMAC signatures on the store's cookies. */
  sessionSecret: string;
  /** Login starts per client address per 10 minutes (`STORE_LOGIN_RATE_LIMIT`). */
  loginRateLimit: number;
};

export const REALM = "shop";
export const CLIENT_ID = "store";

const VARIABLES = {
  storeUrl: "STORE_URL",
  apiInternalUrl: "API_INTERNAL_URL",
  redisUrl: "REDIS_URL",
  keycloakPublicUrl: "KEYCLOAK_PUBLIC_URL",
  keycloakInternalUrl: "KEYCLOAK_INTERNAL_URL",
  clientSecret: "STORE_CLIENT_SECRET",
  sessionSecret: "STORE_SESSION_SECRET",
} as const satisfies Record<Exclude<keyof AuthConfig, "loginRateLimit">, string>;

const URL_KEYS = ["storeUrl", "apiInternalUrl", "redisUrl", "keycloakPublicUrl", "keycloakInternalUrl"] as const;

const MIN_SESSION_SECRET_LENGTH = 32;
const DEFAULT_LOGIN_RATE_LIMIT = 30;

/** Reads the configuration from env. Errors name the variables, never their values. */
export function readAuthConfig(env: Record<string, string | undefined> = process.env): AuthConfig {
  const entries = Object.entries(VARIABLES) as [keyof typeof VARIABLES, string][];
  const missing = entries.filter(([, name]) => !env[name]).map(([, name]) => name);
  if (missing.length > 0) {
    throw new Error(`Missing environment variables: ${missing.join(", ")}.`);
  }

  const config = Object.fromEntries(entries.map(([key, name]) => [key, env[name]!])) as Omit<AuthConfig, "loginRateLimit">;
  for (const key of URL_KEYS) {
    config[key] = config[key].replace(/\/+$/, "");
  }
  if (config.sessionSecret.length < MIN_SESSION_SECRET_LENGTH) {
    throw new Error(`STORE_SESSION_SECRET must be at least ${MIN_SESSION_SECRET_LENGTH} characters long.`);
  }
  return { ...config, loginRateLimit: readLoginRateLimit(env.STORE_LOGIN_RATE_LIMIT) };
}

function readLoginRateLimit(value: string | undefined): number {
  if (!value) {
    return DEFAULT_LOGIN_RATE_LIMIT;
  }
  const limit = /^[1-9]\d*$/.test(value) ? Number(value) : NaN;
  if (!Number.isSafeInteger(limit)) {
    throw new Error("STORE_LOGIN_RATE_LIMIT must be a positive integer.");
  }
  return limit;
}
