#!/usr/bin/env bash
# Baut das Frontend und packt das WordPress-Plugin als ZIP (dist/mk-lank-app.zip).
# Das ZIP wird in WordPress unter „Plugins → Installieren → Plugin hochladen“ installiert.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

cd "$ROOT/app"
npm ci
npm run build

mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/mk-lank-app.zip"
cd "$ROOT/plugin"
zip -rq "$ROOT/dist/mk-lank-app.zip" mk-lank-app -x '*.DS_Store'
echo "Fertig: $ROOT/dist/mk-lank-app.zip"
