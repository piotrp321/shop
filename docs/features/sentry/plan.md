# SENTRY – plan zadań

Specyfikacja: `docs/features/sentry/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | SENTRY-1, SENTRY-2, SENTRY-3, SENTRY-4 | |
| 2 | SENTRY-5 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "SENTRY",
  "spec": "docs/features/sentry/spec.md",
  "tasks": [
    {
      "id": "SENTRY-1",
      "title": "Sentry in the API and worker: one event per error, filtered events, correlation ID tag, traces",
      "scope": "apps/api: dependency sentry/sentry-symfony (accepted), bundle and config/packages/sentry.yaml with %env(default::SENTRY_*)% (spec D1, D2), single capture channel per source (D5: own kernel.exception listener after ProblemDetailsSubscriber using one shared status-for-exception function in Shared\\Infrastructure\\Http, bundle error listener off, console and Messenger listeners with capture_soft_fails false, Monolog Sentry handler from error excluding request/console/messenger/php/deprecation/cache channels and records with context.exception, no breadcrumb handler, 2 s transport timeouts), event filter in Shared\\Infrastructure\\Observability (D4: headers allowlist, cookies, body, IP, query strings in request/transactions/spans/breadcrumbs, frame vars, extra/context except correlation_id, Alloy masking patterns in messages, exception values, breadcrumbs and span descriptions incl. Doctrine spans), correlation_id tag on events and transactions with isolation between Messenger messages (D6), /health not traced, CORS allow_headers sentry-trace and baggage (D7), zend.exception_ignore_args=On in docker/php/conf.d/prod.ini, apps/api/CLAUDE.md (spec: Zmiany dokumentacji). Tests use a test transport and a fake DSN. Not: chart, frontends.",
      "acceptance_criteria": [
        "Without SENTRY_DSN the API behaves as today: make -C apps/api check and test pass, no Sentry transport is used.",
        "With a DSN and the test transport an exception ending in 500 produces exactly one event with release, environment and a correlation_id tag equal to the X-Correlation-ID response header.",
        "401, 403, 404, 405, 409, 422 and 429 responses, including domain NotFound, Conflict and InvalidInput, produce no event.",
        "An uncaught console command exception produces exactly one event; a message failing its last retry in the worker produces exactly one event with the correlation ID of that message, and two consecutive messages carry their own correlation IDs and no leftover scope data.",
        "An explicit application log at error produces exactly one event with no context besides correlation_id; a warning log produces none; no Monolog breadcrumbs are sent.",
        "Filter tests cover threat model paths 1-5: Authorization, Cookie and Order-Access-Token headers and cookies removed, query strings removed from request URL, transaction and span names and span data, POST body absent, emails and log-corpus formats (PostgreSQL rows, DBAL params, JWT, Bearer) masked in messages, exception values, breadcrumbs and span descriptions, frame vars removed, no user and no IP.",
        "A Doctrine span of a query with an email parameter carries SQL with a placeholder and no value; a request transaction has Doctrine spans; /health has no transaction.",
        "A CORS preflight from ADMIN_URL with sentry-trace and baggage is allowed; the API continues a trace from these headers.",
        "apps/api/CLAUDE.md describes the Sentry configuration, the single channel per source and the filter rule for new customer-data formats."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "SENTRY-2",
      "title": "Sentry in the store: server errors and traces, lazy browser error reporting",
      "scope": "apps/store: dependency @sentry/nextjs (accepted), instrumentation.ts (register for nodejs, onRequestError) reading SENTRY_* at server start (spec D2, D8), trace propagation only to API_INTERNAL_URL and API_CATALOG_URL and no continuation of incoming traces (D7, P8), /api/site field sentry {dsn, environment, release} or null, browser init in SiteSwitches by dynamic import of src/observability (P2), app/error.tsx reports only errors without digest through the lazy module, event filter (D4: headers, cookies, body, IP, query strings incl. discountCode/returnTo/code/state/q in URLs, transactions, spans and breadcrumbs, console breadcrumbs, frame vars, masking patterns), sendDefaultPii off, no user, CSP unchanged, apps/store/CLAUDE.md. Build-time source map upload is SENTRY-5. Not: chart, deploy workflow.",
      "acceptance_criteria": [
        "Without SENTRY_DSN: make -C apps/store check and test pass, /api/site returns sentry null, the browser does not download the Sentry chunk and makes no request to non-localhost hosts (e2e visual-foundation unchanged).",
        "With a DSN a page rendering error produces exactly one server event (onRequestError) without query string and cookies.",
        "app/error.tsx reports a browser error without digest once SDK is initialised and does not report errors with digest; the error page looks as before.",
        "An incoming request with a sentry-trace header carrying sampled=1 does not force tracing on the store server.",
        "Outgoing fetches carry sentry-trace and baggage only to the API URLs, never to Keycloak.",
        "Filter tests cover threat model paths 1, 2, 4, 6 and 9 for the store.",
        "next.config.ts security headers and CSP are unchanged (existing security-headers test passes).",
        "apps/store/CLAUDE.md describes the Sentry configuration, the lazy browser init and the filter rule."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "SENTRY-3",
      "title": "Sentry in the admin panel: runtime config, errors, navigation traces to the API",
      "scope": "apps/admin: dependency @sentry/vue (accepted), optional SENTRY_DSN, SENTRY_ENVIRONMENT, SENTRY_RELEASE, SENTRY_TRACES_SAMPLE_RATE written by docker/40-admin-config.sh only when set (required URL keys unchanged), one reader in src/config next to readUrl and env.d.ts types (spec D2), init in main.ts before mount with browserTracingIntegration for the router, tracePropagationTargets only the API URL, attachProps false, no Pinia integration (D9), filter (D4 incl. q of order search and code/state of /auth/callback), setUser({id}) after /me and setUser(null) on every logout including idle logout (P5), SDK bundled, security headers unchanged, apps/admin/CLAUDE.md. Build-time source map upload is SENTRY-5.",
      "acceptance_criteria": [
        "Without Sentry keys config.js is as today, 40-admin-config.sh still rejects a missing or empty required URL, make -C apps/admin check and test pass.",
        "With SENTRY_DSN set the script writes the optional keys and the reader returns them; an unhandled exception produces an event with user.id only and without q, code and state.",
        "API requests carry sentry-trace and baggage; requests to Keycloak do not.",
        "After logout and after idle logout events have no user.",
        "SDK configuration has attachProps false and no Pinia integration (test).",
        "apps/admin/CLAUDE.md describes the Sentry configuration and the filter rule."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "SENTRY-4",
      "title": "Chart values for Sentry and the documentation of the setup",
      "scope": "infra/k8s/charts/shop: block sentry (environment, tracesSampleRate, dsn.api, dsn.store, dsn.admin; empty by default) with schema (tracesSampleRate 0-1, DSN pattern), SENTRY_* env emitted only for an app with a non-empty DSN: api DSN through shop.apiEnv (api, worker, Jobs, init containers, restore CronJob), store, admin; SENTRY_RELEASE = image.tag (spec D1, D3, Konfiguracja); environments/prod/values.yaml with sentry.environment demo, tracesSampleRate 0.1 and empty DSNs (P4, P7); ci profile without DSN; render tests in infra/k8s/tests; infra/k8s/CLAUDE.md and README.md runbook (account in EU region, projects api/store/admin, DSNs PR, SENTRY_AUTH_TOKEN and SENTRY_ORG/SENTRY_PROJECT_STORE/SENTRY_PROJECT_ADMIN, spike protection and project rate limits, sample review after deploy); docs/vision.md and docs/adr/backups-observability.md (spec: Zmiany dokumentacji). Not: deploy workflow.",
      "acceptance_criteria": [
        "make -C infra/k8s check passes; a render with DSNs emits SENTRY_* only to the matching workloads (api: api, worker, Jobs, restore CronJob; store; admin) and a render without DSNs emits no SENTRY_*.",
        "The schema rejects tracesSampleRate outside 0-1 and a DSN in the wrong format.",
        "make -C infra/k8s smoke passes with the ci profile unchanged in behaviour.",
        "The runbook lists the user steps including spike protection and rate limits of the Sentry projects.",
        "docs/vision.md describes Sentry in Obserwowalność and no longer lists it in the next steps; docs/adr/backups-observability.md has the Sentry section."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "SENTRY-5",
      "title": "Source maps of the store and the admin uploaded from the deploy workflow",
      "scope": "apps/store (next.config.ts withSentryConfig source map options, Dockerfile), apps/admin (dev dependency @sentry/vite-plugin accepted, vite.config.ts, Dockerfile), .github/workflows/deploy.yml: maps generated in prod builds, uploaded by debug ID only when the secret is mounted, deleted after upload and absent from the prod stage, SENTRY_AUTH_TOKEN passed only as a build secret in the images job, upload flag as a build ARG that is part of the cache key, plugin telemetry off, missing secret skips the upload with a workflow summary line (spec P3, D10, threat model paths 7-8); infra/k8s/tests/test_deploy_workflow.py and infra/k8s/smoke/checks.py (no .map in prod images). Not: ci.yml and dev images.",
      "acceptance_criteria": [
        "test_deploy_workflow.py: SENTRY_AUTH_TOKEN only as a build secret in the images job, never in build-args, env of other steps or the smoke job; the upload flag is a build argument.",
        "Prod stages of the store and the admin contain no .map files (smoke check).",
        "Without the secret the workflow and the smoke job pass and the summary says the upload was skipped; a failing upload with the secret fails the image build.",
        "Plugin telemetry is disabled; make check and the tests of store and admin pass; the dev images and ci.yml are unchanged."
      ],
      "risk": "STANDARD",
      "depends_on": ["SENTRY-2", "SENTRY-3"]
    }
  ]
}
```
