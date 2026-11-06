# DEPLOY – plan zadań

Specyfikacja: `docs/features/deploy/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DEPLOY-1, DEPLOY-2 | |
| 2 | DEPLOY-3 | |
| 3 | DEPLOY-4, DEPLOY-5 | |
| 4 | DEPLOY-6 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DEPLOY",
  "spec": "docs/features/deploy/spec.md",
  "tasks": [
    {
      "id": "DEPLOY-1",
      "title": "Production images of api, store and admin with runtime admin config and a shared demo seed script",
      "scope": "apps/api (Dockerfile, docker, .dockerignore, bin), apps/store (Dockerfile, .dockerignore, next.config.ts), apps/admin (Dockerfile, .dockerignore, index.html, public, src/auth, src/api/client.ts, src/config), root Makefile (seed only), docs. Spec sections „Obrazy produkcyjne” (api stages runtime/base/build/prod/dev with dev last, store standalone enabled only by a build-time variable and `node server.js`, admin vite build on nginx unprivileged with /config.js generated at start as window.__ADMIN_CONFIG__, SPA fallback and no-cache headers, the admin reads window.__ADMIN_CONFIG__ first and import.meta.env otherwise, empty public/config.js locally, error naming a missing key), „Dane startowe” (apps/api/bin/seed-demo running the <module>:seed-demo commands in the order of make seed, including the PAYMENT modules, and storefront:cache:purge-all, passing --force-reset in prod; make seed calls it). Images are built locally with docker build --target prod; no registry push, no chart, no CI workflow. Docs: apps/api/CLAUDE.md (image stages, bin/seed-demo), apps/admin/CLAUDE.md (production image, /config.js), apps/store/CLAUDE.md (production image).",
      "acceptance_criteria": [
        "docker build --target prod of api, store and admin succeeds without a running stack; the api prod image has no SPX, Composer, git or dev dependencies (shown by commands in the image)",
        "The api prod image starts FrankenPHP with APP_ENV=prod and answers /health given a database; the store image serves with node server.js; the admin image serves /config.js built from its environment, index.html for /auth/callback and no-cache headers for index.html and /config.js",
        "Unit tests (TDD, RED and GREEN runs in the report): the admin config reader prefers window.__ADMIN_CONFIG__, falls back to import.meta.env and names a missing key in the error",
        "bin/seed-demo runs the seeds in the make seed order and passes --force-reset only in prod; make seed uses it; a failing seed stops the script with a non-zero code (error path shown)",
        "Local make up, make seed, make check, make test and make e2e (also with STORE_MODE=production) work as before the change",
        "apps/api/CLAUDE.md, apps/store/CLAUDE.md and apps/admin/CLAUDE.md describe the production images, /config.js and bin/seed-demo"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "DEPLOY-2",
      "title": "Store switches from /api/site: demo notice bar and hiding the customer login link",
      "scope": "apps/store (app/api/site, app/layout.tsx, src/components, src/account), docs. Spec section „Sklep: przełączniki”, decision D7 (store part) and D10 (demoNotice, customerAccounts). Route handler /api/site, dynamic with Cache-Control: no-store, returning { demoNotice, customerAccounts } from STORE_DEMO_NOTICE and STORE_CUSTOMER_ACCOUNTS (defaults false and true). Client component in the layout showing the bar text from the spec when demoNotice; AccountMenu hides „Zaloguj się” when customerAccounts is false; a failed read gives the default state. The static and ISR HTML does not depend on the switches. Not included: chart values and the smoke check of the image (DEPLOY-3, DEPLOY-4). Docs: apps/store/CLAUDE.md (/api/site and the switches).",
      "acceptance_criteria": [
        "Tests (TDD, RED and GREEN runs in the report): /api/site for every combination of the variables and the defaults, with no-store; the bar shown with demoNotice and absent without it; the login link hidden without customerAccounts; the default state when /api/site fails",
        "Without the variables the store behaves as before (existing tests and make e2e unchanged)",
        "apps/store/CLAUDE.md describes /api/site and the switches",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "DEPLOY-3",
      "title": "Helm chart of the stack with ordered start, version jobs, idempotent initialization, chart checks and the k3d smoke test",
      "scope": "infra/k8s (charts/shop, environments/prod and ci values, Makefile, scripts), root Makefile (check calls make -C infra/k8s check), CLAUDE.md, apps/api/CLAUDE.md. Spec sections „Chart shop” (all compose services except rabbitmq-init with the same images, StatefulSets on local-path, Deployments with maxUnavailable 0 and probes from the compose health checks, Keycloak in start mode behind the proxy without --import-realm, the wave table with initContainers, garage-init with the binary copied to emptyDir, CronJob cart:purge-expired, profiles and values.schema.json), „Adresy” (ingresses with only the allowed auth paths, ipAllowList and noindex middlewares), decisions D1, D2 (shop chart only), D3, D8, D11 (job names from a checksum, make -C infra/k8s rerun), „Obrazy produkcyjne” (configuration files through symlinks in charts/shop/files, verified with helm template; copies plus a consistency check if symlinks do not work), „Dane startowe” (initialize: load only when shop has no products, prices, stocks and orders; mixed state fails without changes; initialize.enabled), „Sekrety” (the chart secret template covers every init-env.sh key; the check verifies it), „Weryfikacja bez serwera” (check and smoke; the ci profile fits ubuntu-latest; cluster name and ports from COMPOSE_PROJECT_NAME and .env; smoke checks /health, the store home page with a product, /config.js, realm discovery, an image through imgproxy, a confirmation email in Mailpit, X-Robots-Tag, an upgrade with a new revision that changes no data, the mixed-state failure, peak memory and disk). Keycloak realms in this task: created by a minimal keycloak-config Job (create when missing, from the rendered realm files without users); partialImport, switches and SMTP are DEPLOY-4. Not included: Argo CD, Sealed Secrets controller, seal-secrets.sh, CI workflow, bootstrap. Docs: CLAUDE.md (infra/k8s in the structure, make -C infra/k8s check|smoke|rerun, the rule that compose services, variables, worker transports, secrets and realm changes need the same chart change in the same PR), apps/api/CLAUDE.md (migrations backward compatible with the previous version, Messenger messages compatible both ways).",
      "acceptance_criteria": [
        "make -C infra/k8s check passes and make check runs it; injected errors fail it: a broken template, an init-env.sh key missing from the secret template, an invalid manifest, an auth ingress exposing the master realm, a Job in a wave earlier than its dependency",
        "make -C infra/k8s smoke passes locally on an empty k3d cluster with the production images imported (no push) and prints peak memory and disk; a broken migration and a broken image make it fail, and the cluster is deleted in both cases",
        "Smoke checks pass: /health of the API, store home page with a product, /config.js of the admin, discovery of both realms with no demo users, product image through imgproxy, order confirmation in Mailpit, X-Robots-Tag header with noindex",
        "Idempotency in smoke: an upgrade with a new revision runs initialize and keycloak-config again without changing products, orders or realms; initialize with products missing and orders present fails without changes",
        "A failed migration leaves the previous api and worker pods serving (shown in smoke by an upgrade with a failing migration)",
        "CLAUDE.md and apps/api/CLAUDE.md describe the chart rules and the backward compatibility rules"
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEPLOY-1"]
    },
    {
      "id": "DEPLOY-4",
      "title": "Keycloak configuration and mail: additive realm updates, customer accounts switch, Brevo SMTP, order mail switch and create-admin",
      "scope": "infra/k8s (charts/shop keycloak-config Job, mail and switch values, store and api environment, Makefile target create-admin), CLAUDE.md. Spec sections „Keycloak i konta”, „Poczta”, decisions D9 (partialImport with ifResourceExists=SKIP, then always: store client secret, redirectUris and webOrigins from the domain, registration from customerAccounts, verifyEmail, SMTP; never removes users, passwords or resources) and D10 (customerAccounts to the shop realm and STORE_CUSTOMER_ACCOUNTS, demoNotice to STORE_DEMO_NOTICE, orderMail brevo or mailpit for MAILER_DSN of api and worker; Keycloak SMTP always Brevo in prod, Mailpit in ci), values.schema.json requiring the SMTP secret when a Brevo value is used. create-admin: user in backoffice with role admin, no password, execute-actions-email with UPDATE_PASSWORD and CONFIGURE_TOTP, link valid 12 h, confirmation with the word tak, existing login fails without changes, no secret printed. Smoke additions: the switches in the store (bar and no login link on / and /cart with the image built without flags), registration disabled in shop, create-admin email in Mailpit, a realm change (new client) added by an upgrade while existing users stay. Not included: Sealed Secrets, the runbook. Docs: CLAUDE.md (realm changes go through keycloak-config; create-admin).",
      "acceptance_criteria": [
        "Render tests in make -C infra/k8s check: every switch combination gives the expected environment and realm settings; orderMail brevo without the SMTP secret fails the render naming the value",
        "Smoke: bar and hidden login link on / and /cart, registration disabled in the shop realm, no demo users, store login works with the client secret set by the Job",
        "Smoke: an upgrade that adds a client to a realm file adds it on the cluster and keeps existing users and passwords",
        "Smoke: create-admin sends the actions email to Mailpit, refuses an existing login without changes and changes nothing without the word tak; its output contains no password",
        "CLAUDE.md describes realm updates and create-admin",
        "make -C infra/k8s check and make -C infra/k8s smoke pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEPLOY-2", "DEPLOY-3"]
    },
    {
      "id": "DEPLOY-5",
      "title": "Deployment workflow: build and push of production images, smoke test on ubuntu-latest and fast-forward of the deploy branch",
      "scope": ".github/workflows/deploy.yml, tools/rulesets/deploy.json, infra/k8s (actionlint in check), CLAUDE.md. Spec sections „Wdrożenie nowej wersji” (steps 1–4, concurrency without cancelling the running run), decision D4 (tags = commit SHA, fast-forward of deploy with a deploy key from a repository secret, no force push; without the secret the workflow ends after the smoke test with a warning), „Obrazy produkcyjne” (ghcr.io/piotrp321/shop/<app>:<sha>), „Weryfikacja bez serwera” (smoke on ubuntu-latest for PRs touching the listed paths with images from the GHA cache, on workflow_dispatch without moving deploy, always on main before deploying; not a required check). Ruleset file: deletion and non-fast-forward forbidden for everyone, updates only by the DeployKey bypass actor; applying it, creating the deploy key and the push attempts are runbook steps after the merge (DEPLOY-6). Not included: Argo CD. Docs: CLAUDE.md (deploy branch, the deploy workflow and the deploy ruleset).",
      "acceptance_criteria": [
        "deploy.yml passes actionlint inside make -C infra/k8s check",
        "workflow_dispatch on the epic branch builds the three images, passes the smoke test on ubuntu-latest within its resources (peak memory and disk in the run summary) and does not move deploy; without the deploy key secret it ends with a warning",
        "Render or script test: on main the workflow pushes images tagged with the commit SHA and moves deploy only by fast-forward after a green smoke test (dry run against a local bare repository shown, including a rejected non-fast-forward)",
        "tools/rulesets/deploy.json forbids deletion and non-fast-forward without exceptions and allows updates only to a DeployKey bypass actor (validated against the GitHub rulesets schema)",
        "CLAUDE.md describes the deploy branch, the workflow and the ruleset"
      ],
      "risk": "STANDARD",
      "depends_on": ["DEPLOY-3"]
    },
    {
      "id": "DEPLOY-6",
      "title": "Platform and server: Argo CD app-of-apps, Sealed Secrets, seal-secrets.sh, bootstrap over SSH, runbook and project documentation",
      "scope": "infra/k8s (argocd app-of-apps with root, shop application with image.tag from $ARGOCD_APP_REVISION and the sealed-secrets application, environments/prod/secrets, scripts/seal-secrets.sh, bootstrap, Makefile targets bootstrap, status, reinitialize, README.md runbook), docs/vision.md, docs/adr, CLAUDE.md. Spec sections „Serwer, bootstrap i wdrożenie przez Claude Code” (bootstrap on Ubuntu 24.04: pinned k3s, vm.max_map_count, ufw for SSH and the k3s API, unattended security updates, Sealed Secrets with the key from a backup or the certificate written to the repo, Argo CD with root; idempotent; refuses without root or on another system before changes; status; reinitialize with the word tak), „Sekrety” (seal-secrets.sh in a kubeseal container, certificate from the repo, only missing init-env.sh keys with random values, external values read interactively without echo, errors without writing; the secrets check also requires sealed values when the certificate exists), decisions D4 (runbook: ruleset, deploy key, push attempts), D5, D6 (Cloudflare Origin CA, Full strict, ipAllowList list update script, Access on admin), „Poczta” (SPF, DKIM, DMARC, Brevo domain), „Kopie zapasowe” (OVH VPS backup), runbook marking of agent, user approval and user steps with the post-launch checks. Docs: docs/vision.md (the deployment environment replaces „Po MVP: publiczne środowisko demo”, next steps DEPLOY-OPS, public demo mode and Sentry, the k3s + Argo CD item leaves „Do opisania”), docs/adr/k3s-argo-cd.md, CLAUDE.md (agent runs server operations only through Makefile targets; user approval and user steps are not its decision).",
      "acceptance_criteria": [
        "Render of the Argo CD applications: shop tracks deploy with image.tag $ARGOCD_APP_REVISION, root includes shop and sealed-secrets; kubeconform passes",
        "seal-secrets.sh: without the certificate, with an empty external value or a failing kubeseal it writes nothing; a second run does not change existing sealed secrets (both paths shown); it never prints external values",
        "make -C infra/k8s check requires a sealed value for every init-env.sh key once the certificate is in the repo (shown with a temporary certificate and a missing key)",
        "bootstrap passes shellcheck; run twice in an Ubuntu 24.04 container without k3s it does not break the configuration; it refuses without root and on another system before changes; reinitialize and create-admin change nothing without the word tak",
        "The runbook leads from an empty server to a running environment with each step marked agent, user approval or user, including the ruleset push attempts, Access login, create-admin email, a rejected request to the server IP from outside Cloudflare and enabling the OVH backup",
        "docs/vision.md, docs/adr/k3s-argo-cd.md and CLAUDE.md describe the environment as specified"
      ],
      "risk": "CRITICAL",
      "depends_on": ["DEPLOY-4", "DEPLOY-5"]
    }
  ]
}
```
