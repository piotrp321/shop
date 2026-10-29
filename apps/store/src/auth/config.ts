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
} as const satisfies Record<keyof AuthConfig, string>;

const URL_KEYS = ["storeUrl", "apiInternalUrl", "redisUrl", "keycloakPublicUrl", "keycloakInternalUrl"] as const;

const MIN_SESSION_SECRET_LENGTH = 32;

/** Reads the configuration from env. Errors name the variables, never their values. */
export function readAuthConfig(env: Record<string, string | undefined> = process.env): AuthConfig {
  const entries = Object.entries(VARIABLES) as [keyof AuthConfig, string][];
  const missing = entries.filter(([, name]) => !env[name]).map(([, name]) => name);
  if (missing.length > 0) {
    throw new Error(`Missing environment variables: ${missing.join(", ")}.`);
  }

  const config = Object.fromEntries(entries.map(([key, name]) => [key, env[name]!])) as AuthConfig;
  for (const key of URL_KEYS) {
    config[key] = config[key].replace(/\/+$/, "");
  }
  if (config.sessionSecret.length < MIN_SESSION_SECRET_LENGTH) {
    throw new Error(`STORE_SESSION_SECRET must be at least ${MIN_SESSION_SECRET_LENGTH} characters long.`);
  }
  return config;
}
