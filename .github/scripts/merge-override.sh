#!/bin/sh
# Merges one pull request into main although its required checks (`checks`, `e2e`) are not green. Only the workflow
# .github/workflows/merge-override.yml runs it, and its job `merge` waits for the user's approval in the environment
# `merge-override` (docs/features/agent-security/spec.md, R8). The script never checks out or runs code of the pull
# request: it only talks to the API of GitHub.
#
# Usage: merge-override.sh validate|merge
#   validate   checks the request and prints what would be merged; changes nothing. Refuses a pull request whose
#              required checks are green: that one is merged the usual way.
#   merge      checks the request again (the pull request may have changed while the run waited for the approval),
#              then squash-merges it pinned to the given head commit, deletes its branch and leaves a comment.
# Environment:
#   PR                  the number of the pull request
#   SHA                 its head commit, the full SHA (40 hex); a push after the request makes the merge fail
#   REASON              why the checks are skipped: one line of text without control characters, at most 1000 bytes.
#                       It goes into the summary and the comment with its markdown escaped, like the title of the pull
#                       request, so neither can draw a heading, a link or a mention the user did not write.
#   GITHUB_REPOSITORY   owner/name
#   GH_TOKEN            the token for reading (the job's GITHUB_TOKEN)
#   MERGE_TOKEN         merge only: the installation token of the GitHub App that is the bypass actor of the ruleset
#                       main-merge; the merge, the branch deletion and the comment use it, nothing else does
#   RUN_URL             merge only: the link of the workflow run, for the comment
#   GITHUB_STEP_SUMMARY optional: the file of the run summary
# Exit status: 0 done, 1 the request is refused (state of the pull request, another head commit, green checks),
# 2 wrong usage or input, 4 gh or GitHub failed. After code 1, 2 or 4 the pull request is not merged.
set -u

# Every line with text from outside (inputs, the answer of GitHub) is written with printf, never echo: the echo of dash
# expands \n, and a line of the log that starts with `::` is a workflow command for the runner.
say() { printf '%s\n' "$1"; }
usage() {
    say "usage: merge-override.sh validate|merge" >&2
    [ $# -gt 0 ] && say "merge-override: $1" >&2
    exit 2
}
refuse() { say "merge-override: $1" >&2; exit 1; }
broken() { say "merge-override: $1" >&2; exit 4; }
summary() { [ -n "${GITHUB_STEP_SUMMARY:-}" ] && say "$1" >> "$GITHUB_STEP_SUMMARY"; return 0; }
# md <text>: the text with every ASCII punctuation character escaped, so markdown shows it as written.
md() { printf '%s' "$1" | LC_ALL=C sed 's/[[:punct:]]/\\&/g'; }

[ $# -eq 1 ] || usage
mode="$1"
case "$mode" in
    validate|merge) ;;
    *) usage ;;
esac

pr="${PR:-}"
sha="${SHA:-}"
reason="${REASON:-}"
repo="${GITHUB_REPOSITORY:-}"

case "$pr" in
    ""|0*|*[!0-9]*) usage "pr must be a positive number: '$pr'" ;;
esac
case "$sha" in
    *[!0-9a-f]*) usage "sha must be the full commit, 40 lowercase hex characters: '$sha'" ;;
esac
[ "${#sha}" -eq 40 ] || usage "sha must be the full commit, 40 lowercase hex characters: '$sha'"
# Bytes, not characters (LC_ALL=C): white space and the two bytes of a no-break space do not make a reason; a control
# character (a line break among them) or U+2028/U+2029 would start a new line in the summary and the comment.
[ -n "$(printf '%s' "$reason" | LC_ALL=C tr -d '[:space:]\302\240')" ] || usage "reason must not be empty"
controls="$(printf '%s' "$reason" | LC_ALL=C tr -cd '[:cntrl:]' | wc -c | tr -d ' ')"
if [ "$controls" -ne 0 ] || printf '%s' "$reason" | LC_ALL=C grep -q "$(printf '\342\200[\250\251]')"; then
    usage "reason must be one line without control characters"
fi
[ "$(printf '%s' "$reason" | wc -c | tr -d ' ')" -le 1000 ] || usage "reason must be at most 1000 bytes"
[ -n "$repo" ] || usage "GITHUB_REPOSITORY is not set"
if [ "$mode" = merge ]; then
    [ -n "${MERGE_TOKEN:-}" ] || usage "MERGE_TOKEN is not set (the token of the GitHub App)"
fi

tab="$(printf '\t')"
work="$(mktemp -d)" || broken "mktemp failed"
trap 'rm -rf "$work"' EXIT

