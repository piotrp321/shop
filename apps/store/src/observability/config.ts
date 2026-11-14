/**
 * The configuration of Sentry from the variables of the server (spec: docs/features/sentry/spec.md, D1, D2, D7):
 * `SENTRY_DSN`, `SENTRY_ENVIRONMENT`, `SENTRY_RELEASE`, `SENTRY_TRACES_SAMPLE_RATE`. Without a DSN there is no
 * configuration and no SDK. This module loads nothing of the SDK, so reading it costs nothing without a DSN.
 */

type Variables = Record<string, string | undefined>;

export type SentryConfig = { dsn: string; environment: string | null; release: string | null; tracesSampleRate: number };

/** What the browser gets in `/api/site`: never the sample rate (the browser does not trace). */
export type BrowserSentryConfig = Pick<SentryConfig, "dsn" | "environment" | "release">;

const text = (value: string | undefined): string | null => value?.trim() || null;

/** A number from 0 to 1 written as digits; anything else is 0 (no traces). */
function sampleRate(value: string | undefined): number {
  const written = value?.trim() ?? "";
  const rate = /^\d+(\.\d+)?$/.test(written) ? Number(written) : Number.NaN;
  return rate >= 0 && rate <= 1 ? rate : 0;
}

export function readSentryConfig(env: Variables): SentryConfig | null {
  const dsn = text(env.SENTRY_DSN);
  if (dsn === null) {
    return null;
  }
  return {
    dsn,
    environment: text(env.SENTRY_ENVIRONMENT),
    release: text(env.SENTRY_RELEASE),
    tracesSampleRate: sampleRate(env.SENTRY_TRACES_SAMPLE_RATE),
  };
}

export function browserSentryConfig(env: Variables): BrowserSentryConfig | null {
  const config = readSentryConfig(env);
  return config === null ? null : { dsn: config.dsn, environment: config.environment, release: config.release };
}

const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");

/**
 * The addresses that get `sentry-trace` and `baggage`: only the origins of the API (`API_INTERNAL_URL` directly and
 * `API_CATALOG_URL` through Varnish), anchored, so neither Keycloak nor a host that only starts with the same name
 * gets them. The SDK matches a string target anywhere in the address, so the targets are regular expressions.
 */
export function apiPropagationTargets(env: Variables): RegExp[] {
  return [env.API_INTERNAL_URL, env.API_CATALOG_URL].flatMap((url) => {
    if (!URL.canParse(url ?? "")) {
      return [];
    }
    return [new RegExp(`^${escape(new URL(url!).origin)}(?:[/?#]|$)`)];
  });
}
