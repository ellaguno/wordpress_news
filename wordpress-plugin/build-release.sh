#!/usr/bin/env bash
#
# Genera el zip de release del plugin AI Content Generator.
#
# - Lee la versión del header del plugin (fuente única de verdad)
# - Verifica que AICG_VERSION y el Stable tag del readme.txt coincidan
# - Genera ai-content-generator-vX.Y.Z.zip en este directorio
#   (los zips están en .gitignore; súbelos como GitHub Release o al sitio)
#
# Uso: ./build-release.sh

set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_DIR="ai-content-generator"
MAIN_FILE="$PLUGIN_DIR/ai-content-generator.php"

VERSION=$(grep -oP '^\s*\*\s*Version:\s*\K[0-9.]+' "$MAIN_FILE")
CONST_VERSION=$(grep -oP "define\('AICG_VERSION',\s*'\K[0-9.]+" "$MAIN_FILE")
STABLE_TAG=$(grep -oP '^Stable tag:\s*\K[0-9.]+' "$PLUGIN_DIR/readme.txt")

if [[ -z "$VERSION" ]]; then
    echo "ERROR: no se pudo leer la versión del header de $MAIN_FILE" >&2
    exit 1
fi

if [[ "$VERSION" != "$CONST_VERSION" ]]; then
    echo "ERROR: el header dice $VERSION pero AICG_VERSION es $CONST_VERSION" >&2
    exit 1
fi

if [[ "$VERSION" != "$STABLE_TAG" ]]; then
    echo "ERROR: el header dice $VERSION pero el Stable tag del readme.txt es $STABLE_TAG" >&2
    exit 1
fi

ZIP_FILE="ai-content-generator-v${VERSION}.zip"

rm -f "$ZIP_FILE"
zip -rq "$ZIP_FILE" "$PLUGIN_DIR" \
    -x "*.git*" -x "*.DS_Store" -x "*Thumbs.db"

echo "OK: $ZIP_FILE (versión $VERSION)"