# The pull request, one line of tab-separated fields. The branch name is encoded segment by segment for the path of the
# API call that deletes it (a `#` or `?` in the name would cut the path short and hit another branch).
query='[.state, (.isDraft | tostring), .baseRefName, .headRefOid, (.isCrossRepository | tostring),
        (.headRefName | split("/") | map(@uri) | join("/")), .headRefName, (.author.login // "ghost"), .title] | @tsv'
line="$(gh pr view "$pr" --repo "$repo" --json state,isDraft,baseRefName,headRefOid,isCrossRepository,headRefName,author,title -q "$query" 2> "$work/err")" \
    || broken "cannot read pull request #$pr of $repo: $(cat "$work/err")"
IFS="$tab" read -r state draft base head fork branch_path branch author title <<EOF
$line
EOF
[ -n "$state" ] && [ -n "$branch" ] || broken "cannot read pull request #$pr of $repo: unexpected answer '$line'"

[ "$state" = OPEN ] || refuse "pull request #$pr is $state, not open"
[ "$draft" = false ] || refuse "pull request #$pr is a draft"
[ "$base" = main ] || refuse "pull request #$pr targets $base, not main"
[ "$fork" = false ] || refuse "pull request #$pr comes from a fork"
[ "$head" = "$sha" ] || refuse "pull request #$pr: head is $head, not $sha (pushed after the request?)"

# The check runs of the head commit named like the required checks: name, status, conclusion.
runs="$(gh api "repos/$repo/commits/$sha/check-runs?per_page=100" --paginate \
    -q '.check_runs[] | select(.name == "checks" or .name == "e2e") | [.name, .status, (.conclusion // "none")] | @tsv' 2> "$work/err")" \
    || broken "cannot read the check runs of $sha: $(cat "$work/err")"

# check_state <name>: success when every run of that name passed for GitHub (success, skipped, neutral), missing
# without a run, otherwise the conclusion of the first run that did not pass (or its status while it is not over).
check_state() {
    printf '%s\n' "$runs" | awk -F '\t' -v name="$1" '
        $1 != name { next }
        { seen = 1 }
        !bad && $3 != "success" && $3 != "skipped" && $3 != "neutral" { bad = ($2 == "completed") ? $3 : $2 }
        END { if (!seen) print "missing"; else if (bad) print bad; else print "success" }'
}
checks="$(check_state checks)"
e2e="$(check_state e2e)"

say "pull request #$pr by $author: $title"
say "head commit: $sha, branch: $branch"
say "checks: $checks"
say "e2e: $e2e"

if [ "$mode" = validate ]; then
    if [ "$checks" = success ] && [ "$e2e" = success ]; then
        refuse "the required checks of pull request #$pr are green: merge it the usual way"
    fi
    summary "### Merge override of #$pr"
    summary ""
    summary "Approving the job \`merge\` squash-merges this pull request into main without green required checks."
    summary ""
    summary "- Pull request: https://github.com/$repo/pull/$pr by $(md "$author")"
    summary "- Title: $(md "$title")"
    summary "- Head commit: \`$sha\`"
    summary "- Required checks: \`checks\`: $checks, \`e2e\`: $e2e"
    summary "- Reason: $(md "$reason")"
    exit 0
fi

GH_TOKEN="$MERGE_TOKEN" gh api -X PUT "repos/$repo/pulls/$pr/merge" -f merge_method=squash -f sha="$sha" > "$work/out" 2> "$work/err" \
    || broken "pull request #$pr is NOT merged, GitHub refused: $(cat "$work/out" "$work/err")"
say "merge-override: merged pull request #$pr at $sha"
summary "### Merge override of #$pr: merged"
summary ""
summary "Pull request #$pr is merged into main at \`$sha\` (\`checks\`: $checks, \`e2e\`: $e2e)."
summary ""
summary "- Reason: $(md "$reason")"

if ! GH_TOKEN="$MERGE_TOKEN" gh api -X DELETE "repos/$repo/git/refs/heads/$branch_path" > /dev/null 2> "$work/err"; then
    say "::warning::merge-override: pull request #$pr is merged, but the branch $branch was not deleted: $(tr '\n' ' ' < "$work/err")"
fi

{
    echo "Zmergowano workflowem \`merge-override\` z pominięciem wymaganych checków, po zatwierdzeniu w środowisku \`merge-override\`."
    echo
    echo "- Przebieg: ${RUN_URL:-brak}"
    echo "- Commit PR: \`$sha\`"
    echo "- Stan wymaganych checków w chwili merge'a: \`checks\`: $checks, \`e2e\`: $e2e"
    say "- Powód: $(md "$reason")"
} > "$work/comment"
if ! GH_TOKEN="$MERGE_TOKEN" gh pr comment "$pr" --repo "$repo" --body-file "$work/comment" > /dev/null 2> "$work/err"; then
    say "::warning::merge-override: pull request #$pr is merged, but the comment was not written: $(tr '\n' ' ' < "$work/err")"
fi
exit 0
