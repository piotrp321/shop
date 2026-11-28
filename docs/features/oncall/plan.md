# ONCALL – plan zadań

Specyfikacja: `docs/features/oncall/spec.md`. Każde zadanie to osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ONCALL-1 | |
| 2 | ONCALL-2 | |
| 3 | ONCALL-3 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

Kontrakt między ONCALL-1 (`verdict.py`) a ONCALL-2 (`oncall.py`) to słownik sygnałów punktu kontrolnego:

```text
{
  "sha": "<40 hex>", "previous_sha": "<40 hex>|null", "t0": "<ISO 8601 UTC>", "point_h": 1,
  "cluster": {"k1": ["api"], "k2": {"worker": 3}, "k3": ["store"], "k4": false, "k5": [], "k6": false},
  "sentry": {"new_issues": [{"short_id": "STORE-1A", "events": 31}], "regressions": 0,
             "errors": {"previous": 12, "new": 40}, "dropped": 0},
  "source_errors": [{"source": "metrics", "reason": "token"}],
  "skipped_points": [1], "intermediate": ["<40 hex>"], "reference_tags": ["<40 hex>"]
}
```

- Nazwy workloadów należą do zamkniętej listy z `cluster.json`: `api`, `worker`, `store`, `admin` i `elasticsearch`.
- `source` należy do listy `metrics` i `sentry`.
- `reason` należy do listy `token`, `network`, `config`, `rate_limit`, `page_limit`, `http` i `no_metric`.
- `short_id` jest zgodny z `^[A-Z0-9-]{1,32}$` albo równy `?`.
- Liczby Sentry mają wartość `null` przy błędzie Sentry albo braku wersji poprzedniej.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ONCALL",
  "spec": "docs/features/oncall/spec.md",
  "tasks": [
    {
      "id": "ONCALL-1",
      "title": "Hypercare verdict, push text and report section as pure functions",
      "scope": ".claude/skills/oncall/verdict.py and .claude/skills/oncall/test_verdict.py (Python 3.9+ standard library only, no I/O, no network): decide(signals) returns the verdict and the reason by the first matching rule (ROLLBACK ZALECANY: K1 or at least 3 K2 restarts in one workload; NIEROZSTRZYGNIĘTE: K3, K4, K5, K6, a source error, Sentry dropped events, a new issue with at least 20 events; otherwise OK), push_text(signals, decision) returns the push of at most 200 characters in the format of the spec with the runbook suffix for ROLLBACK ZALECANY, „?” for missing reference numbers and the shortening order (Sentry numbers first, then the reason; the verdict and sha7 always stay), report_section(signals, decision) returns the Markdown block of one checkpoint (table of signals and numbers of both windows, skipped points, intermediate versions, reference window tags, Sentry issue URLs built from short IDs in the fixed format of the spec). The input is the signals dictionary from the contract in this plan; values outside the closed lists and patterns are rendered as „?” (spec: Hypercare: Werdykt, Push, Raport; Model zagrożeń O1, O2, O6, O7; Kryteria akceptacji 3, 4). Not: reading any source, state, files, the skill.",
      "acceptance_criteria": [
        "Every row of the verdict table in criterion 3 of the spec has a test on a signals dictionary and gives the listed verdict, including 20 versus 19 events, 2 restarts in api and 2 in worker (OK) and K1 together with a new issue (ROLLBACK ZALECANY).",
        "push_text never exceeds 200 characters on the longest data allowed by the contract (5 workloads, 2 source errors, 50 new issues, 7-digit numbers), keeps the verdict and sha7 in every case and contains the runbook suffix for ROLLBACK ZALECANY.",
        "A short ID, sha, workload, source or reason outside its pattern or list (including text with an attack instruction, a URL and Markdown) is rendered as „?” in push_text and report_section.",
        "decide never returns ROLLBACK ZALECANY from Sentry fields alone and never returns OK when K3, K4, K6 or a source error is present (property tests over generated values)."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [".claude/skills/oncall/verdict.py", ".claude/skills/oncall/test_verdict.py"],
      "parallel_with": []
    },
    {
      "id": "ONCALL-2",
      "title": "oncall.py: sources, tag segments and T0, cluster signals, checkpoints, state, lock, run, finish and stats",
      "scope": ".claude/skills/oncall/oncall.py, .claude/skills/oncall/cluster.json, .claude/skills/oncall/test_oncall.py, synthetic fixtures in .claude/skills/oncall/fixtures/ and infra/k8s/tests/test_oncall_cluster.py (Python 3.9+ standard library only): configuration from ~/.config/shop/oncall (prometheus.url, prometheus.user, prometheus.token, sentry.token; 0600, https *.grafana.net), Prometheus query and Sentry REST clients with GET only, no redirects followed, pagination only by cursor on the same host (spec: Model zagrożeń O3–O5), the tag history of api pods (ready or not) from kube_pod_container_info joined with kube_pod_owner with the tag label from the experiment, T0 as the start of the last continuous segment, previous, intermediate and reference window tags, no transition giving „Dyżur aktywny”, cluster.json with the deployed workloads and thresholds (10 min not ready, 15 min silent, 3 restarts), K1–K6 as PromQL range queries on kube-state-metrics (no ALERTS series), Sentry numbers of both windows built into the signals dictionary of the contract in this plan (short IDs validated, titles never requested), verdict, push and report through verdict.py (ONCALL-1), state in ~/.local/state/shop-oncall (state.json written atomically, a corrupt one reinitialised; runs.jsonl; history/), the fcntl.flock lock, source error pushes only on a state change and the point push taking precedence, commands run (one JSON line: push, kind, sources, report), finish --usage-before --usage-after and stats for 7 and 30 days; infra/k8s/tests/test_oncall_cluster.py compares cluster.json with the chart templates (workloads rendering shop.appImage; metrics and for of WorkloadNotReady and ClusterSilent in 00-alerts.yaml) (spec: Przegląd rozwiązania, Przebieg, Hypercare, Model zagrożeń, Kryteria akceptacji 1, 2, 5–7). The state and config directories are parameters with these defaults so tests use temporary directories. Not: SKILL.md, CLAUDE.md, monitoring.yaml, an LLM, MCP, gh, kubectl, SSH.",
      "acceptance_criteria": [
        "Every case of criteria 1 and 2 of the spec is a separate test against a stub HTTP server and passes, including a never-ready new api pod opening hypercare, A→B→A with T0 at the return to A, no transition giving „Dyżur aktywny” and a corrupt state.json being reinitialised.",
        "End to end through run on stubs: the rows of criterion 3 of the spec for K1 (api and elasticsearch at 10 min, elasticsearch at 9 min gives OK), K2, K3, K4, K5, K6 and a Sentry error come out of the real queries, and a Sentry stub with an attack instruction in the title, release, environment and tags never puts that text into the run output, the report or runs.jsonl.",
        "Model zagrożeń O3–O5: a 302 to another host and a Link header with a foreign host never receive the token (the 302 gives the http error), a prometheus.url outside https *.grafana.net or a file other than 0600 gives the config error, the stub rejects any method other than GET and any path outside the allowed list, and a scan of the output, report, state.json and runs.jsonl finds no stub token.",
        "Every reason of the closed list gives a source error with exit code 0 and a valid JSON line; a source error push appears only on a state change and on recovery; an empty run gives push null; a second run during the first gives „przebieg trwa” and the lock is free after an exception and after the process is killed; finish records the kind and window deltas or the gap; stats sums 7 and 30 days.",
        "infra/k8s/tests/test_oncall_cluster.py passes in make -C infra/k8s check and fails on a temporary copy of the templates with an added Deployment using shop.appImage and with a changed for of WorkloadNotReady (both shown)."
      ],
      "risk": "STANDARD",
      "depends_on": ["ONCALL-1"],
      "paths": [".claude/skills/oncall/oncall.py", ".claude/skills/oncall/cluster.json", ".claude/skills/oncall/test_oncall.py", ".claude/skills/oncall/fixtures/", "infra/k8s/tests/test_oncall_cluster.py"],
      "parallel_with": []
    },
    {
      "id": "ONCALL-3",
      "title": "/oncall skill and the documentation of step 11",
      "scope": ".claude/skills/oncall/SKILL.md: the session (Haiku, scheduled hourly 7–23 or manual) calls get_usage, runs oncall.py run, sends the push field verbatim with PushNotification (nothing when null; in a manual session a message instead), calls get_usage again and oncall.py finish; the session never reads the report, state files or API responses and runs no other commands; without get_usage finish records the gap (spec: Przegląd rozwiązania, Przebieg, Model zagrożeń O2). CLAUDE.md: one point (spec: Zmiany innych dokumentów). docs/features/agentic-software-house/plan.md, step 11: cluster from Grafana Cloud metrics instead of make -C infra/k8s targets, the split into ONCALL (hypercare) and ONCALL-INVESTIGATE #708 (agent through MCP, proposals, triage), MCP checked in ONCALL-INVESTIGATE (spec: Zmiany innych dokumentów, Decyzje użytkownika). Not: oncall.py, verdict.py, the scheduled task (created by the user after the merge).",
      "acceptance_criteria": [
        "SKILL.md lists exactly get_usage, oncall.py run, PushNotification, get_usage and oncall.py finish in this order, sends the push field verbatim and states that the session does not read the report, state or API data.",
        "CLAUDE.md has one /oncall point (scheduled or manual, deterministic hypercare with push, read-only tokens, rollback only by the user) and step 11 of the Agentic Software House plan describes the split, metrics instead of make targets and ONCALL-INVESTIGATE #708 without contradicting the spec.",
        "The oncall tests (python3 -m unittest discover -s .claude/skills/oncall) and the script tests found by .github/scripts/script-tests.sh pass."
      ],
      "risk": "STANDARD",
      "depends_on": ["ONCALL-2"],
      "paths": [".claude/skills/oncall/SKILL.md", "CLAUDE.md", "docs/features/agentic-software-house/plan.md"],
      "parallel_with": []
    }
  ]
}
```
