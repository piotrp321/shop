# LIGHTHOUSE – plan zadań

Specyfikacja: `docs/features/lighthouse/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | LIGHTHOUSE-1 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "LIGHTHOUSE",
  "spec": "docs/features/lighthouse/spec.md",
  "tasks": [
    {
      "id": "LIGHTHOUSE-1",
      "title": "Lighthouse measurement of three store pages: make lighthouse and a step in the CI e2e job",
      "scope": "Whole spec (D1-D10, 'Przebieg make lighthouse', 'CI', 'Zmiany dokumentacji'). e2e/package.json and e2e/package-lock.json (@lhci/cli, exact version), e2e/entrypoint.sh (only the hand-over to run.sh for the 'lighthouse' argument after the socat forwarders), new e2e/lighthouse/ (lighthouserc.cjs, run.sh, summary.mjs, summary.test.mjs), e2e/.gitignore (lighthouse-results/), infra/docker-compose.yml (e2e service: read-only mount of e2e/lighthouse, writable mount of e2e/lighthouse-results), main Makefile (lighthouse target with the store mode check of D7; e2e-check also runs node --test of summary.mjs), .github/workflows/ci.yml (job e2e: step make lighthouse after make e2e and init-env.sh tests, artifact lighthouse-results with always(), medians.md in the step summary), CLAUDE.md and docs/features/walking-skeleton/spec.md. No change to the store, the API, the e2e Dockerfile, the chart or the list of CI jobs and required checks. Improving page performance and changing thresholds are out of scope: if the median SEO score of a page is below 0.9, stop and ask the user. Local verification needs STORE_MODE=production make up and make seed in the task worktree (.ao/setup starts the store in dev).",
      "acceptance_criteria": [
        "After STORE_MODE=production make up and make seed, make lighthouse measures /, /c/turystyka/biwak/namioty and /p/namiot-turystyczny-norvane-biwak-2, 3 runs each after a warm-up request, and exits 0 when the median SEO score of every page is >= 0.9.",
        "The configuration uses the mobile profile, 3 runs, upload.target filesystem and the assertions of D5, each with aggregationMethod median; only categories:seo (minScore 0.9) is an error, Performance >= 0.9, LCP <= 2500 ms, CLS <= 0.1 and TBT <= 200 ms are warnings.",
        "With the store in dev mode and with the store container stopped, make lighthouse exits non-zero with a message specific to each case and does not start the e2e container (make reports any failed recipe as exit 2; exact codes are checked at the run.sh level).",
        "A page that does not answer 200 in the warm-up ends run.sh with exit 1 (Error 1 in the make output), the address and the status in the message, without measuring.",
        "lhci autorun runs with --failOnUploadFailure, and run.sh exits non-zero when manifest.json is missing after the measurement, so a failed report write never gives a green CI.",
        "A failing SEO assertion (tried with an impossible threshold, not committed) exits non-zero and still leaves the reports, manifest.json and medians.md in e2e/lighthouse-results/; the report shows the lhci autorun behaviour of the pinned version or the collect, upload, assert fallback of D9.",
        "The measurement logic is in the mounted e2e/lighthouse/run.sh; e2e/entrypoint.sh only hands over to it, and make e2e and make e2e-agent behave as before.",
        "summary.mjs has node --test tests run by make e2e-check: median of 3 runs for each of the five metrics in the units of D10, a run without its file and a run with null values left out (a metric with no value is '—'), a missing and an empty manifest (message, exit 0); its exit code does not change the exit code of the measurement.",
        "The CI e2e job runs make lighthouse E2E_FLAGS=-T E2E_BUILD= after make e2e and init-env.sh tests, uploads the lighthouse-results artifact with always() also after a failing assertion and appends medians.md to the step summary; no new job and no new required check.",
        "e2e/lighthouse-results/ is ignored by git.",
        "After the e2e job of the task PR is green, the PR description is updated (gh pr edit) with the medians of SEO, Performance, LCP, CLS and TBT for each page, the duration of the make lighthouse step and a link to the run.",
        "CLAUDE.md and docs/features/walking-skeleton/spec.md describe the target, the CI step, the e2e-check tests and the @lhci/cli dependency as listed in 'Zmiany dokumentacji'.",
        "make check and make e2e-check pass, and the task report shows the commands and results of the success path and of the error paths (criteria 3, 4, 5)."
      ],
      "risk": "STANDARD",
      "depends_on": []
    }
  ]
}
```
