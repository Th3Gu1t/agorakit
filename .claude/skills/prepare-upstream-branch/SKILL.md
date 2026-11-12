---
name: prepare-upstream-branch
description: Build a clean branch, based on the upstream project's main, that holds one change of this fork without any fork-only file, so that the maintainer can later propose it to the upstream Agorakit project. Use when asked to prepare, extract or export a change for upstream. It never pushes to upstream and never opens anything there.
---

# Prepare a change for upstream

The fork does not contribute upstream today. When the maintainer decides to
propose a change, this procedure produces a branch they can use. Proposing it
(the conversation with the upstream team, the issue, the pull request) is done
by the maintainer, by hand. This procedure stops at a branch pushed to the
fork.

## 1. Identify the change

Ask the maintainer which change, by issue or pull request number, unless they
already said. It should carry the label `upstream-candidate`.

Find its commits from the merge commit of the pull request:

```sh
git log --first-parent --oneline origin/main        # find the merge commit
git log --oneline --reverse <merge>^1..<merge>^2    # the commits it brought
```

Leave out the commits with the scope `fork`: they only touch fork-only files.
If an application commit depends on an earlier change of the fork that is not
part of this export, stop and tell the maintainer: that change has to go
first, or together.

## 2. Build the branch in a worktree

A worktree keeps the main checkout, with its hooks and tools, untouched:

```sh
git fetch upstream main
git worktree add ../agorakit-export-<name> -b export/<name> upstream/main
cd ../agorakit-export-<name>
git cherry-pick <commit> [<commit>...]              # in their original order
```

Resolve conflicts by adapting the change to upstream's current code. Do not
use `cherry-pick -x`: hashes of the fork mean nothing upstream.

## 3. Verify

No fork-only file in the branch (the list is in the main checkout):

```sh
git diff --name-only upstream/main...HEAD
```

Compare with `.github/fork/fork-only-paths`. Then run the tests in the
worktree, against the same test database:

```sh
composer install
cp -n .env.ci .env && php artisan key:generate
vendor/bin/phpunit
```

## 4. Keep it honest

Do not remove the AI co-author trailers from the commits and do not present
the work as written without AI assistance. The upstream project has a policy
on AI-assisted contributions; how to handle it is the maintainer's
conversation with the upstream team, with the facts on the table.

## 5. Hand over

Push the branch to the fork only, then remove the worktree:

```sh
git push -u origin export/<name>
cd - && git worktree remove ../agorakit-export-<name>
```

Report to the maintainer, in French:

- the branch name and its commits;
- the test results on top of upstream's `main`;
- what upstream expects before a pull request: a prior discussion on their
  community for features, an issue with acceptance criteria, their pull
  request conventions (<https://docs.agorakit.org/development.html>). Read
  that page again at that time, it may have changed.

Do not open an issue, a discussion or a pull request upstream.
