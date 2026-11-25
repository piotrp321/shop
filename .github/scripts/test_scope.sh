#!/bin/sh
# Tests of scope.sh on throwaway git repositories: one per case, base commit + one change commit.
set -u

script="$(cd "$(dirname "$0")" && pwd)/scope.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0

# new_repo <name>: creates a repo with a base commit holding one code file and one doc.
new_repo() {
    repo="$tmp/$1"
    git init -q "$repo"
    git -C "$repo" config user.email ci@example.test
    git -C "$repo" config user.name CI
    mkdir -p "$repo/docs" "$repo/apps/api/src"
    echo base > "$repo/docs/a.md"
    echo base > "$repo/apps/api/src/A.php"
    git -C "$repo" add -A
    git -C "$repo" commit -qm base
    base="$(git -C "$repo" rev-parse HEAD)"
}

# change <path>...: writes the files and commits them as the PR head.
change() {
    for path in "$@"; do
        mkdir -p "$repo/$(dirname "$path")"
        echo changed >> "$repo/$path"
    done
    git -C "$repo" add -A
    git -C "$repo" commit -qm change --allow-empty
    head="$(git -C "$repo" rev-parse HEAD)"
}

# expect <name> <expected> [base]: runs the script in the repo and compares its stdout.
expect() {
    actual="$(cd "$repo" && sh "$script" "${3:-$base}" "$head" 2>"$tmp/stderr")"
    if [ "$actual" = "$2" ]; then
        echo "ok   $1"
    else
        echo "FAIL $1: expected '$2', got '$actual'"
        failures=$((failures + 1))
    fi
}

new_repo docs; change docs/features/x/spec.md README.md
expect "docs and root markdown only" docs

new_repo app-md; change apps/api/CLAUDE.md
expect "markdown inside an app" docs

new_repo mixed; change docs/features/x/spec.md apps/api/src/A.php
expect "docs plus a code file" full

new_repo ci; change .github/workflows/ci.yml
expect "only the CI workflow" full

new_repo gh-md; change .github/PULL_REQUEST_TEMPLATE.md
expect "markdown under .github" full

new_repo md-dir; change docs.md/run.sh
expect "code in a directory named like markdown" full

new_repo rename; git -C "$repo" mv apps/api/src/A.php docs/B.md; change
expect "code file renamed into docs" full

new_repo skill; change .claude/skills/delivery/state.py
expect "a skill script" tooling

new_repo ao; change .ao/setup .ao/test_setup.py
expect "AO hooks" tooling

new_repo tools; change tools/rulesets/epic.json
expect "repository tools" tooling

new_repo settings; change .claude/settings.json
expect "Claude Code settings" tooling

new_repo skill-md; change .claude/skills/delivery/SKILL.md
expect "a skill described in markdown only" docs

new_repo tooling-docs; change .claude/skills/delivery/state.py docs/features/x/plan.md
expect "tooling plus docs" tooling

new_repo tooling-app; change .ao/setup apps/api/src/A.php
expect "tooling plus app code" full

new_repo issue-templates; change .github/ISSUE_TEMPLATE/bug.yml .github/ISSUE_TEMPLATE/config.yml
expect "issue templates only" docs

new_repo issue-templates-docs; change .github/ISSUE_TEMPLATE/ux.yml docs/features/x/spec.md
expect "issue templates plus docs" docs

new_repo issue-templates-tooling; change .github/ISSUE_TEMPLATE/bug.yml tools/rulesets/epic.json
expect "issue templates plus tooling" tooling

new_repo issue-templates-app; change .github/ISSUE_TEMPLATE/bug.yml apps/api/src/A.php
expect "issue templates plus app code" full

new_repo issue-templates-ci; change .github/ISSUE_TEMPLATE/bug.yml .github/workflows/ci.yml
expect "issue templates plus a workflow" full

new_repo issue-templates-prefix; change .github/ISSUE_TEMPLATE_evil/x.yml
expect "a directory named like the issue templates" full

new_repo issue-templates-nested; change x/.github/ISSUE_TEMPLATE/y.yml
expect "issue templates path nested in another directory" full

new_repo issue-templates-rename; mkdir -p "$repo/.github/ISSUE_TEMPLATE"; git -C "$repo" mv apps/api/src/A.php .github/ISSUE_TEMPLATE/A.php; change
expect "code file renamed into the issue templates" full

new_repo ci-script; change .github/scripts/background.sh
expect "a CI script" full

new_repo infra; change infra/docker-compose.yml
expect "infrastructure" full

