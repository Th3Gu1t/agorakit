#!/usr/bin/env bash
#
# One-time setup of a clone of this fork. Safe to run again.
#
#   - "upstream" remote: fetch only, pushing to it is disabled;
#   - git hooks of the fork (.github/fork/hooks) are enabled;
#   - the GitHub CLI targets the fork by default, not the upstream project.

set -euo pipefail

readonly UPSTREAM_REPO='agorakit/agorakit'

cd "$(git rev-parse --show-toplevel)"

fork_repo=$(git remote get-url origin \
    | sed -E 's#^(git@github\.com:|ssh://git@github\.com/|https://github\.com/)##; s#(\.git)?/?$##')
if [[ $fork_repo == "$UPSTREAM_REPO" ]]; then
    echo "The \"origin\" remote is the upstream project. Clone the fork instead." >&2
    exit 1
fi

if ! git remote get-url upstream > /dev/null 2>&1; then
    git remote add upstream "https://github.com/$UPSTREAM_REPO.git"
fi
git remote set-url --push upstream DISABLED_no_push_to_upstream

git config core.hooksPath .github/fork/hooks

if command -v gh > /dev/null; then
    gh repo set-default "$fork_repo" > /dev/null
else
    echo "GitHub CLI not found: skipped setting the default repository." >&2
fi

echo "Clone ready:"
echo "  origin    $fork_repo (fetch and push)"
echo "  upstream  $UPSTREAM_REPO (fetch only)"
echo "  hooks     .github/fork/hooks"
