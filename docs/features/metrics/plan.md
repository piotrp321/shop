# METRICS – plan zadań

Specyfikacja: `docs/features/metrics/spec.md`. Każde zadanie to osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | METRICS-1 | |
| 2 | METRICS-2 | |
| 3 | METRICS-3, METRICS-4 | |
| 4 | METRICS-5 | |
| 5 | METRICS-6, METRICS-7 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "METRICS",
  "spec": "docs/features/metrics/spec.md",
  "tasks": [
    {
      "id": "METRICS-1",
      "title": "Epic record from AO data: wall clock, breakdown, retries, waits and backfill",
      "scope": "tools/metrics (Python 3.9+ standard library only): tools/metrics/metrics.py with the commands epic and backfill, tools/metrics/config.json (categories, token weights, thresholds; values from the spec), tools/metrics/README.md (commands, record format, configuration), tests and sanitized fixtures in tools/metrics (only from the fixture package of this task in the AO task directory or synthetic). Record fields from AO data and GitHub merge state (spec: Pojęcia, Pomiar epiku: Źródła, Rekord epiku): epic, issue, final_pr, tasks, started_at, done_at, merged_at (gh pr view, read only), wall, breakdown with the category table and its precedence, integration, retries (attempt outcome classes from Pojęcia, REPAIR cause, FIX, sync PRs), parallel and subscription raw window deltas (night_queue._window_deltas and the night_queue intervals, imported, not copied; share is METRICS-2), gate_wait, human (ao_events.waits imported), gaps, and the cache rules (state in ~/.local/state/shop-metrics/epics, a transient source error never replaces a non-null value with null). The AO database is opened read only (mode=ro, busy_timeout) and ao is never run. backfill: every ao-* run with an epics row whose final PR is MERGED (spec: Historia i trend). Not: transcripts (stdout.jsonl), value, tokens, in_attempts (METRICS-2), trend, bottlenecks, regressions, CI, render (METRICS-3), waste, decide, skills.",
      "acceptance_criteria": [
        "metrics.py epic on the DEPLOY-OPS fixture: the breakdown categories sum to to_done, and every category has a test with an interval from the spec table, including github_wait capped at 30 minutes and precedence when categories overlap.",
        "A missing source (no events.jsonl, no measurements.jsonl, gh error, no epic_done) gives null and a gaps entry, never zero; a gh error does not overwrite stored values in the cache.",
        "backfill records runs whose final PR is MERGED (also INTEGRATING and BLOCKED in AO), skips epics in progress and directories without the epic row.",
        "subscription: a window renewed during the epic or a usage state other than ok gives null (same rules as night_queue._window_deltas, imported).",
        "Attempt classes: successful, blocked and failed attempts and usage_limit follow the definitions in Pojęcia (test per outcome).",
        "Error paths: unreadable or locked database, missing AO directory, gh error and bad config.json each end with a non-zero exit code and a message; the fixture scan test over tools/metrics fixtures (no absolute paths /Users/, /private/ or ~, no e-mail addresses, no token patterns ghp_, gho_, github_pat_, sk-, Bearer, Basic or base64 strings of 32+ characters other than SHAs and UUIDs, no text value longer than 120 characters) passes and fails on a planted example of each."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": ["tools/metrics/metrics.py", "tools/metrics/"],
      "parallel_with": []
    },
    {
      "id": "METRICS-2",
      "title": "Tokens and value per role, model and task from executor transcripts, with exact and estimated values",
      "scope": "tools/metrics/metrics.py and its tests and fixtures: reading tasks/<id>/attempts/<n>/stdout.jsonl (spec: Pojęcia: Rola, Wartość tokenów; Dokładność tokenów i wartości; Rekord epiku: value, attempts, in_attempts, versions, share): assistant messages deduplicated by message.id, roles from the subagent_type field of a message (no parent_tool_use_id: orchestrator), the attempt orchestrator by task kind and other for models without messages; the counting rules of the Dokładność table (per-message output_tokens are placeholders: orchestrator output as the sum of usage.output_tokens over all result messages of the attempt (each covers only the segment since the previous one), subagent output per model as modelUsage minus orchestrator output, split between subagent types by unique message ids as an estimate; a surplus of modelUsage over messages goes to other, a shortfall marks the roles of that model estimated), the value of the last result split by token weights from config.json with estimated marking per value, a zeroed last result after a crash treated as no CLI result, costBasis unknown as a gap, attempts without a CLI result with an estimated value from the historical median rate; value and tokens by_role, by_model, by_task and by_role_model; the attempts list; in_attempts from a shell tool_use (shell tool names from config.json) to the tool_result with its id by command categories from config.json including read and other (background commands only counted), repeat_runs as a heuristic lower bound shown as „możliwe powtórzenia testów”, never as wasted work (a test, e2e or check run whose key, the category plus the fragment matched by the category pattern up to the nearest shell operator, equals an earlier run in the same attempt without a code change in between: Edit, Write, NotebookEdit or an other command; read, ci_watch, stack and the test categories are neutral; a run is counted only when the absence of a code change is certain: a background command, a tool call without tool_result or an unfinished subagent in between excludes it; the command text is compared in memory and never stored), api_retry (system/api_retry count and retry_delay_ms) and permission_denials (system/permission_denied events per tool name); by_task work including attempts after epic_done; versions (CLI version from system/init, models from assistant messages); subscription share for overlapping epics. Not: trend, bottlenecks, render, waste, decide.",
      "acceptance_criteria": [
        "On the DEPLOY-OPS fixture value.by_role sums to the sum of attempt values, and the timeout attempt DEPLOY-OPS-4 number 3 has tokens and an estimated value marked estimated with a gaps entry.",
        "An attempt with several result messages (fixture CATALOG-ADMIN-INTEGRATION-2) has the value of the last one, equal to attempts.cost_usd; assistant messages with the same message.id count once; a model in modelUsage without messages goes to the role other.",
        "Token rules, tested on real fixtures of attempts with subagents and with several result messages, including one with both: orchestrator output equals the sum of usage.output_tokens over all results, no token is counted twice (per model and token kind the role sums never exceed modelUsage), the role outputs on a model sum to modelUsage.outputTokens of that model, input and cache per role sum to modelUsage on an attempt without a mismatch (a surplus goes to other, a shortfall marks the roles estimated with a gap); the output of one subagent type and the value of a role sharing its model are marked estimated, a role alone on its model has an exact value; a zeroed last result with non-zero message tokens is an attempt without a CLI result; costBasis unknown gives a gaps entry.",
        "in_attempts: time from tool_use to the tool_result with its id per category; a background command only in the count; repeat_runs counts a test command with the same key (also with a different appended | tail or redirect) without a code change in between and does not count it after Edit, Write, NotebookEdit or an other command, nor a different target of the same category, nor when a background command, a tool call without tool_result or an unfinished subagent lies in between; api_retry and permission_denials (from system/permission_denied events, also of subagents) come from the fixtures; a record built from a transcript whose command contains a /Users/ path contains no command text.",
        "versions lists the CLI versions and the models of assistant messages with attempt counts; subscription.share is the token value share of the epic in the overlapping interval, and 1 without overlap; the raw delta stays next to it."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-1"],
      "paths": ["tools/metrics/metrics.py", "tools/metrics/"],
      "parallel_with": []
    },
    {
      "id": "METRICS-3",
      "title": "Trend, bottlenecks, post-merge regressions, CI times and report rendering",
      "scope": "tools/metrics/metrics.py and its tests: the trend command with the review, gate_wait and repeat_runs columns and the annotation of a CLI version or model change (spec: Historia i trend), bottlenecks in the record including in_attempts items with their repeat_runs share, the gate_wait candidate (share of gate_wait + to_done; 0 without waiting, null with a gap and skipped without gate.jsonl entries) and the review candidate (share of to_merge, only after merge) (spec: Wąskie gardła), regressions and found_after_merge with the 14-day window and the write-permission check of issue authors through gh (spec: Regresje po merge'u), ci per task PR and final PR (spec: Rekord epiku: ci), the render command with --format report (bottlenecks first, estimated values with „≈”) and --format comment (field allowlist, the manual fixes block in the format retro.py collect reads) (spec: Raport odbioru i komentarz w issue, Model zagrożeń paths 1 and 2). .github/ISSUE_TEMPLATE/bug.yml gets the optional field „Wprowadzone przez (epik albo PR, jeśli znane)” (spec: Zmiany dokumentacji). Not: waste, decide, skill changes.",
      "acceptance_criteria": [
        "trend: median per column with nulls skipped, the position of the given epic against the median, regressions recomputed for records with an open window, and an annotation when the CLI version or the model set differs from the previous epic.",
        "bottlenecks: at most 3 items in descending order of excess hours, an excess below 0.5 h is left out, every candidate kind (breakdown category, task, in_attempts category with its repeat_runs share, gate_wait, review) has a test, and every item has its time cost (excess hours and share of to_done; gate_wait of gate_wait + to_done; review of to_merge) and the related decision rule key or null; gate_wait without gate.jsonl entries is skipped with a gap; review is not in the acceptance report and is in the post-merge block.",
        "Regressions with a stubbed gh: a red CI run on main for the merge commit, a revert of the final PR and a bug issue whose „Wprowadzone przez” field names the epic count when the author has write permission and do not count for read permission; nothing outside the 14-day window counts; /sweep proposals go to found_after_merge.",
        "render --format comment contains the line „Ręczne poprawki: N” and its list in the format retro.py collect parses (test with its parser) and no text from run data (error message, /Users/ path, command in the test record).",
        "render --format report starts with the bottlenecks, shows estimated values with „≈” and the role breakdown with the note that the split between subagents is estimated, and contains every field listed in the spec section Raport odbioru i komentarz w issue."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-2"],
      "paths": ["tools/metrics/metrics.py", "tools/metrics/", ".github/ISSUE_TEMPLATE/bug.yml"],
      "parallel_with": []
    },
    {
      "id": "METRICS-4",
      "title": "Waste alarms during a run",
      "scope": "tools/metrics/metrics.py and its tests: the waste <AO_HOME> command with rules W1, W2, W3 and W4 (no cost estimate for a running attempt), thresholds from config.json (cost alarms (W1, W4) need more than 3x the median and at least 5 USD, the time alarm (W2) more than 3x the median and more than 1 h, W3 independent of both), at least 5 historical measurements for the median rules, waste.json written under waste.lock so every alarm is printed once also with two parallel sessions, busy_timeout and a retry on a locked live database, one JSON line per new alarm with a push text of at most 200 characters (spec: Bezproduktywne zużycie, Decyzje użytkownika Q2). Not: stopping attempts.",
      "acceptance_criteria": [
        "Every rule alarms once on its fixture, also with two parallel waste calls on the same state.",
        "A cost alarm (W1, W4) is not raised at 3x the median below 5 USD nor at 5 USD below 3x the median; the time alarm (W2) is not raised below 1 h; W3 alarms regardless of cost and time.",
        "With fewer than 5 historical measurements W1, W2 and W4 do not alarm and the output records the gap; blocked attempts and usage_limit do not count towards W3.",
        "Error paths: unreadable waste.json, missing AO directory and locked database end with a code and a message, without a duplicate alarm."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-2"],
      "paths": ["tools/metrics/metrics.py", "tools/metrics/"],
      "parallel_with": ["METRICS-7"]
    },
    {
      "id": "METRICS-5",
      "title": "Optimization decision candidates D1-D4 and the measured effect of a decision",
      "scope": "tools/metrics/metrics.py and its tests: the stateless decide <epik> [--history] command with rules D1 (role model, reading only status, mode and subagent_model from the EVALS 2.0 run meta.json files under the results directory from config.json when present; never proposes a model change: a role outside the EVALS 2.0 role list is „bez evali”, a role without a counted run on the cheaper model gets a proposal of an EVALS 2.0 variant comparison, a role with such runs is „porównanie wykonane” without a proposal), D2 (plan split by task work), D3 (parallel work queue from gate_wait) and D4 (waiting shares, including review against to_merge), thresholds from config.json, each candidate with key, data, proposal, expected effect and verification measure, ordering by expected wall-clock saving then token value, the potential optimization effect of every bottleneck (expected wall-clock saving of its rule in hours, without a rule the excess as an upper bound marked „bez reguły decyzji”) shown by render --format report next to its time cost, --history listing candidates for every merged epic as input for /retro, estimated values marked in candidate data, and the effect <key> --at <time> command: the measure of the rule from the spec table (D1 role value per task in USD, D2 plan task work in h, D3 gate_wait per epic in h, D4 waiting share per epic in pp), the after window of epics started after --at (epics spanning --at skipped), the before window of the same number of epics merged closest before --at, median, p25–p75 and n of both, the difference in the unit and percent, time to epic_done per plan task as the median of 3 epics before and after, the CLI versions and models that differ between the windows, and „za mało danych” below 15 tasks from at least 3 epics after (task measures) or 3 epics after (epic measures) (spec: Decyzje optymalizacyjne, Wąskie gardła). Not: storing decisions (no decision registry), /retro changes.",
      "acceptance_criteria": [
        "D1–D4 fire on their fixtures with the thresholds from config.json and stay silent just below them (D4 also for review against to_merge); each candidate has key, data, proposal, expected effect and verification measure.",
        "Candidates are ordered by expected wall-clock saving, then token value; the report part lists at most 3.",
        "D1 on EVALS 2.0 result directories with the fields of the real meta.json: a missing directory or no meta.json records the gap and produces no D1; only runs on the more expensive model give the proposal of a variant comparison; a counted run (status ok, mode replay) on the cheaper model gives „porównanie wykonane” without a proposal; squash, infra_error and budget_stop runs on the cheaper model are not counted; a role outside the list is „bez evali”; an unreadable meta.json is skipped and counted in gaps; no D1 result proposes a model change, and results without a proposal are listed among the skipped rules with their reason, not as candidates.",
        "Every bottleneck in the report shows its time cost and potential effect, e.g. „human_wait: +6,2 h ponad medianę (22% czasu), potencjalnie −4,1 h (D4)”; a bottleneck without a rule shows the excess marked „bez reguły decyzji”.",
        "--history lists candidates per merged epic with stable keys (the same key for the same rule and subject in different epics).",
        "effect: on a fixture history before and after --at it prints the measure of the rule, median, p25–p75 and n of both windows, the difference in the unit and percent, the epic time from 3 epics before and after and the version changes between windows; an epic started before --at and merged after it is in neither window; below the count thresholds it prints „za mało danych” and no difference."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-3"],
      "paths": ["tools/metrics/metrics.py", "tools/metrics/"],
      "parallel_with": []
    },
    {
      "id": "METRICS-6",
      "title": "Process documentation: the ADR component and the Agentic Software House plan",
      "scope": "docs/adr/agentic-software-house.md (component „Metryki i evale” points to tools/metrics and tools/evals-pilot) and docs/features/agentic-software-house/plan.md (step 18 split into METRICS and TELEMETRY #577, role „Pomiar czasu, kosztu i jakości” pointing to tools/metrics/README.md, process point 5: the issue comment is the block „Pomiar epiku”) (spec: Zmiany dokumentacji). Not: AO, telemetry.",
      "acceptance_criteria": [
        "The plan and the ADR describe the same behaviour."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-3", "METRICS-4", "METRICS-5"],
      "paths": ["docs/adr/agentic-software-house.md", "docs/features/agentic-software-house/plan.md"],
      "parallel_with": ["METRICS-7"]
    },
    {
      "id": "METRICS-7",
      "title": "/retro: optimization incidents and the decisions section with measured effect",
      "scope": ".claude/skills/retro/retro.py and test_retro.py (collect adds metrics.py decide --history as incidents of kind optimization with IDs <epik>/optimization/<key>, the retrospected epic record and trend as facts in input.json; validate accepts optimization incidents in the existing threshold, registries and limits without a special case), .claude/skills/retro/SKILL.md, .claude/agents/retro-analyst.md and docs/features/retro/spec.md (the report ends with the section „Decyzje optymalizacyjne”: items from optimization incidents, observations, and for keys in applied.jsonl the effect printed by metrics.py effect with --at set to the merge of the fix PR, copied without the agent computing anything: median, spread and n before and after, epic time, version changes or „za mało danych”) (spec: Decyzje optymalizacyjne, Zmiany dokumentacji). Not: metrics.py.",
      "acceptance_criteria": [
        "input.json contains optimization incidents and the measurement facts; an item based on two optimization incidents from different epics passes retro.py validate and an item based on one does not.",
        "The report instructions end with the section „Decyzje optymalizacyjne” with the output of metrics.py effect for keys in applied.jsonl (median, spread and n before and after, epic time, version changes) or „za mało danych”, and the agent does not compute the effect itself.",
        "A failing metrics.py call (missing script, non-zero exit) gives a fact „brak danych pomiaru” and collect still produces the other incidents; the retro tests pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-5"],
      "paths": [".claude/skills/retro/", ".claude/agents/retro-analyst.md", "docs/features/retro/spec.md"],
      "parallel_with": ["METRICS-4", "METRICS-6"]
    }
  ]
}
```
