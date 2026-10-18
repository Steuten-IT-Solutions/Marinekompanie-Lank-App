#!/usr/bin/env bash
# Richtet eine lokale Test-WordPress-Instanz ein (SQLite statt MySQL, kein Docker nötig).
#
#   scripts/setup-test-wp.sh [ZIELVERZEICHNIS]   (Standard: .wp-test)
#   PHP_CLI_SERVER_WORKERS=4 php -S localhost:8888 -t .wp-test/wp .wp-test/router.php
#
# Danach: http://localhost:8888/app/  –  Benutzer spiess / mariner1 / mariner2, Passwort test1234
#         http://localhost:8888/wp-admin/  –  admin / admin
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$(mkdir -p "${1:-$ROOT/.wp-test}" && cd "${1:-$ROOT/.wp-test}" && pwd)"
URL="${MKL_TEST_URL:-http://localhost:8888}"
WP="php $DIR/wp-cli.phar --allow-root --path=$DIR/wp"

if [ ! -f "$DIR/wp/wp-load.php" ]; then
  mkdir -p "$DIR/wp"
  curl -sSL https://wordpress.org/latest.tar.gz | tar xz -C "$DIR/wp" --strip-components=1
  curl -sSLo "$DIR/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
  unzip -qo "$DIR/sqlite.zip" -d "$DIR/wp/wp-content/plugins/"
  curl -sSLo "$DIR/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  SQLITE="$DIR/wp/wp-content/plugins/sqlite-database-integration"
  sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$SQLITE/db.copy" > "$DIR/wp/wp-content/db.php"
fi

if ! $WP core is-installed 2>/dev/null; then
  $WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check --force
  $WP core install --url="$URL" --title="Marinekompanie Test" --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.com --skip-email
  # WP-CLI leitet siteurl sonst aus dem Installationspfad ab (…/wp).
  $WP option update home "$URL"
  $WP option update siteurl "$URL"
  $WP option update timezone_string Europe/Berlin
  $WP rewrite structure '/%postname%/'
fi

ln -sfn "$ROOT/plugin/mk-lank-app" "$DIR/wp/wp-content/plugins/mk-lank-app"
$WP plugin activate mk-lank-app

for u in spiess mariner1 mariner2; do
  $WP user get "$u" >/dev/null 2>&1 || \
    $WP user create "$u" "$u@example.com" --user_pass=test1234 --role=mkl_mitglied --display_name="${u^}" >/dev/null
done
$WP user add-role spiess mkl_spiess >/dev/null
# Anwärter: bei jedem Lauf zurücksetzen, weil die E2E-Tests ihn befördern.
$WP user get anwaerter >/dev/null 2>&1 || \
  $WP user create anwaerter anwaerter@example.com --user_pass=test1234 --role=mkl_anwaerter --display_name=Anwaerter >/dev/null
$WP user set-role anwaerter mkl_anwaerter >/dev/null

# Beispieltermine als iCal-Feed (ersetzt im Test den Google Kalender).
Y=$(date +%Y); N=$((Y + 1))
cat > "$DIR/wp/test.ics" <<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:e1
DTSTART;VALUE=DATE:${N}0508
DTEND;VALUE=DATE:${N}0509
SUMMARY:Maifest
LOCATION:Alter Schulhof
END:VEVENT
BEGIN:VEVENT
UID:e2
DTSTART;TZID=Europe/Berlin:$(date -d '+7 days' +%Y%m%d)T190000
DTEND;TZID=Europe/Berlin:$(date -d '+7 days' +%Y%m%d)T230000
SUMMARY:Kompanieabend
LOCATION:Vereinslokal
DESCRIPTION:Antreten in Uniform.\nBitte pünktlich sein!
END:VEVENT
BEGIN:VEVENT
UID:e4
DTSTART;TZID=Europe/Berlin:$(date -d '-3 days' +%Y%m%d)T100000
DTEND;TZID=Europe/Berlin:$(date -d '-3 days' +%Y%m%d)T140000
SUMMARY:Arbeitsdienst Schützenplatz
LOCATION:Schützenplatz
END:VEVENT
BEGIN:VEVENT
UID:e3
DTSTART;VALUE=DATE:${N}0625
DTEND;VALUE=DATE:${N}0629
SUMMARY:Schützenfest
LOCATION:Schützenplatz
END:VEVENT
END:VCALENDAR
ICS
$WP option update mkl_ics_url "$URL/test.ics" >/dev/null

# Beispiel-Galerie über simuliertes Google Drive (nur Test-Instanz).
mkdir -p "$DIR/wp/wp-content/mu-plugins"
ln -sfn "$ROOT/plugin/tests/fixtures/drive-mock.php" "$DIR/wp/wp-content/mu-plugins/mkl-drive-mock.php"
$WP eval '
$key = openssl_pkey_new( array( "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $key, $pem );
MKL_Drive::save_service_account( wp_json_encode( array( "client_email" => "test@example.iam.gserviceaccount.com", "private_key" => $pem ) ) );
update_option( MKL_Gallery::ROOT_OPTION, "TESTROOT", false );
MKL_Gallery::sync();
MKL_Gallery::process_pending( 60 );
' >/dev/null

cat > "$DIR/router.php" <<'PHP'
<?php
// Router für den PHP-Entwicklungsserver: vorhandene Dateien direkt, sonst WordPress.
$root = __DIR__ . '/wp';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = realpath( $root . $path );
if ( '/' !== $path && $file && is_file( $file ) ) {
	if ( '.php' !== substr( $file, -4 ) ) {
		return false;
	}
	chdir( dirname( $file ) );
	$_SERVER['SCRIPT_NAME']     = $path;
	$_SERVER['SCRIPT_FILENAME'] = $file;
	require $file;
	return true;
}
if ( $file && is_dir( $file ) && is_file( $file . '/index.php' ) ) {
	chdir( $file );
	require $file . '/index.php';
	return true;
}
chdir( $root );
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
PHP

echo "Test-WordPress bereit in $DIR"
echo "Start: PHP_CLI_SERVER_WORKERS=4 php -S ${URL#*://} -t $DIR/wp $DIR/router.php"
