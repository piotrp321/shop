import type { BrowserSentryConfig } from "./config";

/**
 * The entry of Sentry for the browser that imports nothing of the SDK (spec: docs/features/sentry/spec.md, P2, D8).
 * `startBrowserSentry` loads the module of the SDK (`./browser`, its own chunk) with `import()` and starts it, so a
 * page without a DSN never downloads it. Errors before the start are not reported (accepted in P2).
 */

type BrowserSdk = typeof import("./browser");

let starting: Promise<void> | null = null;
let started: BrowserSdk | null = null;

/** Starts the SDK once per page; a failure (chunk not loaded, DSN refused) leaves the page without Sentry. */
export function startBrowserSentry(config: BrowserSentryConfig): Promise<void> {
  starting ??= import("./browser").then(
    (sdk) => {
      sdk.initBrowserSentry(config);
      started = sdk;
    },
    () => undefined,
  ).catch(() => undefined);
  return starting;
}

const hasDigest = (error: unknown) => typeof error === "object" && error !== null && "digest" in error;

/**
 * Reports an error of the browser once the SDK is started. An error with `digest` comes from the server, which reported
 * it in `onRequestError`, so it is never reported here.
 */
export function reportBrowserError(error: unknown): void {
  if (started !== null && !hasDigest(error)) {
    started.reportBrowserError(error);
  }
}
