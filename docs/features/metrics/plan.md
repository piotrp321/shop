# METRICS – plan zadań

Specyfikacja: `docs/features/metrics/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
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
      "depends_on": []
    },
    {
      "id": "METRICS-2",
      "title": "Token value and tokens per role, model and task from executor transcripts",
      "scope": "tools/metrics/metrics.py and its tests and fixtures: reading tasks/<id>/attempts/<n>/stdout.jsonl (spec: Pojęcia: Rola, Wartość tokenów; Rekord epiku: value, attempts, in_attempts, share; the paragraph on splitting value by role): assistant messages deduplicated by message.id, roles from subagent_type, the attempt orchestrator by task kind and other for models without messages, the value of the last result of an attempt split by token weights from config.json, attempts without a CLI result with tokens and an estimated value from the historical median rate of the model, value and tokens by_role, by_model, by_task and by_role_model, the attempts list, in_attempts from Bash tool_use to its tool_result by command categories from config.json (background commands only counted), and subscription share for overlapping epics. Not: trend, bottlenecks, render, waste, decide.",
      "acceptance_criteria": [
        "On the DEPLOY-OPS fixture value.by_role sums to the sum of attempt values, and the timeout attempt DEPLOY-OPS-4 number 3 has tokens and an estimated value marked estimated with a gaps entry.",
        "An attempt with several result messages (fixture CATALOG-ADMIN-INTEGRATION-2) has the value of the last one, equal to attempts.cost_usd; assistant messages with the same message.id count once; a model in modelUsage without messages goes to the role other.",
        "in_attempts: time from tool_use to tool_result per category; a background command only in the count.",
        "subscription.share is the token value share of the epic in the overlapping interval, and 1 without overlap; the raw delta stays next to it."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-1"]
    },
    {
      "id": "METRICS-3",
      "title": "Trend, bottlenecks, post-merge regressions, CI times and report rendering",
      "scope": "tools/metrics/metrics.py and its tests: the trend command (spec: Historia i trend), bottlenecks in the record (spec: Wąskie gardła), regressions and found_after_merge with the 14-day window and the write-permission check of issue authors through gh (spec: Regresje po merge'u), ci per task PR and final PR (spec: Rekord epiku: ci), the render command with --format report (bottlenecks first) and --format comment (field allowlist, the manual fixes block in the format retro.py collect reads) (spec: Raport odbioru i komentarz w issue, Model zagrożeń paths 1 and 2). .github/ISSUE_TEMPLATE/bug.yml gets the optional field „Wprowadzone przez (epik albo PR, jeśli znane)” (spec: Zmiany dokumentacji). Not: waste, decide, skill changes.",
      "acceptance_criteria": [
        "trend: median per column with nulls skipped, the position of the given epic against the median, and regressions recomputed for records with an open window.",
        "bottlenecks: at most 3 items in descending order of excess hours, an excess below 0.5 h is left out, every candidate kind (breakdown category, task, in_attempts category) has a test, and every item has its time cost (excess hours and share of to_done) and the related decision rule key or null.",
        "Regressions with a stubbed gh: a red CI run on main for the merge commit, a revert of the final PR and a bug issue whose „Wprowadzone przez” field names the epic count when the author has write permission and do not count for read permission; nothing outside the 14-day window counts; /sweep proposals go to found_after_merge.",
        "render --format comment contains the line „Ręczne poprawki: N” and its list in the format retro.py collect parses (test with its parser) and no text from run data (error message, /Users/ path, command in the test record).",
        "render --format report starts with the bottlenecks and contains every field listed in the spec section Raport odbioru i komentarz w issue."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-2"]
    },
    {
      "id": "METRICS-4",
      "title": "Waste alarms during a run",
      "scope": "tools/metrics/metrics.py and its tests: the waste <AO_HOME> command with rules W1, W2a, W2b, W3 and W4, thresholds from config.json (cost alarms need more than 3x the median and at least 5 USD, time alarms more than 3x the median and more than 1 h, W3 independent of both), at least 5 historical measurements for the median rules, waste.json written under waste.lock so every alarm is printed once also with two parallel sessions, busy_timeout and a retry on a locked live database, one JSON line per new alarm with a push text of at most 200 characters (spec: Bezproduktywne zużycie, Decyzje użytkownika Q2). Not: the /ao-run integration (METRICS-6), stopping attempts.",
      "acceptance_criteria": [
        "Every rule alarms once on its fixture, also with two parallel waste calls on the same state.",
        "A cost alarm (W1, W2b, W4) is not raised at 3x the median below 5 USD nor at 5 USD below 3x the median; a time alarm (W2a) is not raised below 1 h; W3 alarms regardless of cost and time.",
        "With fewer than 5 historical measurements W1, W2a, W2b and W4 do not alarm and the output records the gap; blocked attempts and usage_limit do not count towards W3.",
        "Error paths: unreadable waste.json, missing AO directory and locked database end with a code and a message, without a duplicate alarm."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-2"]
    },
    {
      "id": "METRICS-5",
      "title": "Optimization decision candidates D1-D4",
      "scope": "tools/metrics/metrics.py and its tests: the stateless decide <epik> [--history] command with rules D1 (role model, using tools/evals/history when present), D2 (plan split), D3 (parallel work queue from gate_wait) and D4 (waiting shares), thresholds from config.json, each candidate with key, data, proposal, expected effect and verification measure, ordering by expected wall-clock saving then token value, the potential optimization effect of every bottleneck (expected wall-clock saving of its rule in hours, without a rule the excess as an upper bound marked „bez reguły decyzji”) shown by render --format report next to its time cost, and --history listing candidates for every merged epic as input for /retro (spec: Decyzje optymalizacyjne, Wąskie gardła). Not: storing decisions (no decision registry), /retro and /ao-run changes.",
      "acceptance_criteria": [
        "D1–D4 fire on their fixtures with the thresholds from config.json and stay silent just below them; each candidate has key, data, proposal, expected effect and verification measure.",
        "Candidates are ordered by expected wall-clock saving, then token value; the report part lists at most 3.",
        "Without tools/evals/history D1 is not produced and the output records the gap; a role without an eval suite is listed as without evals.",
        "Every bottleneck in the report shows its time cost and potential effect, e.g. „human_wait: +6,2 h ponad medianę (22% czasu), potencjalnie −4,1 h (D4)”; a bottleneck without a rule shows the excess marked „bez reguły decyzji”.",
        "--history lists candidates per merged epic with stable keys (the same key for the same rule and subject in different epics)."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-3"]
    },
    {
      "id": "METRICS-6",
      "title": "/ao-run: measurement in the acceptance report, waste pushes and the post-merge comment",
      "scope": ".claude/skills/ao-run/SKILL.md and docs/features/ao-run/spec.md: the acceptance report (point 5) gets the sections „Pomiar” from metrics.py render --format report and „Decyzje optymalizacyjne” from metrics.py decide (at most 3, without recording decisions), the existing report numbers come from the record; waste on every wake-up by an AO event and every Monitor re-arm, each alarm as a push; the post-merge comment (point 6) is the block „Pomiar epiku” from render --format comment with --manual-fixes; trend after every merge and at the start of /ao-run; backfill when the record directory is empty (spec: Bezproduktywne zużycie, Decyzje optymalizacyjne, Raport odbioru i komentarz w issue, Po merge'u). docs/adr/agentic-software-house.md (component „Metryki i evale” points to tools/metrics and tools/evals) and docs/features/agentic-software-house/plan.md (step 18 split into METRICS and TELEMETRY #577, role „Pomiar czasu, kosztu i jakości” pointing to tools/metrics/README.md, process point 5: the issue comment is the block „Pomiar epiku”) (spec: Zmiany dokumentacji). Not: AO, pairs.py, telemetry.",
      "acceptance_criteria": [
        "The acceptance report instructions produce the section „Pomiar” starting with the bottlenecks and the section „Decyzje optymalizacyjne” (also with no candidates), and the post-merge comment instructions produce the block „Pomiar epiku” with the manual fixes in the format retro.py collect reads.",
        "The skill calls waste on every wake-up by an AO event and on every Monitor re-arm and sends each alarm as a push, without stopping anything.",
        "The skill runs trend after each merge and at start, and backfill when ~/.local/state/shop-metrics/epics is empty.",
        "docs/features/ao-run/spec.md, the plan and the ADR describe the same behaviour; the ao-run skill tests (python3 -m unittest discover -s .claude/skills/ao-run) pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-3", "METRICS-4", "METRICS-5"]
    },
    {
      "id": "METRICS-7",
      "title": "/retro: optimization incidents and the decisions section with measured effect",
      "scope": ".claude/skills/retro/retro.py and test_retro.py (collect adds metrics.py decide --history as incidents of kind optimization with IDs <epik>/optimization/<key>, the retrospected epic record and trend as facts in input.json; validate accepts optimization incidents in the existing threshold, registries and limits without a special case), .claude/skills/retro/SKILL.md, .claude/agents/retro-analyst.md and docs/features/retro/spec.md (the report ends with the section „Decyzje optymalizacyjne”: items from optimization incidents, observations, and for keys in applied.jsonl the effect as the median of 3 epics before and after the merge of the fix PR for the decision measure and for time to epic_done per plan task, in hours and percent, or „za mało danych”) (spec: Decyzje optymalizacyjne, Zmiany dokumentacji). Not: metrics.py, /ao-run.",
      "acceptance_criteria": [
        "input.json contains optimization incidents and the measurement facts; an item based on two optimization incidents from different epics passes retro.py validate and an item based on one does not.",
        "The report instructions end with the section „Decyzje optymalizacyjne” with the effect before and after for keys in applied.jsonl (difference in hours and percent) or „za mało danych” when fewer than 3 epics follow the fix.",
        "A failing metrics.py call (missing script, non-zero exit) gives a fact „brak danych pomiaru” and collect still produces the other incidents; the retro tests pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["METRICS-5"]
    }
  ]
}
```
