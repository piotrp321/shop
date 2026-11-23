#!/bin/sh
# Tests of merge-override.sh with a fake `gh` on PATH. The fake logs every call with the token it got
# (`token=<GH_TOKEN> <args>`) and answers from the files of the case: pr-view.json and check-runs.json hold the JSON of
# GitHub and the fake applies the query of the script to them with jq (`gh -q` is jq), so the queries are tested too.
# A file `<kind>.fail` (pr-view, check-runs, merge, delete, comment) makes that call fail.
set -u

script="$(cd "$(dirname "$0")" && pwd)/merge-override.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0
sha=0123456789abcdef0123456789abcdef01234567
other=ffffffffffffffffffffffffffffffffffffffff

mkdir -p "$tmp/bin"
cat > "$tmp/bin/gh" <<'GH'
#!/bin/sh
echo "token=${GH_TOKEN:-} $*" >> "$CASE/calls"
filter="" body="" prev=""
for arg in "$@"; do
    [ "$prev" = "-q" ] && filter="$arg"
    [ "$prev" = "--body-file" ] && body="$arg"
    prev="$arg"
done
case "$*" in
    "pr view"*) kind=pr-view ;;
    "pr comment"*) kind=comment ;;
    *check-runs*) kind=check-runs ;;
    *"-X PUT"*) kind=merge ;;
    *"-X DELETE"*) kind=delete ;;
    *) echo "fake gh: unexpected call: $*" >&2; exit 97 ;;
esac
if [ -f "$CASE/$kind.fail" ]; then echo "HTTP 500: fake failure of $kind" >&2; exit 1; fi
case "$kind" in
    pr-view|check-runs) exec jq -r "$filter" "$CASE/$kind.json" ;;
    comment) cp "$body" "$CASE/comment" ;;
    merge) echo '{"merged":true}' ;;
esac
exit 0
GH
chmod +x "$tmp/bin/gh"

# pr_json <state> <draft> <base> <head sha> <fork> <branch> [title as a JSON string body]
pr_json() {
    printf '{"state":"%s","isDraft":%s,"baseRefName":"%s","headRefOid":"%s","isCrossRepository":%s,"headRefName":"%s","author":{"login":"piotrp321"},"title":"%s"}\n' \
        "$1" "$2" "$3" "$4" "$5" "$6" "${7:-fix(ci): repair the runner}" > "$CASE/pr-view.json"
}

# runs_json <name> <status> <conclusion|null> ...: the check runs of the head commit, plus one unrelated job.
runs_json() {
    runs='{"name":"checks part apps","status":"completed","conclusion":"failure"}'
    while [ $# -gt 0 ]; do
        conclusion="\"$3\""
        [ "$3" = null ] && conclusion=null
        runs="$runs,{\"name\":\"$1\",\"status\":\"$2\",\"conclusion\":$conclusion}"
        shift 3
    done
    echo "{\"check_runs\":[$runs]}" > "$CASE/check-runs.json"
}

# new_case <name>: an open pull request into main with red `checks` and no `e2e` run at all.
new_case() {
    CASE="$tmp/$1"
    mkdir -p "$CASE"
    export CASE
    : > "$CASE/calls"
    pr_json OPEN false main "$sha" false fix/ci
    runs_json checks completed failure
    PR=123 SHA="$sha" REASON="GitHub Actions is down" REPO=piotrp321/shop MERGE_TOKEN=app-token
}

# run_script <mode...>: sets $code; stdout in out, stderr in err, the run summary in summary.
run_script() {
    (
        cd "$CASE" || exit 99
        PATH="$tmp/bin:$PATH" PR="$PR" SHA="$SHA" REASON="$REASON" GITHUB_REPOSITORY="$REPO" GH_TOKEN=read-token \
            MERGE_TOKEN="$MERGE_TOKEN" RUN_URL=https://example.test/runs/9 GITHUB_STEP_SUMMARY="$CASE/summary" \
            sh "$script" "$@" > "$CASE/out" 2> "$CASE/err"
    )
    code=$?
}

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; failures=$((failures + 1)); }

# expect <name> <expected code> <text expected in stdout+stderr>
expect() {
    if [ "$code" = "$2" ] && cat "$CASE/out" "$CASE/err" | grep -q -- "$3"; then ok "$1"; else
        fail "$1: code $code (expected $2), expected text '$3'; output: $(cat "$CASE/out" "$CASE/err" | tr '\n' ' ')"; fi
}

