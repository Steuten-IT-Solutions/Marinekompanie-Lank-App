# Marinekompanie Lank – App

Mitglieder-App der Marinekompanie 1973 Lank mit drei Funktionen:

- **Termine:** kommen aus dem Google Kalender, der auch auf der Webseite angezeigt wird.
- **Punkte:** mit Punktekatalog (Vorlagen).
- **Strafen:** Geldbeträge mit Status offen/bezahlt.

Benutzer mit der Rolle **Spieß** vergeben Punkte und Strafen. Alle anderen Mitglieder sehen **ausschließlich ihre eigenen** Punkte und Strafen.

## Warum kein Apple Developer Account nötig ist

Die App ist eine **Progressive Web App (PWA)**. Sie wird über den Browser installiert und braucht deshalb weder App Store noch TestFlight noch einen kostenpflichtigen Developer Account. Updates sind sofort für alle verfügbar, sobald das Plugin aktualisiert ist.

| Gerät | Installation |
|---|---|
| iPhone/iPad | In **Safari** `https://marinekompanie-lank.de/app/` öffnen → **Teilen** → **Zum Home-Bildschirm** |
| Android | In **Chrome** die Adresse öffnen → Menü **⋮** → **App installieren** |

Hinweis für das iPhone: Die installierte App hat ihren eigenen Speicher, getrennt von Safari. Man meldet sich also einmal direkt in der App an. Mit „Angemeldet bleiben“ hält die Anmeldung 14 Tage.

## Aufbau

Alles steckt in einem **WordPress-Plugin** (`plugin/mk-lank-app`). Es läuft auf dem vorhandenen Webspace der Webseite und braucht nur PHP und MySQL, kein Node.js auf dem Server.

```
app/                       React + TypeScript (Vite) – Quellcode der PWA
  src/views/               Termine, Punkte, Strafen, Spieß-Bereich
  public/sw.js             Service Worker (Offline-Anzeige der zuletzt geladenen Daten)
  e2e/                     Playwright-Tests
plugin/mk-lank-app/        WordPress-Plugin
  includes/class-mkl-db.php        Tabellen + Datenzugriff (Beträge in Cent)
  includes/class-mkl-rest.php      REST-API /wp-json/mk-lank/v1/ inkl. Rechteprüfung
  includes/class-mkl-roles.php     Rolle „Spieß“ (Checkbox im Benutzerprofil)
  includes/class-mkl-calendar.php  Google Kalender / iCal, mit Zwischenspeicher
  includes/class-mkl-app-page.php  liefert die App unter /app/ aus
  includes/class-mkl-settings.php  Einstellungen → Kompanie-App
  app-dist/                Build-Ausgabe des Frontends (nicht im Git)
plugin/tests/              Integrationstests der REST-API
scripts/                   Build- und Test-Skripte
```

- **Login:** Es gilt der normale WordPress-Login. Wer nicht angemeldet ist, wird von `/app/` zum Login und danach zurück in die App geleitet.
- **Rechte:** Die `/me`-Endpunkte lesen die Benutzer-ID immer aus der Sitzung, nie aus der Anfrage. Alle Verwaltungs-Endpunkte verlangen die Capability `mkl_manage`. Die haben nur Spieße und Administratoren.
- **Nachvollziehbarkeit:** Zu jedem Eintrag wird gespeichert, wer ihn angelegt hat. Stornierte Einträge werden nicht gelöscht, sondern nur ausgeblendet.

## Installation auf der Webseite

