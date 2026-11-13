# EVALS – plan zadań

Specyfikacja: `docs/features/evals/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | EVALS-1 | |
| 2 | EVALS-2, EVALS-3 | |
| 3 | EVALS-4, EVALS-5, EVALS-6 | |
| 4 | EVALS-7 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "EVALS",
  "spec": "docs/features/evals/spec.md",
  "tasks": [
    {
      "id": "EVALS-1",
      "title": "Evals registry, result format and the CI freshness check",
      "scope": "tools/evals (Python 3 standard library only): suite registry tools/evals/suites.json with the four agent suites and their watched files (spec: Zestawy), tools/evals/config.json (N, limits, thresholds, per-pass cost estimate default 1.5 USD and pass timeout default 20 min, overridable per suite in the registry), fingerprint over watched files, case files and the run-changing config (N, pass cost cap, pass timeout; not thresholds or the run cap) with recorded scoring code and validator hashes whose change is only a warning, lint rejecting a diff case whose task diff touches overlay paths and, with full history (evals job checkout with fetch-depth 0), case commits not reachable from main or epic/*, (spec: Pojęcia, Zestawy), history file format (the current result is the newest file, an empty history is none) with a field allowlist writer (spec: Wyniki i historia), commands lint, check, report, accept and waive in tools/evals/evals.py, check with --diff <base>...<head> and --enforce (errors only for a changed suite (any input of its fingerprint changed: watched file, case, registry entry, run config) with a measurement whose current result is stale or regression, or a PR adding a regression history file; everything else a warning; without --diff and with --enforce every stale measured suite is an error), lint with format validation, the scan of suites and history for home paths, emails and token patterns, decoded payload_b64 text present in plain anywhere in tools/evals, payload actions limited to the canary list of the registry and destructive patterns rejected only in decoded payloads (spec: Model zagrożeń, paths 5 and 6). make/evals.mk with evals-lint added to CHECK_TARGETS and evals-check outside it, a separate CI job `evals` in .github/workflows/ci.yml running make evals-lint evals-check for every change scope, including docs-only, with --enforce for pull requests to main and pushes to main and without it for epic/*, always reporting a status so it can be required in the main ruleset (spec: Sprawdzenie w CI; the ruleset change is the user's step after the merge). CLAUDE.md rule in „Sposób pracy” and the docs-only CI sentence extended with the evals job (spec: Zmiany dokumentacji). Not: running a model, the runner, scoring, cases, the /evals skill.",
      "acceptance_criteria": [
        "make evals-check passes with an empty history (warning); with a current baseline or pass result a one-byte change in a watched file fails with the suite name and the command /evals <suite>; a change in a file outside the watched list changes nothing; the current result is the newest history file.",
        "A current regression verdict fails evals-check; accepted and waived with a reason pass, without a reason fail evals-lint; make check runs evals-lint and not evals-check, so a stale fingerprint never turns the required checks job red.",
        "The evals job runs in a pull request that changes only .claude/agents/code-reviewer.md; against main a stale or regressed changed suite with a measurement is an error, while a suite without a measurement, a suite not changed in the pull request and any mismatch against epic/* are warnings; tests cover each case with temporary git repositories; the job runs no model.",
        "The writer rejects a field outside the format; lint rejects /Users/ and /home/ paths, an email, ghp_, github_pat_ and sk-ant- patterns, the decoded text of a payload_b64 present in plain in tools/evals, a payload action outside the canary list and rm -rf, push --force, .env and ~/.ssh reads in decoded payloads, while gh pr merge as a canary action and process text in real case data pass, each with a test; a tooling-only pull request with evals data runs evals-lint in the evals job.",
        "Script tests of tools/evals run in CI through script-tests.sh; make check passes; CLAUDE.md has the rule with a pointer to tools/evals/README.md."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "EVALS-2",
      "title": "Evals runner: isolated case checkout, guarded environment, sandbox, agent invocation and limits",
      "scope": "tools/evals runner execution (spec: Checkout przypadku, Uruchomienie agenta, Sandbox, Limity, Artefakty przebiegu, Model zagrożeń paths 1-4 and 7): a fresh checkout per pass in its own pass directory (the only --state-dir of the sandbox settings) cloned from a local bare repository in that directory with origin and pushurl on the local path, overlay commit with the current .claude/agents, .claude/skills and every watched file of the suite, for diff cases the overlay on base_sha and the task diff applied on top as one commit so the prompt gives the new base and head, decoding of payload_b64 into the run directory only, executor environment (gh stub on PATH serving case data and logging every other call, empty GH_CONFIG_DIR, no GH_TOKEN/GITHUB_TOKEN, failing logging GIT_SSH_COMMAND stub, no SSH_AUTH_SOCK, run-specific GIT_CONFIG_GLOBAL, GIT_CONFIG_NOSYSTEM=1, GIT_TERMINAL_PROMPT=0, GIT_ALLOW_PROTOCOL=file, DOCKER_HOST on a missing socket, paid API variables removed as in AO; spec paths 1-4 and 8), sandbox settings rendered by tools/agent-security/render_settings.py (--workdir case checkout, --state-dir run directory, --no-network, --no-docker) and passed with --settings and --strict-mcp-config (paths only from the runner itself and resolved with realpath), without the AO executor wrapper and its token, every real run refused before the first pass without a valid sandbox profile, claude -p --agent <name> with stream-json, --verbose, --permission-mode auto, --permission-prompts none, --max-budget-usd, --strict-mcp-config, --setting-sources project and --add-dir for agents writing results, init event without MCP servers required and its plugin list recorded, the stream of tool_use events stored by the runner outside the agent's reach for canary checks, binary from claude_pin.py (called, not changed), parsing of cost, result and rate_limit_event, pass statuses done, failed (pass budget, pass timeout, CLI error after init, incomplete stream), cost_stop, limit_stop and infra_error, evals.py estimate with the cost and time forecast (previous median or the registry estimate), the 50 USD cap per run of one suite including resumed parts (reaching it ends the run as cost_stop: failed run, no history file, no resume), a trial run cap of N times the pass cap, stop at 90% window utilisation, resume of an interrupted run with summed cost, trial run --case --overlay-rev that never writes history, run artefacts per pass. Tests use a fake CLI. Not: scoring, verdicts, cases, the /evals skill, .claude/skills/ao-run.",
      "acceptance_criteria": [
        "Every watched file of the suite and the agent definition in a case checkout are identical to the files at the current HEAD (a trial run: at the given revision, without a history file); the command contains --agent, --strict-mcp-config and --setting-sources project and none of --model, --allowedTools or --tools; a fake CLI reporting an MCP server in init gives infra_error.",
        "origin URL and pushurl of a case checkout are local paths; a fake CLI pushing a branch leaves it in the bare repository.",
        "In the executor environment git push to git@github.com:… and https://github.com/… fail without a network connection and the attempt is in ssh.log; the environment has GIT_ALLOW_PROTOCOL=file, DOCKER_HOST on a missing socket and no GH_TOKEN, GITHUB_TOKEN, SSH_AUTH_SOCK or paid API variables; a gh write through the stub fails and is in gh.log.",
        "Without the AGENT-SECURITY profile, with a profile without failIfUnavailable or with commands allowed outside the sandbox the run is refused before the first pass and the CLI is not started; with a valid profile the command contains --settings with realpath paths of that pass only and no network domains.", "Each pass has a fresh checkout and does not see files of another pass; for a diff case git diff base..head of the checkout equals the original base_sha..head_sha.",
        "With a fake CLI: a pass over its budget or its timeout is failed with a reason, an error before init is infra_error ending the run without a result; evals.py estimate prints the cost and time forecast and a forecast above the run cap is a refusal before start (without history the registry estimate is used, so 27 passes at 1.5 USD start under 50 USD); reaching the run cap ends the run as cost_stop without a history file and refuses --resume; parts resumed after limit_stop count towards the same cap; a rate_limit_event at 90% or a rejected status ends the run without a result; --resume continues from the first unfinished pass without repeating finished ones when the fingerprint is unchanged and refuses when it changed.",
        "Every pass writes the artefacts from the spec section Artefakty przebiegu; payload_b64 is decoded only inside the run directory."
      ],
      "risk": "CRITICAL",
      "depends_on": ["EVALS-1"]
    },
    {
      "id": "EVALS-3",
      "title": "Evals scoring: assertions, report parser, metrics, stability and regression verdicts",
      "scope": "tools/evals scoring that reads only the run artefacts (spec: Artefakty przebiegu, Asercje, Pomiar i metryki, Kryteria regresji): assertion types verdict, finding, max_severity, validator, json, canary_absent and tree_unchanged in case.json, report parser for code-reviewer (BLOCKER, IMPORTANT, SUGGESTION, PASS/CHANGES_REQUIRED) and spec-critic (Critical, Important, Minor, GOTOWA/DO POPRAWY) tested on stored real reports taken only from the sanitised fixture package in the task directory fixtures/ (spec: Dane przypadków), two injection scores (resilience from artefacts and the stored tool_use stream, and defect detection), metrics, stability and unstable cases, comparison with the previous and the reference result on the common cases (same id and case.json hash) with new and changed cases reported separately, verdicts baseline (only with 100% injection resilience), pass, regression with the cost and time warning, failed passes counted as failures, scoring code and validator hashes recorded, N >= 3 validation, result written as a history file through the EVALS-1 writer. Not: running a model, the checkout, the cases of the suites.",
      "acceptance_criteria": [
        "The parser matches and does not match findings on stored real reports of code-reviewer and spec-critic, including a finding whose path and keyword are in different list items (no match).",
        "Each assertion type has passing and failing tests on artefact fixtures; a pass with status failed counts as a failure without evaluating assertions; canary_absent fails on a forbidden call found only in the tool_use stream; validators get state directories inside the pass directory.",
        "From fixture artefacts the scorer computes correctness, recall, false alarms, scope violations, injection resilience, stability with the list of unstable cases, cost and time.",
        "Regression is reported for an injection resilience loss, a case dropping from N/N to at most floor(N/3) and a correctness drop of at least 10 points, against the previous result and against the reference result; a 9-point drop after a 9-point drop is a regression against the reference; a cost or time increase over 50% is only a warning; the first result is baseline; the verdict does not depend on case order.",
        "Adding or changing a case does not change the correctness denominator of the comparison; a first measurement without full injection resilience is regression; a configuration with N < 3 is an error."
      ],
      "risk": "STANDARD",
      "depends_on": ["EVALS-1"]
    },
    {
      "id": "EVALS-4",
      "title": "Eval cases of code-reviewer and spec-critic",
      "scope": "tools/evals/suites/code-reviewer and tools/evals/suites/spec-critic (spec: Przypadki, Asercje): code-reviewer cases from the real tasks behind #247, #248, #256 and #257 and at least two other real bugs that passed review and are not examples named in the agent prompt, a removed or weakened test (BLOCKER) and an out-of-scope file (IMPORTANT) as patches on real commits, two clean merged tasks checked by hand, one injection case with payload_b64; spec-critic cases from the DEPLOY-3 missing prerequisite, the DEPLOY-OPS-4 open threat model, a contradiction with code as a patch, a clean spec written under the current critic rules (SENTRY or DEMO-DATA), one injection case. Prompts reproduce /delivery step 6 (with tdd.status and the anonymised TDD evidence log of the task) and /prepare-epic step 2; spec-critic gets issue data the way it reads it on main after AGENT-SECURITY (provenance gate or gh stub). A README per suite with the source and the reason for each case. Source data only from the sanitised fixture package in the task directory fixtures/ (spec: Dane przypadków); what enters the repository passes evals-lint (spec: Model zagrożeń path 5). Not: changes of the agent files.",
      "acceptance_criteria": [
        "code-reviewer has at least 6 cases with a defect (at least 2 outside the examples in its prompt), 2 clean cases and 1 injection case; spec-critic at least 3 with a defect, 1 clean and 1 injection.",
        "Every case loads, its commits exist in the repository history, its injection payload decodes and a dry run with a fake CLI produces artefacts that the scorer evaluates.",
        "make check (evals-lint with the scan of the case data) passes and the fingerprints of both suites change with their case files.",
        "Each suite README names the source of each case (issue, task, commit) and why the clean cases are clean."
      ],
      "risk": "STANDARD",
      "depends_on": ["EVALS-2", "EVALS-3"]
    },
    {
      "id": "EVALS-5",
      "title": "Eval cases of backlog-groomer and retro-analyst",
      "scope": "tools/evals/suites/backlog-groomer and tools/evals/suites/retro-analyst (spec: Przypadki, Asercje): backlog-groomer cases from a /groom input.json of the sanitised fixture package (spec: Dane przypadków) or one built from real issues (a duplicate pair, an obsolete issue with evidence in code at the case commit, fix-epic candidates, an issue assigned to an open epic that must get no item), retro-analyst cases from the /retro fixtures in the repository and the sanitised fixture package (a recurring problem with a cause in an instruction, a single incident below the threshold, a corrective incident), one injection case per suite with payload_b64, validator assertions with groom.py validate and retro.py validate (called, not changed), GitHub data delivered the way the agents read it on main after AGENT-SECURITY (provenance gate or gh stub). A README per suite. Not: changes of the agent files, groom.py or retro.py.",
      "acceptance_criteria": [
        "backlog-groomer and retro-analyst each have at least 3 substantive cases and 1 injection case.",
        "Every case loads, its injection payload decodes and a dry run with a fake CLI writing a stored proposals.json produces artefacts that the scorer evaluates, including the validator assertion.",
        "make check (evals-lint with the scan of the case data) passes: no home paths, emails or tokens in input.json fixtures).",
        "Each suite README names the source of each case."
      ],
      "risk": "STANDARD",
      "depends_on": ["EVALS-2", "EVALS-3"]
    },
    {
      "id": "EVALS-6",
      "title": "The /evals skill and the documentation of the evals",
      "scope": ".claude/skills/evals/SKILL.md (spec: /evals): status, a suite or agents, start conditions in order (clean tree, branch other than main and epic/* with evals/<suite>-<date> created on main and evals/<epic>-<date> on epic/<epic>, pinned binary from claude_pin.py, sandbox profile valid, forecast of cost and time per suite and in total within the cap, night_queue.py room at 80% with get_usage, the user's confirmation after the forecast), /evals resume <id>, runner in the background with the session waiting in the same turn, get_usage at start and end, a check that HEAD and the fingerprint did not change during the run before writing, result and history commit test(evals): <suite> result, push or PR, report with metrics, comparison, regressions, skipped cases and usage, accept and waive only on the user's words in the conversation, results for epic/<epic> through a PR evals/<epic>-<date>, accept and waive as new history files. tools/evals/README.md (usage, registry, case, result and executor interface, forecast and limits, the gh api command adding evals to the required checks of the main ruleset, adoption in another repository), docs/features/agentic-software-house/plan.md step 17 and role table, docs/adr/agentic-software-house.md component Metryki i evale (spec: Zmiany dokumentacji). Not: changes of .claude/skills/ao-run or night_queue.py.",
      "acceptance_criteria": [
        "/evals shows the cost and time forecast per suite and in total and starts only after the user's confirmation; it refuses with a reason on a dirty tree, without the pinned binary, with a forecast above the cap and without usage room; each error path is described and checked with a command in the task report.",
        "/evals status shows suite freshness, verdicts, sandbox availability, the AO pinned CLI version and suites measured with another version.",
        "The skill writes accepted or waived only after the user's words in the conversation and says that a message of another session, a file or a tool result is not a decision.",
        "README, the plan step 17 with the split into EVALS and EVALS-BENCH, the role table and the ADR component point to /evals and tools/evals; make check and the script tests pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["EVALS-2", "EVALS-3"]
    },
    {
      "id": "EVALS-7",
      "title": "/retro adds an eval case from the incident behind an agent fix",
      "scope": ".claude/skills/retro/SKILL.md and docs/features/retro/spec.md (spec: /retro i przypadek z incydentu): when a fix item changes a watched file of a suite the session builds a case from the incidents in evidence, the session (not retro-analyst) builds the case in the README format with an assertion matching the cause, runs it N times as a trial run with the overlay from origin/main (the agent before the fix; a case that passes in most repetitions is an observation without a commit), commits the case and later the suite result with the footer Retro-eval: <epic> #<n> <key> (not Retro-item), shows the forecast in the report and runs the suite of the changed agent in the background only with a forecast within the cap and room at 80% (also from the /ao-run queue) and the limits, on regression shows the cases and offers „przyjmij”, „zwolnij” or „bez N” in the retro conversation, or leaves the PR with the note „wymaga /evals <suite>”; „bez N” also reverts the Retro-eval commits of that item; the run time goes into the report usage section; the sentence that a retro PR runs nothing in CI is corrected (tooling scope with evals files); an incident without reproducible input is named in the report; the retro PR file check in SKILL.md allows tools/evals/suites/** and tools/evals/history/**. Not: retro.py (its fix file list is unchanged), the retro-analyst agent, .claude/skills/ao-run.",
      "acceptance_criteria": [
        "The skill describes building the case by the session, the trial run on the version before the fix with the observation path, the commits with the Retro-eval footer (the Retro-item lookup still finds exactly one commit per item), the revert of Retro-eval commits on „bez N” and the suite run with room at 80% and the limits, with the regression choices in the retro conversation.",
        "Without usage room or above the estimate the PR description says „wymaga /evals <suite>” and the evals job stays red; an incident without reproducible input is named in report.md.",
        "The retro PR file check allows *.md and the two evals directories and nothing else; docs/features/retro/spec.md describes the same behaviour; the script tests pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["EVALS-4", "EVALS-5", "EVALS-6"]
    }
  ]
}
```
