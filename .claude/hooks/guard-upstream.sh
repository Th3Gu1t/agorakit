#!/usr/bin/env bash
#
# Claude Code PreToolUse hook for the Bash tool.
#
# This fork never writes to the upstream project: no push, no issue, no pull
# request, no comment (see CLAUDE.md, "Fork policy"). This hook is the safety
# net behind that rule. It reads the hook payload on stdin and
#
#   - denies a command that would write to a repository of the upstream
#     organisation, or that leaves its GitHub target implicit;
#   - asks the human before the settings or the protections of a repository
#     are changed.
#
# Merging a pull request of the fork is allowed: the maintainer delegated it
# (CLAUDE.md, "Fork policy"). A merge in the upstream project is denied above,
# with every other write there.
#
# It matches on the text of the command: it catches mistakes, it is not a
# security boundary. Reading from upstream (fetch, view, list, search) is
# never blocked.

set -u

readonly UPSTREAM_OWNER='agorakit'

command=$(jq -r '.tool_input.command // empty' 2> /dev/null) || exit 0
[ -n "$command" ] || exit 0

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
fork=$(git -C "$repo_root" remote get-url origin 2> /dev/null \
    | sed -E 's#^(git@github\.com:|ssh://git@github\.com/|https://github\.com/)##; s#(\.git)?/?$##')
fork=${fork:-<owner>/<fork>}

decide() {
    jq -n --arg decision "$1" --arg reason "$2" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: $decision,
            permissionDecisionReason: $reason
        }
    }'
    exit 0
}

matches() { printf '%s\n' "$command" | grep -Eq -- "$1"; }
matches_i() { printf '%s\n' "$command" | grep -Eiq -- "$1"; }

# Building blocks. B: start of a command word. S: spaces. Q: optional quote.
readonly B='(^|[^A-Za-z0-9_./-])'
readonly S='[[:space:]]+'
readonly Q="[\"']?"
readonly GIT_PUSH="${B}git${S}([^;&|]*[[:space:]])?push"
readonly GH="${B}gh${S}"
readonly UPSTREAM_SLUG="${UPSTREAM_OWNER}/[A-Za-z0-9_.-]"

# --- git push ---------------------------------------------------------------

if matches "${GIT_PUSH}(${S}-[^[:space:]]+)*${S}${Q}upstream${Q}([[:space:];&|)]|\$)" \
    || matches "${GIT_PUSH}[[:space:]][^;&|]*--repo[[:space:]=]+${Q}upstream" \
    || matches "${GIT_PUSH}[[:space:]][^;&|]*github\.com[:/]${UPSTREAM_SLUG}"; then
    decide deny "This fork never pushes to the upstream project. Push to \"origin\" ($fork) instead."
fi

# --- GitHub CLI -------------------------------------------------------------

if matches "${GH}repo${S}set-default[[:space:]]([^;&|]*[[:space:]\"'/:])?${UPSTREAM_SLUG}"; then
    decide deny "The default repository of the GitHub CLI must stay the fork ($fork), not the upstream project."
fi

# Commands that write and take their target from -R/--repo.
readonly GH_WRITE="${GH}((issue|pr)${S}(create|comment|close|reopen|edit|merge|review|ready|lock|unlock|delete|transfer|pin|unpin|develop|update-branch)|release${S}(create|edit|delete|upload|delete-asset)|label${S}(create|edit|delete|clone)|workflow${S}(run|enable|disable)|run${S}(rerun|cancel|delete)|secret${S}(set|delete)|variable${S}(set|delete)|cache${S}delete)"

if matches "$GH_WRITE"; then
    if matches "(^|[[:space:]])(-R|--repo)[[:space:]=]+${Q}(https://)?(github\.com/)?${UPSTREAM_SLUG}"; then
        decide deny "This fork never writes to the upstream project (issues, pull requests, comments, releases). Target the fork: --repo $fork."
    fi
    if ! matches "(^|[[:space:]])(-R|--repo)([[:space:]=]|\$)"; then
        decide deny "Name the target repository explicitly: add --repo $fork. Without it the GitHub CLI may pick the upstream project, which this fork never writes to."
    fi
fi

# Commands that change a repository itself; the target is a positional argument.
if matches "${GH}repo${S}(edit|delete|rename|archive|unarchive|sync|deploy-key)([[:space:]]|\$)"; then
    if matches "${GH}repo${S}[a-z-]+[[:space:]]([^;&|]*[[:space:]\"'/:])?${UPSTREAM_SLUG}"; then
        decide deny "This fork never changes a repository of the upstream project."
    fi
    decide ask "This changes the settings or the default branch of a repository. That is the maintainer's decision."
fi

# gh api: a write is a non-GET method, or fields without an explicit GET.
gh_api_write=false
if matches "${GH}api([[:space:]]|\$)"; then
    if matches_i "(^|[[:space:]])(-X|--method)[[:space:]=]*${Q}(POST|PUT|PATCH|DELETE)"; then
        gh_api_write=true
    elif matches "(^|[[:space:]])(-f|-F|--field|--raw-field|--input)([[:space:]=]|\$)" \
        && ! matches_i "(^|[[:space:]])(-X|--method)[[:space:]=]*${Q}GET"; then
        gh_api_write=true
    fi
fi

if $gh_api_write; then
    if matches "(^|[^A-Za-z0-9_.-])repos/${UPSTREAM_SLUG}"; then
        decide deny "This fork never writes to the upstream project through the GitHub API."
    fi
    if matches '\{owner\}|:owner([^A-Za-z0-9_]|$)'; then
        decide deny "Name the repository in the API path (repos/$fork/...) instead of a placeholder: the placeholder may resolve to the upstream project."
    fi
    if matches '/(branches/[^[:space:]]+/protection|rulesets)'; then
        decide ask "This changes the branch protections of a repository. That is the maintainer's decision."
    fi
fi

exit 0
