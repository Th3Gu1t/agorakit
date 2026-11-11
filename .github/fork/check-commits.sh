#!/usr/bin/env bash
#
# Checks that commits follow the rules of this fork (see CLAUDE.md, "Commits"):
#
#   1. The subject follows Conventional Commits and is at most 72 characters.
#   2. A body, if there is one, is separated from the subject by a blank line.
#   3. A commit touches either fork-only paths (.github/fork/fork-only-paths)
#      or application paths, never both, and has the scope "fork" if and only
#      if it touches fork-only paths.
#
# Usage:
#   check-commits.sh [<base> [<head>]]         check the commits of a branch
#   check-commits.sh --list [<base> [<head>]]  print the commits that would be checked
#   check-commits.sh --subject <text>          check one subject line (a pull request title)
#   check-commits.sh --commit-msg <file>       check the commit being created (commit-msg hook)
#
# <base> defaults to origin/main and <head> to HEAD. Merge commits are skipped,
# and so are commits that already belong to the upstream project (the ref in
# $UPSTREAM_REF, upstream/main by default): upstream history predates these rules.

set -euo pipefail

readonly TYPES='feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert'
readonly SUBJECT_RE="^(${TYPES})(\(([a-z0-9][a-z0-9._/-]*)\))?!?: [^[:space:]].*$"
readonly MAX_SUBJECT=72
readonly FORK_SCOPE='fork'

root=$(git rev-parse --show-toplevel)
readonly PATHS_FILE="$root/.github/fork/fork-only-paths"

is_fork_only() {
    local path=$1 pattern
    while IFS= read -r pattern; do
        [[ -z $pattern || $pattern == \#* ]] && continue
        if [[ $pattern == */ ]]; then
            [[ $path == "$pattern"* ]] && return 0
        else
            # Unquoted on purpose: the pattern is a glob.
            # shellcheck disable=SC2053
            [[ $path == $pattern ]] && return 0
        fi
    done < "$PATHS_FILE"
    return 1
}

scope_of() {
    if [[ $1 =~ $SUBJECT_RE ]]; then
        printf '%s' "${BASH_REMATCH[3]}"
    fi
}

# Prints the problems of a subject line; fails if there are any.
check_subject() {
    local subject=$1 status=0
    if [[ ! $subject =~ $SUBJECT_RE ]]; then
        echo "  the subject must be \"<type>(<scope>): <description>\", with a type among: ${TYPES//|/, }"
        status=1
    fi
    if (( ${#subject} > MAX_SUBJECT )); then
        echo "  the subject is ${#subject} characters long, the limit is $MAX_SUBJECT"
        status=1
    fi
    return $status
}

# Reads changed paths on stdin and checks them against the scope given as $1.
check_paths() {
    local scope=$1 path fork_only='' application=''
    while IFS= read -r path; do
        [[ -z $path ]] && continue
        if is_fork_only "$path"; then
            fork_only=${fork_only:-$path}
        else
            application=${application:-$path}
        fi
    done
    if [[ -n $fork_only && -n $application ]]; then
        echo "  it mixes a fork-only path ($fork_only) with an application path ($application): make two commits"
        return 1
    fi
    if [[ -n $fork_only && $scope != "$FORK_SCOPE" ]]; then
        echo "  it only touches fork-only paths ($fork_only): the scope must be \"$FORK_SCOPE\""
        return 1
    fi
    if [[ -n $application && $scope == "$FORK_SCOPE" ]]; then
        echo "  the scope \"$FORK_SCOPE\" is reserved for fork-only paths, but it touches $application"
        return 1
    fi
    return 0
}

# Checks a whole message ($1) against the changed paths read on stdin.
check_message() {
    local message=$1 subject rest status=0
    subject=${message%%$'\n'*}
    check_subject "$subject" || status=1
    if [[ $message == *$'\n'* ]]; then
        rest=${message#*$'\n'}
        if [[ -n ${rest%%$'\n'*} ]]; then
            echo "  the second line must be blank: subject, blank line, then the body"
            status=1
        fi
    fi
    check_paths "$(scope_of "$subject")" || status=1
    return $status
}

list_commits() {
    local base=$1 head=$2 upstream_ref=${UPSTREAM_REF:-upstream/main}
    local -a exclude=("$base")
    if git rev-parse --verify --quiet "$upstream_ref^{commit}" > /dev/null; then
        exclude+=("$upstream_ref")
    fi
    git rev-list --reverse --no-merges "$head" --not "${exclude[@]}"
}

check_range() {
    local base=$1 head=$2 commit title problems failed=0 count=0
    while IFS= read -r commit; do
        count=$((count + 1))
        title=$(git log -1 --format='%h %s' "$commit")
        if problems=$(git diff-tree --no-commit-id --name-only -r --root "$commit" \
            | check_message "$(git log -1 --format=%B "$commit")"); then
            echo "ok    $title"
        else
            echo "FAIL  $title"
            echo "$problems"
            failed=1
        fi
    done < <(list_commits "$base" "$head")
    if (( count == 0 )); then
        echo "No commit to check between $base and $head."
    fi
    return $failed
}

check_commit_msg_file() {
    local file=$1 message problems
    # A merge keeps the message git prepared for it.
    if [[ -e $(git rev-parse --git-path MERGE_HEAD) ]]; then
        return 0
    fi
    # Drop the diff of "git commit --verbose", then comments and blank edges.
    message=$(sed '/^# -\{24\} >8 -\{24\}$/,$d' "$file" | git stripspace --strip-comments)
    # Work-in-progress commits for "git rebase --autosquash" are fine locally;
    # the range check refuses them, so they cannot reach main.
    if [[ -z $message || $message =~ ^(fixup|squash|amend)!\  ]]; then
        return 0
    fi
    if ! problems=$(git diff --cached --name-only | check_message "$message"); then
        echo "This commit breaks the commit rules of the fork (see CLAUDE.md):" >&2
        echo "$problems" >&2
        return 1
    fi
}

case ${1:-} in
    --list)
        list_commits "${2:-origin/main}" "${3:-HEAD}"
        ;;
    --subject)
        if ! problems=$(check_subject "${2:?usage: check-commits.sh --subject <text>}"); then
            echo "FAIL  $2"
            echo "$problems"
            exit 1
        fi
        echo "ok    $2"
        ;;
    --commit-msg)
        check_commit_msg_file "${2:?usage: check-commits.sh --commit-msg <file>}"
        ;;
    -h | --help)
        sed -n '2,/^$/s/^# \{0,1\}//p' "$0"
        ;;
    *)
        check_range "${1:-origin/main}" "${2:-HEAD}"
        ;;
esac
