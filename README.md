# Marinekompanie Lank – App

App der Marinekompanie 1973 Lank mit diesen Bereichen:

- **Termine:**
  - Gäste sehen die öffentliche Terminliste, die Admins in der App pflegen. Dieselbe Liste erscheint per Shortcode auf der Startseite.
  - Mitglieder sehen den Google Kalender mit allen Terminen.
- **News:** die Blogbeiträge der Webseite.
- **Galerie:** Bilder aus Google Drive. Alle sehen die öffentlichen Bilder; Mitglieder sehen zusätzlich die Bilder aus den Ordnern „intern“.
- **Meins:** eigene Punkte (mit Punktekatalog) und Strafen (Geldbeträge, offen/bezahlt).
- **Spieß:** Punkte und Strafen vergeben, Mitglieder verwalten.

### Rollen

| Rolle | Darf |
|---|---|
| **Admin** (WordPress-Administrator) | alles: öffentliche Termine pflegen, Mitglieder anlegen, Rollen vergeben, dazu alles vom Spieß |
| **Spieß** | Punkte und Strafen vergeben und verwalten, Punktekatalog pflegen |
| **Mitglied** | die eigenen Punkte und Strafen sehen, den Google Kalender mit allen Terminen |

**Gäste** sind Besucher **ohne Anmeldung** und haben keine Rolle. Sie sehen nur öffentliche Termine, News und öffentliche Galeriebilder.

Mitglieder sehen **ausschließlich ihre eigenen** Punkte und Strafen. Ein angemeldetes WordPress-Konto ohne App-Rolle (z. B. ein reines Webseiten-Konto) steht in der App als **„Ohne Rolle“** und sieht dasselbe wie ein Gast.

## Warum kein Apple Developer Account nötig ist

Die App ist eine **Progressive Web App (PWA)**. Sie wird über den Browser installiert und braucht deshalb weder App Store noch TestFlight noch einen kostenpflichtigen Developer Account. Updates sind sofort für alle verfügbar, sobald das Plugin aktualisiert ist.

| Gerät | Installation |
|---|---|
| iPhone/iPad | In **Safari** `https://marinekompanie-lank.de/app/` öffnen → **Teilen** → **Zum Home-Bildschirm** |
| Android | In **Chrome** die Adresse öffnen → Menü **⋮** → **App installieren** |

Die App hilft beim Installieren selbst, damit es auch ohne Smartphone-Erfahrung klappt:

- Oben in der App erscheint ein Hinweis **„Die App aufs Handy holen“**. Er verschwindet, sobald die App installiert ist oder jemand ihn wegklickt.
- **Android (Chrome, Edge, Samsung Internet):** Bietet der Browser die Installation an, reicht ein Tipp auf **„Installieren“**. Danach muss man nur noch bestätigen.
- **iPhone/iPad:** Apple erlaubt Webseiten keinen Installationsknopf. Deshalb führt **„So geht's“** zu einer bebilderten Schritt-für-Schritt-Anleitung (`/app/#/installieren`). Sie zeigt auch die neuere Safari-Leiste mit „•••“ (iOS 26).
- Die Anleitung hat eine eigene Adresse zum Weitergeben: **`https://marinekompanie-lank.de/app/installieren/`**. Diesen Link kann man per WhatsApp, E-Mail oder Rundschreiben verschicken. Auf der Anleitung selbst gibt es dafür den Knopf **„Link teilen“**, außerdem erreicht man sie über **Konto → App installieren**.
- Wurde der Link in Facebook oder Instagram geöffnet, wo man nicht installieren kann, erscheint ein Hinweis mit einem Knopf zum Kopieren der Adresse.

Hinweis für das iPhone: Die installierte App hat ihren eigenen Speicher, getrennt von Safari. Man meldet sich also einmal direkt in der App an. „Angemeldet bleiben“ ist auf der Anmeldeseite der App schon angehakt. Damit bleibt man praktisch dauerhaft angemeldet: Die Anmeldung gilt 400 Tage (mehr erlauben Chrome und Edge für Cookies nicht) und verlängert sich automatisch, sobald man die App öffnet. Wer die App mindestens einmal im Jahr nutzt, muss sich also nie neu anmelden. Abmelden geht über **Konto → Abmelden**. Bei einem verlorenen Handy hilft eine Passwortänderung (meldet alle anderen Geräte ab) oder im WP-Profil des Mitglieds „Überall sonst abmelden“.

## Aufbau

Alles steckt in einem **WordPress-Plugin** (`plugin/mk-lank-app`). Es läuft auf dem vorhandenen Webspace der Webseite und braucht nur PHP und MySQL, kein Node.js auf dem Server.

