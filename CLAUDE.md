# Agorakit, association fork

This repository is a fork of [agorakit/agorakit](https://github.com/agorakit/agorakit)
(groupware for citizens, AGPL-3.0-only). It runs the private instance of an
association and is developed with AI assistance, under the review of its
maintainer.

The upstream project discourages AI-generated contributions, so for now
nothing from this fork is sent upstream. The fork still keeps its changes in a
shape that could be proposed upstream one day.

## Fork policy

These rules come first. A request that conflicts with them needs an explicit
decision from the maintainer.

- **Never write to the upstream project** (any repository of the `agorakit`
  organisation): no push, issue, pull request, comment, review or report.
  Reading upstream is fine.
- **All work happens in this fork** (`Th3Gu1t/agorakit`): an issue, a branch,
  then a pull request against the `main` branch of the fork.
- **Name the target of every GitHub write**: `gh ... --repo Th3Gu1t/agorakit`.
  In a fork the GitHub CLI can default to the parent repository.
- **Do not link to upstream issues, pull requests or commits** from the
  issues, pull requests or commit messages of the fork: no
  `agorakit/agorakit#123`, no URL. GitHub posts a visible backlink on the
  upstream item. Write "upstream issue 123" in plain text.
- **Merging is delegated.** Merge the pull requests of the fork once their CI
  is green (the maintainer's decision of 2026-11-12). Never push to `main`
  directly, and never change repository settings or branch protections
  without being asked to.
- **AGPL-3.0**: everything derived from this code stays under AGPL-3.0, and
  the source of what is deployed must be public. Do not add code or assets
  under an incompatible licence.

A Claude Code hook (`.claude/hooks/guard-upstream.sh`) denies pushes and
GitHub writes that target upstream or leave their target implicit, and asks
the maintainer before a change of repository settings or branch protections.
It is a safety net for mistakes: follow the rules even where the hook cannot
see.

## Fork-only files

Files that exist only for this fork are listed in
`.github/fork/fork-only-paths`: this guide, `.claude/`, `.github/fork/`, the
`fork-*.yml` workflows, and the issue and pull request templates. Everything
else is application code, shared with upstream.

A commit touches either fork-only files or application code, never both. This
is what makes it possible to extract application changes for upstream later.
Prefer new fork-only files over edits to upstream files (`readme.md`,
`.github/workflows/php.yml`, ...): every such edit is a future merge conflict.

When the association needs behaviour upstream would not want, make it
configurable (setting, `.env`, `config/agorakit.php`) rather than hard-coded,
so the change stays proposable.

## Workflow for a change

1. **Issue** in the fork: context, goal, acceptance criteria, implementation
   notes. Label it `upstream-candidate` or `fork-only`.
2. **Branch** from an up-to-date `main`: `<type>/<short-name>`, for example
   `fix/login-link-throttle` or `feat/magic-link-login`.
3. **Commits** following the rules below. One purpose per pull request.
4. **Before pushing**: run the tests and `.github/fork/check-commits.sh`.
5. **Pull request** against `main` of the fork, following the template, with
   `Closes #<issue>`. Open it as a draft while it is not ready.
6. **CI** must be green: `PHP Composer` (tests) and `Fork checks` (commits
   and coding standard).
7. **Merge** with a merge commit:
   `gh pr merge <number> --repo Th3Gu1t/agorakit --merge`. Merge pull
   requests that depend on another one after it.
8. The maintainer reviews when they can, before or after the merge: the
   description must let them do it alone. Leave a pull request open for them,
   and say why in its description, when it carries a decision that is theirs:
   a product choice, a change of data or anything that affects deployment.

## Commits

Atomic and understandable on their own: the maintainer reviews commit by
commit, and commits are the unit that may be proposed upstream.

- One logical change per commit, with the tests that cover it. The test suite
  passes at every commit.
- [Conventional Commits](https://www.conventionalcommits.org):
  `<type>(<scope>): <description>`, in English, imperative mood, at most 72
  characters. Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`,
  `test`, `build`, `ci`, `chore`, `revert`.
- The body says why the change is needed and what was chosen over what. Leave
  it out only when the subject says it all.
- Scope `fork` if and only if the commit touches fork-only files, for example
  `docs(fork): ...` or `ci(fork): ...`. Other scopes name the area: `auth`,
  `calendar`, `groups`, `files`, `i18n`, ...
- Never mix a functional change with reformatting, coding-standard fixes or CI
  changes.
- No issue number (`#12`) in the message of an application commit: once
  proposed upstream, `#12` would point at an unrelated upstream issue. The
  pull request description carries `Closes #12`. Fork-only commits never
  leave the fork and may reference its issues.
- Commits are GPG-signed (configured on the development machine). Do not
  bypass signing or hooks.
- Never rewrite `main`. Rewriting a pull request branch (`commit --fixup`,
  `rebase --autosquash`, `push --force-with-lease`) keeps its history clean
  but replaces commits the maintainer may have read: do it only with their
  go-ahead. Without it, add a follow-up commit that stands on its own.

`.github/fork/check-commits.sh` checks the format, the fork-only separation,
the scope and the absence of issue references; CI also checks signatures and
the pull request title, which becomes the subject of the merge commit.

## Languages

- **English**: code, comments, commit messages, pull request titles, this
  guide and the skills. This matches upstream.
- **French**: issues, pull request descriptions, and conversation with the
  maintainer.
- **Interface text** always goes through `__()` or `trans()`, never
  hard-coded. Add the English source and the French translation
  (`resources/lang/en*`, `resources/lang/fr*`), keeping the existing order of
  the files. Upstream manages the other languages through translation.io.

## Project overview

- Laravel 11 on PHP `^8.2`. Composer pins the platform to PHP 8.2 and the CI
  runs 8.2: do not use newer syntax, even if the local PHP is more recent.
- MySQL or MariaDB. SQLite is not supported.
- A hypermedia application: Blade views with [Unpoly](https://unpoly.com),
  Tabler and Bootstrap styles, flat CSS and JavaScript in `public/`. There is
  no asset build step; do not introduce one.
- Models sit at the root of `app/` (`App\User`, `App\Group`,
  `App\Discussion`, ...), not in `app/Models`.
- Routes are in `routes/web.php`, written as documentation, with string
  controller references. Controllers are in `app/Http/Controllers`.
- Authorisation goes through policies (`app/Policies`) and
  `$this->authorize()`. Group visibility matters: closed and secret groups
  must not leak content to non-members.
- `app/Services` holds domain services; the `Context` facade knows the groups
  the current user can see.
- Helpers in `app/Helpers/Functions.php`: `setting()` (database settings),
  `flash()`, `warning()`, `error()` (messages shown to the user).
- E-mail: mailables in `app/Mail`, Markdown views in `resources/views/emails`.
- Console commands in `app/Console/Commands` (`agorakit:*`), scheduled in
  `app/Console/Kernel.php`.
- Sign-in lives in `app/Http/Controllers/Auth`. A login link by e-mail already
  exists: an empty password on `/login`, or `/login/email`, sends a signed
  link handled by `AutoLoginController`.

Upstream documentation: <https://docs.agorakit.org> (source in
`agorakit/agorakit-docs`).

## Commands

First setup of a clone:

```sh
.github/fork/setup-clone.sh        # remotes, git hooks, GitHub CLI default
composer install
cp -n .env.ci .env && php artisan key:generate
```

The tests need a MySQL database matching `.env.ci`:

```sh
docker start agorakit-test-db 2>/dev/null || docker run -d \
  --name agorakit-test-db --restart unless-stopped \
  -e MYSQL_DATABASE=agorakit_testing -e MYSQL_ROOT_PASSWORD=testrootpass \
  -p 127.0.0.1:3306:3306 mysql:8.0
```

Day to day:

```sh
vendor/bin/phpunit                          # everything, under a minute
vendor/bin/phpunit tests/e2e/UserTest.php   # one file
composer test-unit                          # also test-int, test-e2e
composer lint                               # coding standard (PSR-12)
composer scan                               # PHPStan, level 0
.github/fork/check-commits.sh               # commits of the branch against origin/main
```

Things to know:

- The end-to-end tests (`tests/e2e`, Laravel BrowserKit) are a scenario, not
  independent tests. `UserTest::testSetupItAll` runs `migrate:fresh`, and later
  tests rely on the users and groups created by earlier ones (see the helpers
  in `tests/BaseTest.php`). A new end-to-end file must be added to the `E2E`
  suite in `phpunit.xml`, where order matters.
- `composer lint` passes on `main` and CI runs it: keep it that way. Fix the
  lines you write; do not reformat code you do not otherwise change.
- `composer scan` reports known errors on `main`, tied to the optional IMAP
  package. Do not add new ones.

## Procedures

Two project skills describe the recurring fork operations:

- `sync-upstream`: bring upstream changes into the fork through a pull request.
- `prepare-upstream-branch`: build a clean branch of one change, without
  fork-only files, that the maintainer could propose upstream.