new_repo k8s-secrets; change infra/k8s/environments/prod/secrets/shop-origin-tls.yaml infra/k8s/environments/prod/sealed-secrets.pem
expect "sealed secrets of the production environment" k8s

new_repo k8s-chart; change infra/k8s/charts/shop/templates/api.yaml infra/k8s/scripts/status.sh infra/k8s/Makefile
expect "the chart, the server scripts and the k8s Makefile" k8s

new_repo k8s-docs; change infra/k8s/environments/prod/values.yaml infra/k8s/README.md docs/features/deploy/spec.md
expect "k8s plus docs" k8s

new_repo k8s-md; change infra/k8s/CLAUDE.md
expect "markdown under infra/k8s only" docs

new_repo k8s-compose; change infra/k8s/environments/prod/values.yaml infra/docker-compose.yml
expect "k8s plus compose" full

new_repo k8s-app; change infra/k8s/charts/shop/values.yaml apps/api/src/A.php
expect "k8s plus app code" full

new_repo k8s-tooling; change infra/k8s/charts/shop/values.yaml tools/rulesets/epic.json
expect "k8s plus tooling" full

new_repo k8s-ci; change infra/k8s/charts/shop/values.yaml .github/workflows/deploy.yml
expect "k8s plus a workflow" full

new_repo k8s-prefix; change infra/k8s-extra/run.sh
expect "a directory named like infra/k8s" full

new_repo k8s-rename; mkdir -p "$repo/infra/k8s"; git -C "$repo" mv apps/api/src/A.php infra/k8s/A.php; change
expect "code file renamed into infra/k8s" full

new_repo makefile; change Makefile
expect "the root Makefile" full

new_repo tooling-rename; mkdir -p "$repo/.claude"; git -C "$repo" mv apps/api/src/A.php .claude/A.php; change
expect "code file renamed into tooling" full

new_repo empty; change
expect "empty diff" full

new_repo bad-base; change docs/b.md
expect "unknown base commit" full 0000000000000000000000000000000000000000
if ! grep -q '::warning::' "$tmp/stderr"; then
    echo "FAIL unknown base commit: no ::warning:: on stderr"
    failures=$((failures + 1))
fi

new_repo no-args; change docs/b.md
actual="$(cd "$repo" && sh "$script" 2>/dev/null)"
if [ "$actual" = full ]; then echo "ok   missing arguments"; else
    echo "FAIL missing arguments: expected 'full', got '$actual'"
    failures=$((failures + 1))
fi

# The second question, `scope.sh --k8s`: does the change touch infra/k8s or the workflows at all? In the full CI it
# decides whether the part `k8s` (`make -C infra/k8s check`) has work; an error answers yes.
# expect_k8s <name> <expected> [base]
expect_k8s() {
    actual="$(cd "$repo" && sh "$script" --k8s "${3:-$base}" "$head" 2>"$tmp/stderr")"
    if [ "$actual" = "$2" ]; then
        echo "ok   k8s: $1"
    else
        echo "FAIL k8s: $1: expected '$2', got '$actual'"
        failures=$((failures + 1))
    fi
}

new_repo q-app; change apps/api/src/A.php apps/store/src/x.tsx e2e/tests/a.spec.ts
expect_k8s "app code and tests only" no

new_repo q-infra; change infra/keycloak/realms/shop.json infra/elasticsearch/Dockerfile infra/scripts/init-env.sh Makefile
expect_k8s "other infrastructure and the Makefile" no

# The compose file holds the image tags the chart must match (the test of the chart in `make -C infra/k8s check`), so a
# change of it runs that check in its own PR, e.g. an image bump of Dependabot.
new_repo q-compose; change infra/docker-compose.yml
expect_k8s "the compose file only" yes
expect "the compose file only is the full CI" full

new_repo q-compose-mixed; change infra/docker-compose.yml infra/keycloak/realms/shop.json
expect_k8s "the compose file mixed with other infrastructure" yes

new_repo q-compose-docs; change infra/docker-compose.yml docs/features/x/spec.md
expect_k8s "the compose file mixed with documentation" yes

new_repo q-compose-control; mkdir -p "$repo/infra"; printf x > "$repo/infra/docker-compose.yml$(printf '\t')x"; change
expect_k8s "a compose-like path with a control character (git quotes the path)" yes

new_repo q-compose-rename-in; mkdir -p "$repo/infra"; git -C "$repo" mv apps/api/src/A.php infra/docker-compose.yml; change
expect_k8s "a file moved to infra/docker-compose.yml" yes

