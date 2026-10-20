#!/usr/bin/env bash
# Tests der Übernahme in einer eigenen Test-WordPress-Instanz:
#   KOMPANIE_TEMPLATE=/pfad/zum/Kompanie-App-Template uebernahme/tests/run.sh [ZIELVERZEICHNIS]
# 1. mk-lank-app aktiv, synthetische Daten anlegen
# 2. mk-lank-app deaktivieren, Kompanie-App und Übernahme aktivieren, Probelauf/Übernahme/Wiederholung/Aliase prüfen
# 3. Rückfall: altes Plugin wieder aktiv, Lank-Daten unverändert
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEMPLATE="$(cd "${KOMPANIE_TEMPLATE:?KOMPANIE_TEMPLATE: Pfad zum Kompanie-App-Template}" && pwd)"
DIR="$(mkdir -p "${1:-$ROOT/.wp-test-uebernahme}" && cd "${1:-$ROOT/.wp-test-uebernahme}" && pwd)"
WP="php $DIR/wp-cli.phar --allow-root --path=$DIR/wp"

# Frische Datenbank und Uploads, WordPress selbst bleibt.
rm -rf "$DIR/wp/wp-content/database" "$DIR/wp/wp-content/uploads"
"$ROOT/scripts/setup-test-wp.sh" "$DIR" >/dev/null
ln -sfn "$TEMPLATE/adapters/wordpress" "$DIR/wp/wp-content/plugins/kompanie-app"
ln -sfn "$ROOT/uebernahme/mkl-uebernahme" "$DIR/wp/wp-content/plugins/mkl-uebernahme"

$WP eval-file "$ROOT/uebernahme/tests/seed.php"
$WP plugin deactivate mk-lank-app >/dev/null
$WP plugin activate kompanie-app mkl-uebernahme >/dev/null
$WP eval-file "$ROOT/uebernahme/tests/run-tests.php"
$WP mkl-uebernahme probelauf >/dev/null
echo "  ok   WP-CLI: wp mkl-uebernahme probelauf"

$WP plugin deactivate mkl-uebernahme kompanie-app >/dev/null
$WP plugin activate mk-lank-app >/dev/null
$WP eval-file "$ROOT/uebernahme/tests/rollback-test.php"
