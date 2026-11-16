#!/usr/bin/env bash
#
# Writes the notes of a release of the fork, in Markdown, on standard output:
# the upstream version it is based on, then the pull requests merged since the
# previous release, grouped by type, and how to install or upgrade.
#
# Usage: release-notes.sh <version> <git revision>
#
# The previous release is the latest tag "v*-fork.*" before the revision. For
# the first release, the latest upstream tag is used instead. Following the
# first parent of main lists each pull request once, by its merge commit, and
# leaves out the commits that a sync brought from upstream.

set -euo pipefail

version=${1:?usage: release-notes.sh <version> <git revision>}
revision=${2:?usage: release-notes.sh <version> <git revision>}

readonly FORK_TAGS='v*-fork.*'

since=$(git describe --tags --abbrev=0 --match "$FORK_TAGS" "$revision^" 2> /dev/null \
    || git describe --tags --abbrev=0 --exclude "$FORK_TAGS" "$revision" 2> /dev/null \
    || true)
range=${since:+$since..}$revision

upstream_version=$(git show "$revision:config/agorakit.php" \
    | sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p")

subjects=$(git log --first-parent --format='%s' "$range")

# Prints a section with the subjects that grep selects with the given options.
section() {
    local title=$1 lines
    shift
    lines=$(printf '%s\n' "$subjects" | grep "$@" || true)
    if [[ -n $lines ]]; then
        printf '### %s\n\n' "$title"
        printf '%s\n' "$lines" | sed 's/^/- /'
        printf '\n'
    fi
}

cat << EOF
Version \`$version\` de l'instance, basée sur Agorakit $upstream_version.

## Changements${since:+ depuis $since}

EOF

section 'Nouveautés' -E '^feat(\(|!|:)'
section 'Corrections' -E '^fix(\(|!|:)'
section 'Autres changements' -vE '^(feat|fix)(\(|!|:)'

cat << EOF
## Mettre à jour depuis le site

Pour une instance en \`v1.11.4-fork.2\` ou plus récente :

1. Supprimez le fichier \`setup.lock\` à la racine d'Agorakit, par FTP ou avec le gestionnaire de fichiers de l'hébergeur.
2. Ouvrez \`/setup.php\` sur le site, puis, dans « Mise à jour d'Agorakit » : « Rechercher une mise à jour », « Télécharger et vérifier », puis « Installer la version ».
3. Lancez les migrations s'il y en a, puis verrouillez la page : le site revient en ligne.

## Installer, ou mettre à jour une version plus ancienne

1. Téléchargez \`agorakit-$version.zip\` ci-dessous et décompressez-le.
2. Envoyez le contenu du dossier \`agorakit-$version\` sur l'hébergement, par-dessus l'installation existante s'il y en a une : le fichier \`.env\` et les fichiers des groupes, dans \`storage\`, sont conservés.
3. Pour une mise à jour, supprimez le fichier \`setup.lock\` à la racine.
4. Ouvrez \`/setup.php\` sur le site et suivez les étapes, puis verrouillez.

Le domaine doit pointer vers le dossier \`public\`, jamais vers la racine : sinon le fichier \`.env\` serait lisible par tous.

Somme de contrôle SHA-256 : voir \`agorakit-$version.zip.sha256\`.
EOF