# expect_call <name> <pattern> / expect_no_call <name> <pattern>: a logged call of the fake gh.
expect_call() {
    if grep -q -- "$2" "$CASE/calls"; then ok "$1"; else fail "$1: no gh call matching '$2': $(tr '\n' '|' < "$CASE/calls")"; fi
}
expect_no_call() {
    if grep -q -- "$2" "$CASE/calls"; then fail "$1: a gh call matches '$2': $(tr '\n' '|' < "$CASE/calls")"; else ok "$1"; fi
}
# expect_file <name> <file in the case> <pattern>
expect_file() {
    if grep -q -- "$3" "$CASE/$2" 2>/dev/null; then ok "$1"; else fail "$1: '$3' not in $2: $(tr '\n' ' ' < "$CASE/$2" 2>/dev/null)"; fi
}
# expect_line <name> <file in the case> <text>: a whole line of the file is exactly this text.
expect_line() {
    if grep -qxF -- "$3" "$CASE/$2" 2>/dev/null; then ok "$1"; else fail "$1: no line '$3' in $2: $(tr '\n' '|' < "$CASE/$2" 2>/dev/null)"; fi
}
# Nothing but reads: no merge, no deleted branch, no comment.
expect_nothing_written() {
    expect_no_call "$1: no merge" "-X PUT"
    expect_no_call "$1: no deleted branch" "-X DELETE"
    expect_no_call "$1: no comment" "pr comment"
}

# --- validate ---

new_case validate-red
run_script validate
expect "validate: red checks and no e2e run can be overridden" 0 "checks: failure"
expect "validate: a required check without a run is missing" 0 "e2e: missing"
expect_file "validate: the summary names the pull request" summary "#123"
expect_file "validate: the summary shows the reason" summary "GitHub Actions is down"
expect_file "validate: the summary shows the state of the checks" summary "failure"
expect_call "validate: the pull request is read from the repository of the run" "token=read-token pr view 123 --repo piotrp321/shop"
expect_call "validate: the check runs are those of the given commit" "commits/$sha/check-runs"
expect_nothing_written "validate"

new_case validate-pending
runs_json checks in_progress null e2e queued null
run_script validate
expect "validate: a check still running can be overridden" 0 "checks: in_progress"
expect "validate: a queued check is named" 0 "e2e: queued"

new_case validate-green
runs_json checks completed success e2e completed success
run_script validate
expect "validate: green required checks are refused" 1 "merge it the usual way"

new_case validate-green-skipped
runs_json checks completed success e2e completed skipped
run_script validate
expect "validate: a skipped required check counts as green for GitHub, so it is refused" 1 "merge it the usual way"

new_case validate-one-green
runs_json checks completed success e2e completed failure
run_script validate
expect "validate: one red required check is enough" 0 "e2e: failure"

new_case validate-two-runs-of-one-check
runs_json checks completed success checks completed timed_out e2e completed success
run_script validate
expect "validate: a second, red run of a check makes it not green" 0 "checks: timed_out"

for bad in "" 12a "1 2" -3 0 "1;id"; do
    new_case "validate-pr-$bad"
    PR="$bad"
    run_script validate
    expect "validate: the pull request number '$bad' is refused" 2 "pr must be a positive number"
    expect_no_call "validate: no call for the number '$bad'" "token="
done

for bad in "" 0123456 "${sha}0" 0123456789ABCDEF0123456789abcdef01234567 "0123456789abcdef0123456789abcdef0123456g"; do
    new_case "validate-sha-$bad"
    SHA="$bad"
    run_script validate
    expect "validate: the commit '$bad' is refused" 2 "sha must be the full commit"
    expect_no_call "validate: no call for the commit '$bad'" "token="
done

for bad in "" "   " "$(printf '\t\n')"; do
    new_case "validate-reason-$(printf '%s' "$bad" | wc -c | tr -d ' ')"
    REASON="$bad"
    run_script validate
    expect "validate: an empty reason is refused" 2 "reason must not be empty"
    expect_no_call "validate: no call without a reason" "token="
done

