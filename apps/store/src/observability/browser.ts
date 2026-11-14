import * as Sentry from "@sentry/nextjs";
import type { BrowserSentryConfig } from "./config";
import { filterOptions } from "./options";

/**
 * The SDK of the browser of the store (spec: docs/features/sentry/spec.md, P2, D4, D8), its own chunk: `lazy.ts`
 * loads it with `import()` only when `/api/site` gave a configuration. Errors only: unhandled errors and rejections
 * (`GlobalHandlers`) and the errors of `app/error.tsx`; no traces (no `browserTracingIntegration`, no
 * `tracesSampleRate`, so no `sentry-trace` on requests), no Replay, no sessions.
 */

const LEFT_OUT = new Set(["BrowserTracing", "BrowserSession", "Replay", "ReplayCanvas", "Feedback"]);

export function initBrowserSentry(config: BrowserSentryConfig, testing: Pick<Sentry.BrowserOptions, "transport"> = {}): void {
  Sentry.init({
    dsn: config.dsn,
    environment: config.environment ?? undefined,
    release: config.release ?? undefined,
    integrations: (defaults) => defaults.filter((integration) => !LEFT_OUT.has(integration.name)),
    ...filterOptions,
    ...testing,
  });
}

export function reportBrowserError(error: unknown): void {
  Sentry.captureException(error);
}
