# AGENT-SECURITY – plan zadań

Specyfikacja: `docs/features/agent-security/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | AGENT-SECURITY-1, AGENT-SECURITY-3, AGENT-SECURITY-4 | |
| 2 | AGENT-SECURITY-2, AGENT-SECURITY-5 | |

Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "AGENT-SECURITY",
  "spec": "docs/features/agent-security/spec.md",
  "tasks": [
    {
      "id": "AGENT-SECURITY-1",
      "title": "GitHub origin gate for agent input, used by /groom and /prepare-epic, and the agent security ADR",
      "scope": "tools/agent-security/github_trust.py with tests (spec R1: trusted author = write/maintain/admin from collaborators/<login>/permission, untrusted record = number/author/url/trusted:false, comments judged one by one, gh error fails closed, subcommands issues/issue/prs/comments with --repo, importable module). /groom: groom.py collect builds input.json through the gate, prints untrusted issues to the user as external reports, rejects proposals and fix epics with untrusted numbers; SKILL.md and backlog-groomer.md. /prepare-epic: SKILL.md (refuse start for an untrusted epic issue, untrusted fix-epic issues go to out of scope) and spec-critic.md (reads issues through the gate). Docs: new docs/adr/agent-security.md (threat model of the spec, decisions R1-R7, gaps with #498, user recommendations, explicit statement that the sandbox does not protect against deliberate bypass through Docker and the AO lifecycle, path 15 and the P13 hypothesis: ruleset bypass only for the admin role, interactive sessions and AO with the user's token as a prompt-level gap), docs/adr/agentic-software-house.md row, docs/features/groom/spec.md, docs/features/prepare-epic/spec.md. Not: /sweep, /retro, /integration, /ao-run scripts (AGENT-SECURITY-2, -5).",
      "acceptance_criteria": [
        "Gate tests with a fake gh: an author with write, maintain or admin gets the full record with trusted true; read, triage, 404, a [bot] account and ghost get only number, author, url and trusted false; each comment is judged by its own author; a gh error or an unexpected status ends with a non-zero exit and no output; one permission call per login per invocation.",
        "groom.py tests: input.json has no title or body of an untrusted issue; the user summary lists untrusted issues as external reports with number, author and link; a proposal or fix epic naming an untrusted issue is rejected by validation.",
        "/prepare-epic SKILL.md reads the epic issue and fix-epic issues through the gate, refuses to start for an untrusted epic issue with the reason to re-file it, and moves untrusted fix-epic issues to the out-of-scope section with a reason; spec-critic.md reads issues through the gate.",
        "docs/adr/agent-security.md exists with context, threat model (assets, parties, in-scope paths with their checks, gaps with #498), decisions, rationale, consequences and the explicit statement that the sandbox is not protection against deliberate bypass; the agentic-software-house ADR row points to it; groom and prepare-epic specs describe the gate and external reports.",
        "Script tests (.github/scripts/script-tests.sh) pass."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "AGENT-SECURITY-2",
      "title": "Origin gate in /sweep, /retro and /integration",
      "scope": "Spec R1 table rows /sweep, /retro, /integration: sweep SKILL.md builds open-issues.json through tools/agent-security/github_trust.py, tester.md and ux-reviewer.md fallbacks use the gate; retro.py takes measurement comments only from trusted authors, reads PRs into epic/* and AO issues through the gate (AO issues judged by permission in piotrp321/agentic-orchestrator), retro SKILL.md and retro-analyst.md read issues and PRs through the gate; integration SKILL.md reads task PR bodies through the gate. Docs: docs/features/agent-tester-ux/spec.md, docs/features/retro/spec.md. Not: the gate module itself (AGENT-SECURITY-1), /ao-run scripts (AGENT-SECURITY-5).",
      "acceptance_criteria": [
        "retro.py tests: a measurement comment (\"Ręczne poprawki: N\") from an untrusted author does not change the measurement or create manual_fix incidents; untrusted PR bodies and AO issues appear only as number, author and link in input.json.",
        "sweep SKILL.md, tester.md and ux-reviewer.md fetch open issues only through the gate; open-issues.json has no title or body of an untrusted issue.",
        "integration SKILL.md and retro-analyst.md read PR and issue content only through the gate; no direct gh --json body/title/comments call that feeds an agent remains in sweep, retro or integration.",
        "agent-tester-ux and retro specs describe the gate.",
        "Script tests (.github/scripts/script-tests.sh) pass."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "AGENT-SECURITY-1"
      ]
    },
    {
      "id": "AGENT-SECURITY-3",
      "title": "Executor sandbox profile, claude-agent wrapper and bypass probe",
      "scope": "tools/agent-security: executor-settings.json template (spec R2: sandbox enabled, failIfUnavailable, allowUnsandboxedCommands false, no excludedCommands, enableWeakerNetworkIsolation, denyRead ~ with the minimal allowRead list, per-task AO_HOME paths from AO_TASK_ID, denyWrite for .ao, .git hooks/config/worktrees config, task briefs and attempts, GitHub domains, Docker socket, allowLocalBinding, permissions blockReadsOutsideWorkingDirectories, additionalDirectories, deny WebFetch/WebSearch/.ao edits/secret reads, disableBypassPermissionsMode, disableAllHooks); render_settings.py callable on its own (--workdir, --git-common-dir, repeatable --state-dir, --read-dir and --deny-write, --no-network, --no-docker; access options widen access, so paths come only from trusted callers and are validated: absolute, existing, no symlink component, not /, ~ or its ancestor, not overlapping protected directories; the template's security rules cannot be removed; used by EVALS #495); claude-agent wrapper (POSIX sh: pinned binary from AGENT_CLAUDE_BIN, generated --settings JSON and --strict-mcp-config, --version and auth status passthrough without settings (AO preflight), --setting-sources project, fresh main SHA via ls-remote/fetch for the P12 check, DOCKER_CONFIG without credsStore if the docker work cases need it, refusal on missing variables, unknown task or settings/.mcp.json changed by the branch or working tree against the merge-base with origin/main, executor token from Keychain shop-agent-github-token as GH_TOKEN, GH_CONFIG_DIR, git over HTTPS with a credential helper via GIT_CONFIG_*, SSH_AUTH_SOCK removed); probe.py and probe-cases.sh (spec R6: real executor process through the wrapper, Haiku, default technical pass with dontAsk and exact allow rules where all Bash cases run in one probe-cases.sh call and the model runs only the tool cases, --mode ao pass judged only by no leak, all bypass and work cases, fail-closed with timeout and cost limit, result JSON with cases, time, cost and reason, cleanup of processes, labelled containers, worktree, canaries and fake AO_HOME after every exit, CLAUDE* variables cleared before the nested claude, tool cases Read/Grep/Glob, CLAUDE.md import and skill ! command (spec path 3a), git redirection, symlink and hard-link reads of the canary, moved refs/remotes/origin/main, user settings not merged, docker compose build/exec and docker run -v worktree work cases, spec R8 token case: current_user_can_bypass of the main-merge and epic-merge rulesets read with the executor token must be never, a missing ruleset is a fail with a reason). The host run with a model is attempted; if the executor's classifier stops it, the task reports that (the run happens at epic acceptance). Tests without a model for profile, wrapper and probe evaluation. Not: /ao-run integration (AGENT-SECURITY-5), CLAUDE.md.",
      "acceptance_criteria": [
        "Profile tests: allowUnsandboxedCommands false, failIfUnavailable true, denyRead contains ~, no excludedCommands, disableAllHooks true, deny rules for WebFetch, WebSearch and .ao edits, denyWrite for .ao, .git hooks, .git config and worktree configs.",
        "render_settings.py tests: options give the expected paths; --no-network and --no-docker drop the GitHub domains, allowLocalBinding and the Docker socket; a relative, missing or symlinked path (any component), /, ~ or its ancestor, and a path that is an ancestor, descendant or equal of a protected directory are errors; no option removes denyRead, denyWrite, allowUnsandboxedCommands false, disableAllHooks or the deny rules. Wrapper tests: AGENT_AO_HOME outside ~/.local/state/ao-* or a task directory outside $AGENT_AO_HOME/tasks (also through a symlink) is refused.",
        "Wrapper tests: --version and auth status pass through unchanged without AO_TASK_ID and without settings; --setting-sources project is added; the P12 check uses the main SHA from ls-remote even when refs/remotes/origin/main was moved.",
        "Wrapper tests with a fake binary, git and security: AO arguments pass unchanged with --settings and --strict-mcp-config added; --version passes unchanged; generated settings contain only the task directory (and the epic integration directory for <EPIC>-INTEGRATION-<n>) of AO_HOME; missing AGENT_CLAUDE_BIN, AGENT_AO_HOME, AO_TASK_ID or token, an unknown task, or .claude/settings*.json or .mcp.json changed by the branch, the working tree or untracked files against the merge-base with origin/main (but not by a later change on main) end with a non-zero exit and a message; GH_TOKEN, GH_CONFIG_DIR and the git variables are set and SSH_AUTH_SOCK is removed.",
        "Probe tests without a model: a canary in a tool result, a missing case, a case not executed, a cost over the limit, truncated output and a binary that never ends (timeout) all give exit 1 with a reason; a start failure gives exit 2; cleanup runs after an error and after SIGINT.",
        "The probe is attempted on the host with the user's prerequisites; when it runs, it exits 0 in the default pass (every bypass case refused by the system or the permission layer, including Read/Grep/Glob, the CLAUDE.md import and skill ! command, git redirection and the moved origin/main ref; every work case passes, including git push -u to the probe's local remote and docker compose build/exec) and in --mode ao (no canary in the output, no write outside the allowed paths); result, time and cost or the reason it could not run (e.g. stopped by the executor's classifier) are in the PR description. The 403 on pending_deployments is confirmed on the real token or replaced by another check of the missing Deployments permission.",
        "Probe tests without a model for the R8 case: current_user_can_bypass never passes; pull_requests_only, always or a missing main-merge or epic-merge ruleset fail with a reason. When the rulesets already exist in GitHub, the host run reports the value for the executor token and for the user's token; if the executor token gets the same value as the user's token or anything other than never, the task reports a blocker with the result instead of finishing (spec P13).",
        "Error paths on the host: the probe with a test template without denyRead exits 1 naming the canary case; without the token it exits 2; the docker case with the canary directory inside a shared directory exits 1; after a run and after SIGINT no probe processes, containers, worktree or files remain.",
        "Script tests (.github/scripts/script-tests.sh) pass.",
        "If the probe's hard-link case lets the canary be read, the task reports a blocker with the result instead of finishing."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "AGENT-SECURITY-4",
      "title": "Production gates: deploy environment, Actions secrets only on main, no production targets during an AO run",
      "scope": "Spec R5: .github/workflows/deploy.yml job deploy with environment production; infra/k8s/tests/test_deploy_workflow.py checks every .github/workflows/*.yml (every expression with the secrets context other than secrets.GITHUB_TOKEN, including workflow-level env, secrets['X'], toJSON(secrets) and secrets: inherit, only in jobs with an environment limited to main; deploy job with environment production); if SENTRY_AUTH_TOKEN is already on main, its job gets a main-only environment. infra/k8s Makefile and scripts: kubeconfig, rerun, create-admin, restore and backups (the targets that write or mount KUBE_DIR) refuse without changes while a `python3 -m orchestrator ... run` process or a live executor (claude-agent process or AO runner) runs; kubeconfig records KUBE_DIR in ~/.local/state/shop-ops/kube-dirs (read by ao-prepare in AGENT-SECURITY-5) (confirmation tak stays on stdin), with tests on a fake process list. Docs: infra/k8s/README.md runbook (environment production with required reviewer, Prevent self-review off, main-only branch policy, DEPLOY_KEY as environment secret and removal of the repository secret after merge, KUBE_DIR created per operation and removed, approving deployments and the main concurrency queue), infra/k8s/CLAUDE.md, docs/features/deploy/spec.md, CLAUDE.md lines on Actions secrets and production targets. Not: smoke test and selftest behaviour (unchanged).",
      "acceptance_criteria": [
        "test_deploy_workflow.py: the deploy job has environment production; no expression with the secrets context other than secrets.GITHUB_TOKEN appears outside jobs with a main-only environment in any workflow; the test fails on a copy of deploy.yml without the environment and on fixtures using workflow-level env, secrets['X'], toJSON(secrets) and secrets: inherit.",
        "With a fake running AO process or a fake live executor, kubeconfig, rerun, create-admin, restore and backups exit non-zero without changes and with a message; kubeconfig records KUBE_DIR in the registry; without them the targets behave as today; make -C infra/k8s check passes, including the selftest and the existing script tests.",
        "infra/k8s/README.md, infra/k8s/CLAUDE.md, docs/features/deploy/spec.md and CLAUDE.md describe the environment, the secrets rule, the user steps and the AO-run refusal; no document still says DEPLOY_KEY is a repository secret.",
        "make check and the script tests pass."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "AGENT-SECURITY-5",
      "title": "/ao-run starts AO through the wrapper after the probe; gate in the night queue",
      "scope": "Spec R7: tools/agent-security/ao-prepare (after git fetch origin main, copy of claude-agent, render_settings.py, executor-settings.json, probe.py and probe-cases.sh from origin/main via git show, not the session working tree, to $AO_HOME/agent-security; refusal while a KUBE_DIR from ~/.local/state/shop-ops/kube-dirs still exists; probe run from the copy ($AO_HOME/agent-security/probe.py, never the session working tree) on the copied wrapper and profile with the pinned binary from claude_pin.py, output --claude-bin and AGENT_CLAUDE_BIN, AGENT_AO_HOME; fail-closed with time, cost and reason) with tests; .claude/skills/ao-run/SKILL.md section Start (and resume) runs ao-prepare before every ao run, starts AO with its output, sends a push with the reason on failure and puts probe time and cost in the acceptance report; before merging the final PR of AGENT-SECURITY itself the session runs the probe default pass and --mode ao and reports the result (spec criterion 6), and checks with the user's token that current_user_can_bypass of main-merge and epic-merge is pull_requests_only while the probe reported never for the executor token (spec criterion 11; same value or missing rulesets: blocker to the user). Spec R1 /ao-run row: next_epic.py and night_queue.py read epic issues through tools/agent-security/github_trust.py and skip untrusted ones with a reason, with tests. Docs: docs/features/ao-run/spec.md, CLAUDE.md (GitHub content for agents only through the gate, AO executors in the sandbox with the probe before every start, pointer to docs/adr/agent-security.md). Not: profile, wrapper and probe internals (AGENT-SECURITY-3).",
      "acceptance_criteria": [
        "ao-prepare tests with a fake probe, git and claude_pin.py: all five files including probe.py are copied from origin/main even when the session working tree differs; the probe is started from the copy (a changed probe.py in the working tree is not run); a registered KUBE_DIR that still exists stops it; the probe runs on the copy; the output has --claude-bin pointing to the copy and the variables; a probe exit 1 or 2, a timeout or a missing result ends ao-prepare with a non-zero exit and the reason.",
        "ao-run SKILL.md acceptance of AGENT-SECURITY compares current_user_can_bypass of main-merge and epic-merge for the user's token (pull_requests_only) and the executor token (never, from the probe) and stops with a blocker otherwise.",
        "ao-run SKILL.md runs ao-prepare before every ao run and every resume, never skips it, starts AO only with its output and sends a push with the reason when it fails.",
        "next_epic.py and night_queue.py tests: an epic issue from an untrusted author is not an epic of the queue and is reported with a reason; no title or body of it is printed.",
        "docs/features/ao-run/spec.md and CLAUDE.md describe the gate rule, the executor sandbox and the probe before every start, pointing to the ADR.",
        "Script tests (.github/scripts/script-tests.sh) and make check pass."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "AGENT-SECURITY-1",
        "AGENT-SECURITY-3",
        "AGENT-SECURITY-4"
      ]
    }
  ]
}
```
