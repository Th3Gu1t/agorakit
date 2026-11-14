#!/usr/bin/env bash
#
# Builds the archive of a release of the fork: the code of a git revision with
# its PHP dependencies installed, ready to upload to a web host without SSH.
#
# Usage: build-release.sh <version> <git revision> [<output directory>]
#
# Writes <output directory>/agorakit-<version>.zip and its .sha256 checksum.
# The archive holds a single folder, agorakit-<version>/, with:
# - the tracked files of the revision, as "git archive" exports them, minus
#   what only serves development (tests, Docker, guides for AI assistants);
# - vendor/, from "composer install --no-dev" against composer.lock;
# - VERSION, naming the version and the commit it was built from.
# It holds no .env, no setup.lock and no user data: unpacking it over an
# installation keeps its configuration and its files.

set -euo pipefail

version=${1:?usage: build-release.sh <version> <git revision> [<output directory>]}
revision=${2:?usage: build-release.sh <version> <git revision> [<output directory>]}
output=${3:-dist}

# Development files that "git archive" would otherwise export.
readonly DEV_ONLY=(
    CLAUDE.md
    .claude
    .ddev
    .docker
    .env.docker
    .editorconfig
    bin/dev-connect.sh
    bin/dev-init.sh
)

root=$(git rev-parse --show-toplevel)
commit=$(git -C "$root" rev-parse --verify "$revision^{commit}")
name="agorakit-$version"

mkdir -p "$output"
output=$(cd "$output" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

echo "Exporting $commit as $name"
git -C "$root" archive --format=tar --prefix="$name/" "$commit" | tar -x -C "$work"
for path in "${DEV_ONLY[@]}"; do
    rm -rf "${work:?}/$name/$path"
done

echo "Installing the dependencies"
# The post-install scripts discover the Laravel packages; they need no .env.
composer install --working-dir="$work/$name" --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction --no-progress

printf '%s\n%s\n' "$version" "$commit" > "$work/$name/VERSION"

echo "Packing $output/$name.zip"
rm -f "$output/$name.zip"
(cd "$work" && zip -q -r -X "$output/$name.zip" "$name")
(cd "$output" && sha256sum "$name.zip" > "$name.zip.sha256")

echo "Done: $(du -h "$output/$name.zip" | cut -f1)"