new_repo q-compose-rename-out; change infra/docker-compose.yml
base="$head"; git -C "$repo" mv infra/docker-compose.yml apps/api/src/compose.yml; change
expect_k8s "infra/docker-compose.yml moved away" yes

new_repo q-compose-delete; change infra/docker-compose.yml
base="$head"; git -C "$repo" rm -q infra/docker-compose.yml; change
expect_k8s "infra/docker-compose.yml deleted" yes

new_repo q-compose-like; change infra/docker-compose.yaml infra/scripts/docker-compose.yml x/infra/docker-compose.yml infra/keycloak/docker-compose.yml
expect_k8s "paths that only look like the compose file" no

new_repo q-chart; change infra/k8s/charts/shop/values.yaml
expect_k8s "the chart only" yes

new_repo q-mixed; change infra/k8s/scripts/status.sh apps/api/src/A.php
expect_k8s "the chart mixed with app code" yes
expect "the chart mixed with app code is still the full CI" full

new_repo q-mixed-tooling; change infra/k8s/charts/shop/values.yaml tools/rulesets/epic.json
expect_k8s "the chart mixed with tooling" yes

new_repo q-md; change infra/k8s/README.md apps/api/src/A.php
expect_k8s "a document under infra/k8s mixed with app code" yes

new_repo q-prefix; change infra/k8s-extra/run.sh infra/k8sx
expect_k8s "paths that only start like infra/k8s" no

new_repo q-nested; change x/infra/k8s/run.sh
expect_k8s "infra/k8s nested in another directory" no

new_repo q-rename-in; mkdir -p "$repo/infra/k8s"; git -C "$repo" mv apps/api/src/A.php infra/k8s/A.php; change
expect_k8s "a file moved into infra/k8s" yes

new_repo q-rename-out; change infra/k8s/scripts/run.sh
base="$head"; git -C "$repo" mv infra/k8s/scripts/run.sh apps/api/src/run.sh; change
expect_k8s "a file moved out of infra/k8s" yes

new_repo q-delete; change infra/k8s/scripts/run.sh
base="$head"; git -C "$repo" rm -q infra/k8s/scripts/run.sh; change
expect_k8s "a file of infra/k8s deleted" yes

new_repo q-control; mkdir -p "$repo/infra/k8s"; printf x > "$repo/infra/k8s/a$(printf '\t')b.sh"; change
expect_k8s "a file of infra/k8s with a control character in its name (git quotes the path)" yes

# The workflows are checked by `make -C infra/k8s check` alone (actionlint, secrets only in main-only environments, no
# deletion of package versions, the deployment waits for the check): a change of a workflow runs it in its own PR.
new_repo q-workflow; change .github/workflows/deploy.yml
expect_k8s "a workflow only" yes

new_repo q-workflow-mixed; change .github/workflows/release.yml apps/api/src/A.php
expect_k8s "a new workflow mixed with app code" yes

new_repo q-workflow-control; mkdir -p "$repo/.github/workflows"; printf x > "$repo/.github/workflows/a$(printf '\t')b.yml"; change
expect_k8s "a workflow with a control character in its name (git quotes the path)" yes

new_repo q-workflow-like; change .github/scripts/scope.sh .github/workflows-old/a.yml x/.github/workflows/a.yml
expect_k8s "CI scripts and paths that only look like the workflows" no

new_repo q-empty; change
expect_k8s "empty diff" yes

new_repo q-bad-base; change apps/api/src/A.php
expect_k8s "unknown base commit" yes 0000000000000000000000000000000000000000
if ! grep -q '::warning::' "$tmp/stderr"; then
    echo "FAIL k8s: unknown base commit: no ::warning:: on stderr"
    failures=$((failures + 1))
fi

new_repo q-no-args; change apps/api/src/A.php
actual="$(cd "$repo" && sh "$script" --k8s 2>/dev/null)"
if [ "$actual" = yes ]; then echo "ok   k8s: missing arguments"; else
    echo "FAIL k8s: missing arguments: expected 'yes', got '$actual'"
    failures=$((failures + 1))
fi

# A push to main is scoped by the commits it adds: the same script with the commit before the push as the base.
new_repo push-docs; change apps/api/src/A.php
base="$head"; change docs/features/x/spec.md
expect "a push of a documentation commit on top of a code commit" docs
expect_k8s "a push of a documentation commit on top of a code commit" no

new_repo push-many; change docs/a.md
base="$head"; change docs/b.md; change apps/api/src/A.php; change docs/c.md
expect "a push of several commits, one of them code" full

[ "$failures" -eq 0 ] || { echo "$failures failure(s)"; exit 1; }
echo "all passed"
