#!/usr/bin/env bash
# Baut das Frontend und packt das WordPress-Plugin als ZIP (dist/mk-lank-app.zip).
# Das ZIP wird in WordPress unter „Plugins → Installieren → Plugin hochladen“ installiert.
#
# Optional: MKL_VERSION=0.1.3 scripts/build-plugin.sh
#   schreibt diese Version in die gepackte Kopie (Plugin-Header + MKL_VERSION),
#   ohne die Dateien im Repository zu verändern.
# Optional: MKL_SKIP_FRONTEND=1 überspringt den Frontend-Build (app-dist muss existieren).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [ -z "${MKL_SKIP_FRONTEND:-}" ]; then
  (cd "$ROOT/app" && npm ci && npm run build)
fi
if [ ! -f "$ROOT/plugin/mk-lank-app/app-dist/index.html" ]; then
  echo "Fehler: plugin/mk-lank-app/app-dist fehlt – Frontend zuerst bauen." >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp -R "$ROOT/plugin/mk-lank-app" "$STAGE/mk-lank-app"

if [ -n "${MKL_VERSION:-}" ]; then
  if ! [[ "$MKL_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Fehler: MKL_VERSION muss die Form X.Y.Z haben." >&2
    exit 1
  fi
  sed -i.bak -E \
    -e "s/^( \* Version:[[:space:]]+).*/\1$MKL_VERSION/" \
    -e "s/define\( 'MKL_VERSION', '[^']*' \);/define( 'MKL_VERSION', '$MKL_VERSION' );/" \
    "$STAGE/mk-lank-app/mk-lank-app.php"
  rm -f "$STAGE/mk-lank-app/mk-lank-app.php.bak"
fi

mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/mk-lank-app.zip"
(cd "$STAGE" && zip -rq "$ROOT/dist/mk-lank-app.zip" mk-lank-app -x '*.DS_Store')
echo "Fertig: $ROOT/dist/mk-lank-app.zip"
