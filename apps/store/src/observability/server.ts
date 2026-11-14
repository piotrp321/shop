import { subscribe } from "node:diagnostics_channel";
import type { IncomingMessage } from "node:http";
import * as Sentry from "@sentry/nextjs";
import { apiPropagationTargets, type SentryConfig } from "./config";
import { filterOptions } from "./options";

/**
 * The SDK of the server of the store (spec: docs/features/sentry/spec.md, D2, D4, D7, D8), started once by `register`
 * in `instrumentation.ts`, only with a DSN. Errors come from `onRequestError` (one event per error); traces of the
 * rendering and routes go to the API only (`API_INTERNAL_URL`, `API_CATALOG_URL`).
 */
export function initServerSentry(config: SentryConfig, env: Record<string, string | undefined>): void {
  Sentry.init({
    dsn: config.dsn,
    environment: config.environment ?? undefined,
    release: config.release ?? undefined,
    tracesSampleRate: config.tracesSampleRate,
    tracePropagationTargets: apiPropagationTargets(env),
    // A transaction per request, sent through `beforeSendTransaction` (the filter). The default of this SDK streams
    // single spans, which skip `beforeSendTransaction`.
    traceLifecycle: "static",
    ...filterOptions,
  });
  ignoreIncomingTraces();
}

/** The headers through which a request would continue a trace: Sentry's and W3C's. */
const TRACE_HEADERS = ["sentry-trace", "baggage", "traceparent", "tracestate"];

/**
 * The server never continues the trace of an incoming request (D7, P8): the browser of the store does not trace, so a
 * `sentry-trace` or `baggage` from the internet would only let anyone choose the trace and the sampling of the server.
 * Both the HTTP integration of the SDK (its own `continueTrace` and the OpenTelemetry propagator) and Next.js read
 * them from the headers of the request, so they are removed when Node.js starts a request (`http.server.request.start`
 * comes before the request reaches the SDK and Next.js). Every request starts a new trace sampled by
 * `tracesSampleRate`. Outgoing requests to the API still carry the trace of the server.
 */
function ignoreIncomingTraces(): void {
  subscribe("http.server.request.start", (message) => {
    const { request } = message as { request: IncomingMessage };
    for (const name of TRACE_HEADERS) {
      delete request.headers[name];
    }
  });
}

/** `onRequestError` of Next.js: one event per error of a request (rendering, route handler, server action). */
export const captureServerRequestError = Sentry.captureRequestError;