# One line, no control characters, no Unicode line separators, at most 1000 bytes: the text goes into the summary and
# the comment the user reads before and after the approval.
nl='
'
for bad in "CI is down${nl}${nl}### Merge override of #7" "a$(printf '\tb')" "a$(printf '\rb')" "a$(printf '\033[2Jb')" \
        "a$(printf '\342\200\250')b" "a$(printf '\342\200\251')b"; do
    new_case "validate-reason-control-$(printf '%s' "$bad" | cksum | cut -d' ' -f1)"
    REASON="$bad"
    run_script validate
    expect "validate: a reason with a line break or a control character is refused" 2 "reason must be one line without control characters"
    expect_no_call "validate: no call for such a reason" "token="
done

new_case validate-reason-nbsp
REASON="$(printf '\302\240 \302\240')"
run_script validate
expect "validate: a reason of no-break spaces is empty" 2 "reason must not be empty"

new_case validate-reason-long
REASON="$(printf '%01001d' 0)"
run_script validate
expect "validate: a reason over 1000 bytes is refused" 2 "reason must be at most 1000 bytes"
new_case validate-reason-limit
REASON="$(printf '%01000d' 0)"
run_script validate
expect "validate: a reason of 1000 bytes is accepted" 0 "checks: failure"

new_case validate-reason-polish
REASON="Awaria CI – zażółć gęślą jaźń"
run_script validate
expect "validate: a reason with Polish letters" 0 "checks: failure"
expect_line "validate: the Polish letters reach the summary" summary "- Reason: Awaria CI – zażółć gęślą jaźń"

# Markdown of the reason and of the title is escaped: neither can draw a heading, a link or a mention in the summary.
new_case validate-markdown
REASON='### Merge override of #7 [x](http://e.test) <b>@user</b> `c` *e*'
pr_json OPEN false main "$sha" false fix/ci '# big [t](http://e.test) | <i>'
run_script validate
expect "validate: a reason and a title with markdown" 0 "checks: failure"
expect_line "validate: the markdown of the reason is escaped" summary \
    '- Reason: \#\#\# Merge override of \#7 \[x\]\(http\:\/\/e\.test\) \<b\>\@user\<\/b\> \`c\` \*e\*'
expect_line "validate: the markdown of the title is escaped" summary '- Title: \# big \[t\]\(http\:\/\/e\.test\) \| \<i\>'

# A line break in the title (JSON \n) must not come back as a new line of the log: a line that starts with `::` is a
# workflow command for the runner. dash's echo would expand the escaped \n that @tsv writes.
new_case validate-title-newline
pr_json OPEN false main "$sha" false fix/ci 'fix\n::warning title=x::spoofed\\n::error::y'
run_script validate
expect "validate: a title with a line break" 0 "checks: failure"
if grep -q '^::' "$CASE/out" "$CASE/err" "$CASE/summary"; then fail "validate: the title wrote a workflow command: $(grep -h '^::' "$CASE/out" "$CASE/err" "$CASE/summary" | tr '\n' '|')"; else
    ok "validate: the title cannot write a workflow command"; fi

new_case validate-no-repository
REPO=""
run_script validate
expect "validate: no repository" 2 "GITHUB_REPOSITORY"

new_case no-mode
run_script
expect "no mode" 2 "usage: merge-override.sh validate|merge"
new_case unknown-mode
run_script force
expect "an unknown mode" 2 "usage: merge-override.sh validate|merge"

new_case validate-closed
pr_json CLOSED false main "$sha" false fix/ci
run_script validate
expect "validate: a closed pull request" 1 "is CLOSED, not open"

new_case validate-merged
pr_json MERGED false main "$sha" false fix/ci
run_script validate
expect "validate: a merged pull request" 1 "is MERGED, not open"

new_case validate-draft
pr_json OPEN true main "$sha" false fix/ci
run_script validate
expect "validate: a draft" 1 "is a draft"

new_case validate-base
pr_json OPEN false epic/cart "$sha" false fix/ci
run_script validate
expect "validate: another base branch" 1 "targets epic/cart, not main"

new_case validate-fork
pr_json OPEN false main "$sha" true fix/ci
run_script validate
expect "validate: a pull request from a fork" 1 "comes from a fork"

new_case validate-other-head
pr_json OPEN false main "$other" false fix/ci
run_script validate
expect "validate: another head commit" 1 "head is $other, not $sha"
expect_nothing_written "validate, another head"

new_case validate-pr-view-fails
: > "$CASE/pr-view.fail"
run_script validate
expect "validate: gh cannot read the pull request" 4 "cannot read pull request #123"
expect "validate: the answer of gh is shown" 4 "fake failure of pr-view"

