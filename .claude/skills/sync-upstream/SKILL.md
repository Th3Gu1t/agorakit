---
name: sync-upstream
description: Bring new commits of the upstream Agorakit project (agorakit/agorakit) into this fork through a pull request against the fork's main branch. Use when asked to update or sync the fork with upstream, or to pick up an upstream release, fix or security patch.
---

# Sync the fork with upstream

The fork's `main` is upstream's `main` plus the fork's own merge commits.
Upstream changes come in with a merge, through a pull request like any other
change. `main` is never rebased: its history is published and signed.

Nothing here writes to upstream. `upstream` is a fetch-only remote.

## 1. See what is coming

```sh
git fetch origin main
git fetch upstream main --tags
git log --oneline origin/main..upstream/main
```

If the log is empty, the fork is up to date: say so and stop.

Otherwise read the incoming changes before merging, and note for the pull
request:

- the upstream version (`config/agorakit.php`, key `version`);
- new migrations (`database/migrations`) and dependency changes
  (`composer.json`, `composer.lock`);
- security fixes (commit subjects, `SECURITY.md`, upstream releases);
- files changed on both sides, which is where conflicts will be:

```sh
base=$(git merge-base origin/main upstream/main)
comm -12 <(git diff --name-only "$base" origin/main | sort) \
         <(git diff --name-only "$base" upstream/main | sort)
```

## 2. Issue and branch

Create an issue in the fork ("Synchroniser avec l'amont", with the upstream
version, label `fork-only`), then:

```sh
git switch -c chore/sync-upstream-<version> origin/main
git merge --no-ff upstream/main -m "chore(fork): merge upstream main (<version>)"
```

## 3. Conflicts

Resolve each conflict by understanding both sides:

- If upstream now does what a fork change did, take upstream's version and
  say so in the pull request: the fork change can be dropped.
- If both changes are still needed, keep both intents.
- Never settle a whole file by taking one side blindly.

The resolution belongs to the merge commit. Explain non-obvious resolutions
in its message.

## 4. Verify

```sh
composer install
vendor/bin/phpunit
```

If the merge breaks something of the fork, fix it in separate commits on the
same branch, following the normal commit rules. Do not hide fixes in the merge
commit.

`check-commits.sh` skips the merge commit and the commits that come from
upstream; it still checks the fix commits.

## 5. Pull request

Push to `origin` and open a pull request against the fork's `main`:

```sh
git push -u origin chore/sync-upstream-<version>
gh pr create --repo Th3Gu1t/agorakit --base main \
  --title "chore(fork): merge upstream main (<version>)" --body-file <file>
```

In the description (French, following the template): the notable upstream
changes, the conflicts and how they were resolved, and what the maintainer
must do when deploying (migrations, new settings, new environment variables).

The maintainer merges.