```
app/                       React + TypeScript (Vite) – Quellcode der PWA
  src/views/               Termine, News, Galerie, Meins (Punkte/Strafen), Spieß-Bereich, Konto
  public/sw.js             Service Worker (Offline-Anzeige der zuletzt geladenen Daten)
  e2e/                     Playwright-Tests
plugin/mk-lank-app/        WordPress-Plugin
  includes/class-mkl-db.php        Tabellen + Datenzugriff (Beträge in Cent)
  includes/class-mkl-rest.php      REST-API /wp-json/mk-lank/v1/ inkl. Rechteprüfung
  includes/class-mkl-roles.php     Rollen Mitglied/Spieß/Admin, Migration
  includes/class-mkl-events.php    Öffentliche Terminliste
  includes/class-mkl-shortcodes.php Shortcodes [mkl_termine] und [mkl_galerie] für die Webseite
  includes/class-mkl-drive.php     Google Drive über Dienstkonto (JWT, ohne Bibliothek)
  includes/class-mkl-gallery.php   Galerie: Abgleich, Bildgrößen, Ablage, Auslieferung
  includes/class-mkl-calendar.php  Google Kalender / iCal, mit Zwischenspeicher
  includes/class-mkl-app-page.php  liefert die App unter /app/ aus
  includes/class-mkl-settings.php  Einstellungen → Kompanie-App
  app-dist/                Build-Ausgabe des Frontends (nicht im Git)
plugin/tests/              Integrationstests der REST-API
scripts/                   Build- und Test-Skripte
```

- **Login:** Es gilt der normale WordPress-Login. Ohne Anmeldung zeigt `/app/` die Gast-Ansicht. Über „Anmelden“ geht es zum WordPress-Login und danach zurück in die App.
- **Rechte:** Die `/me`-Endpunkte lesen die Benutzer-ID immer aus der Sitzung, nie aus der Anfrage. Die Capabilities sind:
  - `mkl_member`: Mitglied
  - `mkl_manage`: Spieß
  - `mkl_manage_events`: Admin
  - Benutzer anlegen und Rollen ändern ist an die WordPress-Rechte `create_users` bzw. `promote_users` gebunden.
- **Nachvollziehbarkeit:** Zu jedem Eintrag wird gespeichert, wer ihn angelegt hat. Stornierte Einträge werden nicht gelöscht, sondern nur ausgeblendet.

## Installation auf der Webseite