new_case validate-check-runs-fail
: > "$CASE/check-runs.fail"
run_script validate
expect "validate: gh cannot read the check runs" 4 "cannot read the check runs"
expect_nothing_written "validate, no check runs"

# --- merge ---

new_case merge
run_script merge
expect "merge: a red pull request is merged" 0 "merged pull request #123"
expect_call "merge: squash, pinned to the head commit, with the token of the app" \
    "token=app-token api -X PUT repos/piotrp321/shop/pulls/123/merge -f merge_method=squash -f sha=$sha"
expect_call "merge: the branch is deleted with the token of the app" "token=app-token api -X DELETE repos/piotrp321/shop/git/refs/heads/fix/ci"
expect_call "merge: the comment is written with the token of the app" "token=app-token pr comment 123 --repo piotrp321/shop"
expect_call "merge: the pull request is read with the token of the job" "token=read-token pr view 123"
expect_no_call "merge: the token of the app reads nothing" "token=app-token pr view"
expect_file "merge: the comment links the run" comment "https://example.test/runs/9"
expect_file "merge: the comment gives the reason" comment "GitHub Actions is down"
expect_file "merge: the comment gives the state of the checks" comment "checks.*failure"
expect_file "merge: the comment names the commit" comment "$sha"
expect_file "merge: the summary says it is merged" summary "merged"

new_case merge-green-meanwhile
runs_json checks completed success e2e completed success
run_script merge
expect "merge: checks that turned green after the approval do not stop the merge" 0 "merged pull request #123"

new_case merge-no-app-token
MERGE_TOKEN=""
run_script merge
expect "merge: without the token of the app" 2 "MERGE_TOKEN"
expect_no_call "merge: no call without the token of the app" "token="

new_case merge-other-head
pr_json OPEN false main "$other" false fix/ci
run_script merge
expect "merge: a push after the request" 1 "head is $other, not $sha"
expect_nothing_written "merge, another head"

new_case merge-draft
pr_json OPEN true main "$sha" false fix/ci
run_script merge
expect "merge: turned into a draft after the request" 1 "is a draft"
expect_nothing_written "merge, a draft"

new_case merge-retargeted
pr_json OPEN false deploy "$sha" false fix/ci
run_script merge
expect "merge: retargeted after the request" 1 "targets deploy, not main"
expect_nothing_written "merge, retargeted"

new_case merge-bad-pr
PR="12 --admin"
run_script merge
expect "merge: the inputs are checked again" 2 "pr must be a positive number"
expect_no_call "merge: no call for a bad number" "token="

new_case merge-refused
: > "$CASE/merge.fail"
run_script merge
expect "merge: GitHub refuses the merge" 4 "pull request #123 is NOT merged"
expect "merge: the answer of GitHub is shown" 4 "fake failure of merge"
expect_no_call "merge refused: the branch stays" "-X DELETE"
expect_no_call "merge refused: no comment" "pr comment"

new_case merge-delete-fails
: > "$CASE/delete.fail"
run_script merge
expect "merge: a branch that cannot be deleted is a warning" 0 "::warning::.*branch fix/ci"
expect_call "merge: the comment is still written" "pr comment 123"

new_case merge-comment-fails
: > "$CASE/comment.fail"
run_script merge
expect "merge: a comment that cannot be written is a warning" 0 "::warning::.*comment"
expect "merge: it still reports the merge" 0 "merged pull request #123"

new_case merge-branch-with-hash
pr_json OPEN false main "$sha" false "fix/a#b c%d"
run_script merge
expect_call "merge: the branch name is encoded segment by segment" "-X DELETE repos/piotrp321/shop/git/refs/heads/fix/a%23b%20c%25d$"

new_case merge-hostile-reason
# shellcheck disable=SC2016 # the text must reach the comment as it is, unexpanded
REASON='$(touch pwned) `touch pwned2` "; touch pwned3 #'
run_script merge
expect "merge: a reason with shell syntax" 0 "merged pull request #123"
# shellcheck disable=SC2016
expect_line "merge: the reason is in the comment, its markdown escaped" comment '- Powód: \$\(touch pwned\) \`touch pwned2\` \"\; touch pwned3 \#'
if ls "$CASE"/pwned* > /dev/null 2>&1; then fail "merge: the reason was executed"; else ok "merge: the reason is never executed"; fi

if [ "$failures" -gt 0 ]; then
    echo "test_merge_override: $failures failure(s)" >&2
    exit 1
fi
echo "test_merge_override: all passed"
