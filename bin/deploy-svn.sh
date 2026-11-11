#!/usr/bin/env bash
# Publica una versión etiquetada en Git en el SVN de WordPress.org.
#
# Uso:  bin/deploy-svn.sh 1.0.1            (prepara y enseña los cambios; no sube nada)
#       bin/deploy-svn.sh 1.0.1 --commit   (sube trunk, tags/1.0.1 y assets)
#
# Requisitos: repositorio Git limpio, etiqueta vX.Y.Z creada, la misma versión en
# inochi-pulso.php (Version e INOCHI_PULSO_VERSION) y en «Stable tag» de readme.txt.
# La contraseña de SVN se pide al ejecutar con --commit (no se guarda en este script).
set -euo pipefail

VERSION="${1:?Indica la versión, p. ej. 1.0.1}"
COMMIT="${2:-}"
SLUG="inochi-pulso"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
SVN_USER="inochidev"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${SVN_WORKDIR:-${ROOT}/../${SLUG}-svn}"

cd "$ROOT"
[ -z "$(git status --porcelain)" ] || { echo "Hay cambios sin confirmar en Git." >&2; exit 1; }
git rev-parse -q --verify "refs/tags/v${VERSION}" >/dev/null || { echo "Falta la etiqueta v${VERSION}." >&2; exit 1; }
grep -q "^ \* Version: *${VERSION}$" inochi-pulso.php || { echo "La cabecera de inochi-pulso.php no dice ${VERSION}." >&2; exit 1; }
grep -q "'INOCHI_PULSO_VERSION', '${VERSION}'" inochi-pulso.php || { echo "INOCHI_PULSO_VERSION no es ${VERSION}." >&2; exit 1; }
grep -q "^Stable tag: ${VERSION}$" readme.txt || { echo "«Stable tag» de readme.txt no es ${VERSION}." >&2; exit 1; }

if [ ! -d "${WORK}/.svn" ]; then
	svn checkout --depth immediates "$SVN_URL" "$WORK"
	svn update --set-depth infinity "${WORK}/trunk" "${WORK}/assets"
	svn update --set-depth immediates "${WORK}/tags"
else
	svn update "$WORK"
fi
[ ! -e "${WORK}/tags/${VERSION}" ] || { echo "tags/${VERSION} ya existe en SVN: las versiones publicadas no se reescriben." >&2; exit 1; }

# trunk = exactamente lo que va en el ZIP (git archive respeta export-ignore).
TMP="$(mktemp -d)"
git archive "v${VERSION}" | tar -x -C "$TMP"
rsync -a --delete --exclude=.svn "${TMP}/" "${WORK}/trunk/"
if [ -d .wordpress-org ]; then
	rsync -a --delete --exclude=.svn .wordpress-org/ "${WORK}/assets/"
fi
rm -rf "$TMP"

cd "$WORK"
svn status | awk '/^\?/ {print $2}' | xargs -r svn add -q
svn status | awk '/^!/ {print $2}' | xargs -r svn delete -q
svn copy -q trunk "tags/${VERSION}"
svn status

if [ "$COMMIT" = "--commit" ]; then
	svn commit --username "$SVN_USER" -m "Release ${VERSION}"
	echo "Publicado ${VERSION}."
else
	echo "Revisión preparada en ${WORK}. Nada subido. Repite con --commit para publicar."
fi