1. **Backup** der Webseite anlegen.
2. Das Plugin-ZIP besorgen: Lade `mk-lank-app.zip` aus dem neuesten GitHub-Release herunter (siehe [Updates](#updates)). Alternativ baust du es selbst mit `scripts/build-plugin.sh`; das Ergebnis liegt dann in `dist/mk-lank-app.zip`.
3. In WordPress **Plugins → Installieren → Plugin hochladen**, das ZIP auswählen und aktivieren.
4. **Einstellungen → Kompanie-App** öffnen und **„Kalender jetzt testen“** klicken.
   - Sind die Felder leer, nutzt die App automatisch die Kalender und Zugangsdaten aus dem Plugin **„Private Google Calendars“**, das die Webseite schon verwendet.
   - Alternativ trägst du eine Kalender-ID mit API-Key ein, oder eine iCal-Adresse.
5. **Rollen festlegen:**
   - In der App unter **Spieß → Mitglied antippen → „Rolle in der App“** (Ohne Rolle / Mitglied / Spieß). Mit „Auch Konten ohne Rolle anzeigen“ findest du Konten, die noch keine Rolle haben.
   - Alternativ im WordPress-Profil unter „Rolle in der App“.
   - Die bisherige WordPress-Rolle, z. B. Redakteur, bleibt dabei erhalten.
   - Beim Update auf 0.2.0 werden alle bisherigen Abonnenten automatisch zu Mitgliedern. Wer keins ist, bekommt in der App „Ohne Rolle“.
6. **Öffentliche Termine und Startseite:**
   - Als Admin in der App unter **Termine → „Öffentliche Termine verwalten“** die Termine anlegen, mit optionaler Uhrzeit, Ort, Beschreibung und Link.
   - Dann auf der Startseite im Block-Editor die bisherige Termin-Timeline (Spectra-Block „Content Timeline“) löschen und an ihrer Stelle einen **Shortcode-Block** mit `[mkl_termine]` einfügen.
   - Mit `[mkl_termine anzahl="5"]` begrenzt du die Anzahl.
7. Jedes Mitglied braucht ein WordPress-Benutzerkonto mit der Rolle „Mitglied“. Administratoren können Mitglieder direkt in der App anlegen: **Spieß → Mitglieder → „+ Mitglied anlegen“**. Die App erzeugt ein zufälliges Startpasswort, zeigt es **nur einmal** an und verschickt keine E-Mail. Gebt die Zugangsdaten persönlich weiter. Das Mitglied kann das Passwort danach selbst in der App ändern: oben rechts **„Konto“** → **„Passwort ändern“**. Dafür ist das aktuelle Passwort nötig, das neue muss mindestens 8 Zeichen haben. Auf allen anderen Geräten wird das Mitglied danach abgemeldet. Spieße ohne Admin-Rechte sehen diese Funktion nicht.
8. **Galerie einrichten** (ersetzt das Plugin „Integrate Google Drive“):
   1. In der [Google Cloud Console](https://console.cloud.google.com/) im vorhandenen Projekt die **Google Drive API** aktivieren: [Direktlink](https://console.cloud.google.com/apis/library/drive.googleapis.com).
   2. Unter [IAM → Dienstkonten](https://console.cloud.google.com/iam-admin/serviceaccounts) ein **Dienstkonto** anlegen. Rollen braucht es keine.
   3. Beim Dienstkonto unter **Schlüssel → Schlüssel hinzufügen → JSON** einen Schlüssel erzeugen. Die heruntergeladene Datei gut aufbewahren und nicht weitergeben.
   4. In Google Drive den Galerie-Hauptordner für die **E-Mail-Adresse des Dienstkontos** freigeben (Rolle „Betrachter“).
   5. In WordPress **Einstellungen → Kompanie-App → Galerie** den Inhalt der JSON-Datei einfügen, den Link zum Galerie-Ordner eintragen, speichern und **„Jetzt synchronisieren“** klicken. Beim ersten Mal lädt die Seite selbstständig weiter, bis alle Bilder aufbereitet sind.
   6. Auf der Galerie-Seite der Webseite den bisherigen Block durch einen Shortcode-Block mit `[mkl_galerie]` ersetzen. Danach kann „Integrate Google Drive“ deaktiviert werden.

   **Ordnerstruktur:** Galerie-Ordner → Jahr (z. B. `2026`) → Album (z. B. `Schützenfest`) → Bilder.
   - Bilder im Unterordner **`intern`** eines Albums sehen nur angemeldete Mitglieder. Für sie werden diese Bilder ins Album eingemischt.
   - Neue Bilder übernimmt die App stündlich automatisch.
   - Gelöschte oder verschobene Bilder verschwinden beim nächsten Abgleich.
   - Videos werden nicht angezeigt.

   **Warum es schneller ist:** Jedes Bild wird einmalig in zwei Größen (400 px / 1600 px) auf dem eigenen Server abgelegt.
   - Öffentliche Bilder liefert der Webserver direkt aus.
   - Interne Bilder gibt es nur über `/app/foto/…` nach Prüfung der Anmeldung.
9. Die **Datenschutzerklärung** ergänzen: Die App verarbeitet Punkte und Strafen der Mitglieder; die Galerie legt Kopien der Bilder aus Google Drive auf dem eigenen Server ab. Die Daten liegen in der WordPress-Datenbank bzw. im Upload-Verzeichnis auf dem eigenen Server.

Danach ist die App unter `https://marinekompanie-lank.de/app/` erreichbar.

> Hinweis: Wird die Rolle eines Benutzers über die **Sammelaktion** in der WordPress-Benutzerliste geändert, entfernt WordPress dabei auch die Zusatzrollen „Mitglied“ und „Spieß“. Dann die Rolle in der App oder im Profil wieder setzen.

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

# Galerie mit simuliertem Google Drive (setzt die Beispiel-Galerie der Test-Instanz zurück)
php .wp-test/wp-cli.phar --allow-root --path=.wp-test/wp eval-file plugin/tests/gallery-test.php

# Ende-zu-Ende im Browser (Server muss laufen)
cd app && npx playwright test
```

GitHub Actions führt bei jedem Push und jedem Pull Request alles aus: Syntaxprüfung, Build, REST-Tests und E2E-Tests. Danach stellt der Workflow das Plugin-ZIP als Artefakt bereit. Ein Merge auf `main` veröffentlicht zusätzlich ein Release.

## Ideen für später

- Push-Benachrichtigung bei neuer Strafe oder neuem Termin (iOS ab 16.4, nur bei installierter App)
- Zu- und Absagen für Termine
- Strafenkatalog analog zum Punktekatalog
