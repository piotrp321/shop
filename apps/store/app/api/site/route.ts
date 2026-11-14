import { browserSentryConfig } from "@/observability/config";

/**
 * The switches of this deployment for the browser: `{ demoNotice, customerAccounts, sentry }` from the server variables
 * `STORE_DEMO_NOTICE` (default `false`) and `STORE_CUSTOMER_ACCOUNTS` (default `true`). A value is `true` or `false`
 * (any case, spaces around ignored); anything else, also an empty value, gives the default. `sentry` is
 * `{ dsn, environment, release }` of Sentry for the browser from `SENTRY_DSN`, `SENTRY_ENVIRONMENT` and `SENTRY_RELEASE`,
 * or `null` without a DSN (spec: docs/features/sentry/spec.md, D2). The pages never read these variables, so their
 * static and ISR HTML is the same for every deployment; the browser asks here instead.
 */

// Reads the environment at request time, never at build time.
export const dynamic = "force-dynamic";

function flag(value: string | undefined, fallback: boolean): boolean {
  switch (value?.trim().toLowerCase()) {
    case "true":
      return true;
    case "false":
      return false;
    default:
      return fallback;
  }
}

export function GET(): Response {
  return Response.json(
    {
      demoNotice: flag(process.env.STORE_DEMO_NOTICE, false),
      customerAccounts: flag(process.env.STORE_CUSTOMER_ACCOUNTS, true),
      sentry: browserSentryConfig(process.env),
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
