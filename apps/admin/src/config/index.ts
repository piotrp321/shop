/** Keys of the runtime configuration: the names of the Vite variables, also used in window.__ADMIN_CONFIG__. */
export type ConfigKey = "VITE_API_URL" | "VITE_KEYCLOAK_URL" | "VITE_ADMIN_URL" | "VITE_STORE_URL";

export interface ConfigSources {
  /** window.__ADMIN_CONFIG__: written at container start by the production image (/config.js). */
  runtime: Partial<Record<string, string>> | undefined;
  /** import.meta.env: the Vite variables of the dev server (compose) or of the build. */
  env: Partial<Record<string, string>>;
}

function currentSources(): ConfigSources {
  return { runtime: window.__ADMIN_CONFIG__, env: import.meta.env };
}

/**
 * Reads a required, browser-facing URL: window.__ADMIN_CONFIG__ first, import.meta.env when the key is not set there.
 * Drops trailing slashes. A key missing in both sources is an error naming the key.
 */
export function readUrl(name: ConfigKey, sources: ConfigSources = currentSources()): string {
  const value = sources.runtime?.[name] || sources.env[name];
  if (!value) {
    throw new Error(`${name} is not set: neither window.__ADMIN_CONFIG__ (/config.js) nor the Vite environment has it.`);
  }
  return value.replace(/\/+$/, "");
}

export interface SentryConfig {
  dsn: string;
  environment: string | null;
  release: string | null;
  /** From 0 to 1; 0 (no traces) when the value is missing, not a number written in digits or outside the range. */
  tracesSampleRate: number;
}

const text = (value: string | undefined): string | null => value?.trim() || null;

function sampleRate(value: string | undefined): number {
  const written = value?.trim() ?? "";
  const rate = /^\d+(\.\d+)?$/.test(written) ? Number(written) : Number.NaN;
  return rate >= 0 && rate <= 1 ? rate : 0;
}

/**
 * Reads the optional Sentry keys of window.__ADMIN_CONFIG__ (written by docker/40-admin-config.sh only when the variables are
 * set). Without a DSN (missing, empty or blank) it returns null and the SDK stays off. Only the runtime configuration counts:
 * Sentry is not active in `vite dev`, so the Vite environment is never read.
 */
export function readSentryConfig(
  runtime: Partial<Record<string, string>> | undefined = window.__ADMIN_CONFIG__,
): SentryConfig | null {
  const dsn = text(runtime?.SENTRY_DSN);
  if (dsn === null) {
    return null;
  }
  return {
    dsn,
    environment: text(runtime?.SENTRY_ENVIRONMENT),
    release: text(runtime?.SENTRY_RELEASE),
    tracesSampleRate: sampleRate(runtime?.SENTRY_TRACES_SAMPLE_RATE),
  };
}
