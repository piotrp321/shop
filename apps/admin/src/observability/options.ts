import { browserTracingIntegration, type init } from "@sentry/vue";
import type { Router } from "vue-router";
import type { SentryConfig } from "@/config";
import { filterBreadcrumb, filterEvent } from "./filter";

/** The options of `Sentry.init` that the panel builds (the rest, `app` and the test transport, is added by `initSentry`). */
export type SentryOptions = NonNullable<Parameters<typeof init>[0]>;

const escape = (value: string) => value.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");

/**
 * The addresses that get `sentry-trace` and `baggage` (spec: docs/features/sentry/spec.md, D7): only the API, anchored at
 * its start and at the end of its origin or path, because the SDK matches a text target anywhere in the address, so
 * `https://api.example.evil` or a query holding the address would match it. Never Keycloak or the store.
 */
export function apiPropagationTargets(apiUrl: string): RegExp[] {
  return [new RegExp(`^${escape(apiUrl.replace(/\/+$/, ""))}(?:[/?#]|$)`)];
}

/**
 * The options of the SDK of the panel (spec: D4, D7, D9). This version of the SDK has no `sendDefaultPii`; its replacement
 * `dataCollection` collects no user, cookies, bodies, query strings, variables of frames or lines of source around them, and of
 * the headers only User-Agent, Content-Type and Accept; the filter removes the same once more from everything that is sent.
 * `attachProps: false` keeps the props of the components out of the errors, there is no integration of Pinia (the state of the
 * stores holds the staff member and the data of orders) and no Replay. `traceLifecycle: "static"`: a transaction per page and
 * navigation sent through `beforeSendTransaction` (the filter); the default of this SDK streams single spans, which skip it.
 */
export function buildSentryOptions(config: SentryConfig, apiUrl: string, router: Router): SentryOptions {
  return {
    dsn: config.dsn,
    environment: config.environment ?? undefined,
    release: config.release ?? undefined,
    tracesSampleRate: config.tracesSampleRate,
    traceLifecycle: "static",
    tracePropagationTargets: apiPropagationTargets(apiUrl),
    attachProps: false,
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: { request: { allow: ["user-agent", "content-type", "accept"] }, response: false },
      httpBodies: [],
      urlQueryParams: false,
      stackFrameVariables: false,
      frameContextLines: 0,
      databaseQueryData: false,
      queues: false,
      graphQL: { document: false, variables: false },
      genAI: { inputs: false, outputs: false },
      mcp: { inputs: false, outputs: false },
    },
    integrations: [browserTracingIntegration({ router })],
    beforeSend: filterEvent,
    beforeSendTransaction: filterEvent,
    beforeBreadcrumb: filterBreadcrumb,
  };
}