1. **Backup** der Webseite anlegen.
2. Das Plugin-ZIP besorgen: Lade `mk-lank-app.zip` aus dem neuesten GitHub-Release herunter (siehe [Updates](#updates)). Alternativ baust du es selbst mit `scripts/build-plugin.sh`; das Ergebnis liegt dann in `dist/mk-lank-app.zip`.
3. In WordPress **Plugins → Installieren → Plugin hochladen**, das ZIP auswählen und aktivieren.
4. **Einstellungen → Kompanie-App** öffnen und **„Kalender jetzt testen“** klicken.
   - Sind die Felder leer, nutzt die App automatisch die Kalender und Zugangsdaten aus dem Plugin **„Private Google Calendars“**, das die Webseite schon verwendet.
   - Alternativ trägst du eine Kalender-ID mit API-Key ein, oder eine iCal-Adresse.
5. **Spieße festlegen:** Unter Benutzer → Profil bearbeiten die Checkbox **„Spieß“** setzen. Die bisherige Rolle, z. B. Redakteur, bleibt dabei erhalten.
6. Jedes Mitglied braucht ein WordPress-Benutzerkonto. Die Rolle „Abonnent“ reicht. Administratoren können Mitglieder direkt in der App anlegen: **Spieß → Mitglieder → „+ Mitglied anlegen“**. Die App erzeugt ein zufälliges Startpasswort, zeigt es **nur einmal** an und verschickt keine E-Mail. Gebt die Zugangsdaten persönlich weiter. Das Mitglied kann das Passwort danach unter `/wp-admin/profile.php` ändern. Spieße ohne Admin-Rechte sehen diese Funktion nicht.
7. Die **Datenschutzerklärung** ergänzen: Die App verarbeitet Punkte und Strafen der Mitglieder. Die Daten liegen in der WordPress-Datenbank auf dem eigenen Server.

Danach ist die App unter `https://marinekompanie-lank.de/app/` erreichbar.

> Hinweis: Wird die Rolle eines Benutzers später über die **Sammelaktion** in der Benutzerliste geändert, entfernt WordPress dabei auch die Zusatzrolle „Spieß“. Dann einfach die Checkbox im Profil wieder setzen.

## Updates

Jeder Merge auf `main` startet zuerst alle Tests. Sind sie grün, entsteht automatisch ein **GitHub-Release** mit der Datei `mk-lank-app.zip`. Du findest es unter **Releases** rechts auf der Repo-Seite.

So spielst du ein Update ein:

1. Lade `mk-lank-app.zip` aus dem neuesten Release herunter.
2. Gehe in WordPress zu **Plugins → Installieren → Plugin hochladen** und wähle das ZIP aus.
3. Bestätige **„Aktuelle Version durch hochgeladene ersetzen“**.

Daten und Einstellungen bleiben dabei erhalten.

Versionsnummern:
- Bei jedem Merge wird die letzte Stelle automatisch hochgezählt, z. B. `0.1.0 → 0.1.1`.
- Für einen größeren Sprung trägst du die neue Version im Plugin-Header von `plugin/mk-lank-app/mk-lank-app.php` ein, z. B. `Version: 0.2.0` und `MKL_VERSION`. Der nächste Merge veröffentlicht dann genau diese Version.

## Entwicklung

Voraussetzungen: PHP ≥ 7.4 mit SQLite-Erweiterung, Node.js ≥ 20. Docker und MySQL sind nicht nötig.

```bash
# Frontend bauen (landet direkt in plugin/mk-lank-app/app-dist)
cd app && npm ci && npm run build && cd ..

# Lokales Test-WordPress (SQLite) mit Plugin, Testbenutzern und Beispielterminen
scripts/setup-test-wp.sh
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8888 -t .wp-test/wp .wp-test/router.php
```

- App: http://localhost:8888/app/. Benutzer `spiess`, `mariner1`, `mariner2`, Passwort `test1234`.
- WP-Admin: http://localhost:8888/wp-admin/ mit `admin` / `admin`.

Nach Änderungen am Frontend `npm run build` erneut ausführen und die Seite neu laden.

### Tests

```bash
# REST-API: Rechte, Validierung, Summen, ICS-Parser
php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp eval-file plugin/tests/rest-api-test.php

# Ende-zu-Ende im Browser (Server muss laufen)
cd app && npx playwright test
```

GitHub Actions führt bei jedem Push und jedem Pull Request alles aus: Syntaxprüfung, Build, REST-Tests und E2E-Tests. Danach stellt der Workflow das Plugin-ZIP als Artefakt bereit. Ein Merge auf `main` veröffentlicht zusätzlich ein Release.

## Ideen für später

- Push-Benachrichtigung bei neuer Strafe oder neuem Termin (iOS ab 16.4, nur bei installierter App)
- Zu- und Absagen für Termine
- Strafenkatalog analog zum Punktekatalog
