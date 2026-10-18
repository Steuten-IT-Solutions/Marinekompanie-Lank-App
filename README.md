# Marinekompanie Lank – App

App der Marinekompanie 1973 Lank mit diesen Bereichen:

- **Termine:**
  - Gäste sehen die öffentliche Terminliste, die Admins in der App pflegen. Dieselbe Liste erscheint per Shortcode auf der Startseite.
  - Anwärter und Mitglieder sehen den Google Kalender mit allen Terminen.
- **News:** die Blogbeiträge der Webseite.
- **Galerie:** Bilder aus Google Drive. Alle sehen die öffentlichen Bilder; Mitglieder sehen zusätzlich die Bilder aus den Ordnern „intern“.
- **Meins:** eigene Punkte (mit Punktekatalog), eigener Dienstgrad mit Liste aller Dienstgrade und Verlauf, eigene Titel, Strafen (Geldbeträge, offen/bezahlt), eigene Anwesenheit.
- **Dokumente:** Satzung, Protokolle, Formulare usw. als PDF, Office-Datei oder Bild, nach Kategorien geordnet. Je Dokument sichtbar für alle Mitglieder oder nur für Spieße; einmal geöffnet auch offline lesbar.
- **Spieß:** Punkte und Strafen vergeben, Anwesenheit je Termin erfassen, Anwärter zu Mitgliedern befördern, Mitglieder befördern und degradieren, Titel (z. B. Kapitän, Ehrenmitglied) vergeben. Unter **Einrichtung**: Punktekatalog, Dienstgrade und Titel pflegen; für Admins zusätzlich „Mitglieder verwalten“ (anlegen, Rollen, Namen, Passwörter).

### Rollen

| Rolle | Darf |
|---|---|
| **Admin** (WordPress-Administrator) | alles: öffentliche Termine und Dokumente pflegen, Mitglieder anlegen, Rollen vergeben, Konten bearbeiten und Passwörter zurücksetzen, dazu alles vom Spieß |
| **Spieß** | Punkte und Strafen vergeben und verwalten, Anwesenheit erfassen, Punktekatalog, Dienstgrade und Titel pflegen, befördern und degradieren, Titel vergeben, Anwärter zum Mitglied befördern |
| **Mitglied** | die eigenen Punkte, den eigenen Dienstgrad und die eigenen Strafen sehen, den Google Kalender mit allen Terminen, die Dokumente für Mitglieder |
| **Anwärter** | wie „Ohne Rolle“, sieht aber den Google Kalender mit allen Terminen statt der öffentlichen Terminliste |

**Anwärter** haben keinen Bereich „Meins“, keine Dokumente für Mitglieder, keine internen Galeriebilder und bekommen keine Punkte, Strafen, Dienstgrade oder Titel. Im Spieß-Bereich stehen sie mit Abzeichen in der Mitgliederliste; in ihrer Detailansicht gibt es den Knopf **„Zum Mitglied befördern“** (Spieß und Admins). Neue Konten starten beim Anlegen als Anwärter, die Rolle lässt sich dort aber auch anders wählen.

**Jungmariner** erhalten laut Satzung nur die halbe Punktzahl. Der Spieß (oder ein Admin) setzt dafür beim Mitglied unter **Spieß → Mitglieder → Name** den Haken „Jungmariner“. Beim Anlegen eines neuen Mitglieds lässt sich der Haken auch gleich setzen. Dann gilt:

- Bei jeder Punktevergabe wird für Jungmariner automatisch nur die Hälfte gutgeschrieben, z. B. 5 → 2,5. Dafür sind halbe Punkte möglich.
- Abzüge (negative Punkte) zählen auch für Jungmariner voll.
- Der Anfangsbestand beim Anlegen wird nicht halbiert, weil er ein bereits erreichter Punktestand ist.
- Für Dienstgrade und die Beförderungsübersicht zählen die gutgeschriebenen (halbierten) Punkte.
- Ob ein Eintrag halbiert wurde, wird bei der Vergabe gespeichert und im Verlauf angezeigt („Jungmariner: halbe Punktzahl von +5“). Wird der Haken später entfernt, gelten nur künftige Vergaben wieder voll. Bereits vergebene Punkte bleiben unverändert.

**Gäste** sind Besucher **ohne Anmeldung** und haben keine Rolle. Sie sehen nur öffentliche Termine, News und öffentliche Galeriebilder.

Mitglieder sehen **ausschließlich ihre eigenen** Punkte, Dienstgrade und Strafen. Ein angemeldetes WordPress-Konto ohne App-Rolle (z. B. ein reines Webseiten-Konto) steht in der App als **„Ohne Rolle“** und sieht dasselbe wie ein Gast.

## Warum kein Apple Developer Account nötig ist

Die App ist eine **Progressive Web App (PWA)**. Sie wird über den Browser installiert und braucht deshalb weder App Store noch TestFlight noch einen kostenpflichtigen Developer Account. Updates sind sofort für alle verfügbar, sobald das Plugin aktualisiert ist.

| Gerät | Installation |
|---|---|
| iPhone/iPad | In **Safari** `https://marinekompanie-lank.de/app/` öffnen → **Teilen** → **Zum Home-Bildschirm** |
| Android | In **Chrome** die Adresse öffnen → Menü **⋮** → **App installieren** |

Die App hilft beim Installieren selbst, damit es auch ohne Smartphone-Erfahrung klappt:

- Oben in der App erscheint ein Hinweis **„Die App aufs Handy holen“**. Er verschwindet, sobald die App installiert ist oder jemand ihn wegklickt.
- **Android (Chrome, Edge, Samsung Internet):** Bietet der Browser die Installation an, reicht ein Tipp auf **„Installieren“**. Danach muss man nur noch bestätigen. Während der Installation und danach zeigt die App, wie es weitergeht: Das Wappen auf dem Startbildschirm antippen. Von selbst öffnen kann sich die App nach der Installation nicht, das lässt der Browser einer Webseite nicht zu.
- **iPhone/iPad:** Apple erlaubt Webseiten keinen Installationsknopf. Deshalb führt **„So geht's“** zu einer bebilderten Schritt-für-Schritt-Anleitung (`/app/#/installieren`). Sie zeigt auch die neuere Safari-Leiste mit „•••“ (iOS 26).
- Die Anleitung hat eine eigene Adresse zum Weitergeben: **`https://marinekompanie-lank.de/app/installieren/`**. Diesen Link kann man per WhatsApp, E-Mail oder Rundschreiben verschicken. Auf der Anleitung selbst gibt es dafür den Knopf **„Link teilen“**, außerdem erreicht man sie über **Konto → App installieren**.
- Wurde der Link in Facebook oder Instagram geöffnet, wo man nicht installieren kann, erscheint ein Hinweis mit einem Knopf zum Kopieren der Adresse.

Hinweis für das iPhone: Die installierte App hat ihren eigenen Speicher, getrennt von Safari. Man meldet sich also einmal direkt in der App an. „Angemeldet bleiben“ ist auf der Anmeldeseite der App schon angehakt. Damit bleibt man praktisch dauerhaft angemeldet: Die Anmeldung gilt 400 Tage (mehr erlauben Chrome und Edge für Cookies nicht) und verlängert sich automatisch, sobald man die App öffnet. Wer die App mindestens einmal im Jahr nutzt, muss sich also nie neu anmelden. Abmelden geht über **Konto → Abmelden**. Bei einem verlorenen Handy hilft eine Passwortänderung (meldet alle anderen Geräte ab) oder im WP-Profil des Mitglieds „Überall sonst abmelden“.

## Aufbau

Alles steckt in einem **WordPress-Plugin** (`plugin/mk-lank-app`). Es läuft auf dem vorhandenen Webspace der Webseite und braucht nur PHP und MySQL, kein Node.js auf dem Server.

```
app/                       React + TypeScript (Vite) – Quellcode der PWA
  src/views/               Termine, News, Galerie, Meins (Punkte/Strafen/Anwesenheit), Spieß-Bereich, Konto
  public/sw.js             Service Worker (Offline-Anzeige der zuletzt geladenen Daten)
  e2e/                     Playwright-Tests
plugin/mk-lank-app/        WordPress-Plugin
  includes/class-mkl-db.php        Tabellen + Datenzugriff (Beträge in Cent)
  includes/class-mkl-rest.php      REST-API /wp-json/mk-lank/v1/ inkl. Rechteprüfung
  includes/class-mkl-roles.php     Rollen Anwärter/Mitglied/Spieß/Admin, Migration
  includes/class-mkl-ranks.php     Dienstgrade, zugewiesene Dienstgrade, Beförderungsregeln, Verlauf
  includes/class-mkl-titles.php    Titel (Kapitän, Ehrenkapitän, …) und ihre Zuweisung
  includes/class-mkl-documents.php Dokumente und Kategorien: Prüfung, geschützte Ablage, Auslieferung
  includes/class-mkl-events.php    Öffentliche Terminliste
  includes/class-mkl-attendance.php Anwesenheitslisten je Termin
  includes/class-mkl-shortcodes.php Shortcodes [mkl_termine] und [mkl_galerie] für die Webseite
  includes/class-mkl-drive.php     Google Drive über „Mit Google verbinden“ (OAuth) oder Dienstkonto, ohne Bibliothek
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
  - `mkl_calendar`: interner Kalender (Anwärter und Mitglied)
  - `mkl_member`: Mitglied
  - `mkl_manage`: Spieß
  - `mkl_manage_events`: Admin (öffentliche Termine)
  - `mkl_manage_documents`: Admin (Dokumente)
  - Benutzer anlegen und Rollen ändern ist an die WordPress-Rechte `create_users` bzw. `promote_users` gebunden. Ausnahme: Anwärter zum Mitglied befördern (`POST /members/{id}/promote`) darf auch der Spieß (`mkl_manage`).
- **Nachvollziehbarkeit:** Zu jedem Eintrag wird gespeichert, wer ihn angelegt hat. Stornierte Einträge werden nicht gelöscht, sondern nur ausgeblendet.

## Installation auf der Webseite

1. **Backup** der Webseite anlegen.
2. Das Plugin-ZIP besorgen: Lade `mk-lank-app.zip` aus dem neuesten GitHub-Release herunter (siehe [Updates](#updates)). Alternativ baust du es selbst mit `scripts/build-plugin.sh`; das Ergebnis liegt dann in `dist/mk-lank-app.zip`.
3. In WordPress **Plugins → Installieren → Plugin hochladen**, das ZIP auswählen und aktivieren.
4. **Einstellungen → Kompanie-App** öffnen und **„Kalender jetzt testen“** klicken.
   - Sind die Felder leer, nutzt die App automatisch die Kalender und Zugangsdaten aus dem Plugin **„Private Google Calendars“**, das die Webseite schon verwendet.
   - Alternativ trägst du eine Kalender-ID mit API-Key ein, oder eine iCal-Adresse.
5. **Rollen festlegen:**
   - In der App unter **Spieß → Einrichtung → Mitglieder verwalten → Konto antippen → „Rolle in der App“** (Ohne Rolle / Anwärter / Mitglied / Spieß). Die Liste enthält alle Konten, auch die ohne Rolle.
   - Alternativ im WordPress-Profil unter „Rolle in der App“.
   - Die bisherige WordPress-Rolle, z. B. Redakteur, bleibt dabei erhalten.
   - Beim Update auf 0.2.0 werden alle bisherigen Abonnenten automatisch zu Mitgliedern. Wer keins ist, bekommt in der App „Ohne Rolle“.
6. **Öffentliche Termine und Startseite:**
   - Als Admin in der App unter **Termine → „Öffentliche Termine verwalten“** die Termine anlegen, mit optionaler Uhrzeit, Ort, Beschreibung und Link.
   - Dann auf der Startseite im Block-Editor die bisherige Termin-Timeline (Spectra-Block „Content Timeline“) löschen und an ihrer Stelle einen **Shortcode-Block** mit `[mkl_termine]` einfügen.
   - Mit `[mkl_termine anzahl="5"]` begrenzt du die Anzahl.
7. Jedes Mitglied braucht ein WordPress-Benutzerkonto mit der Rolle „Mitglied“. Administratoren können Mitglieder direkt in der App anlegen: **Spieß → Einrichtung → Mitglieder verwalten → „+ Mitglied anlegen“**. Dort wählt man auch die Rolle in der App (vorausgewählt ist Anwärter; Anfangsbestand, Dienstgrad und Jungmariner gibt es nur bei Mitglied und Spieß). Die App erzeugt ein zufälliges Startpasswort, zeigt es **nur einmal** an und verschickt keine E-Mail. Gebt die Zugangsdaten persönlich weiter. Das Mitglied kann das Passwort danach selbst in der App ändern: oben rechts **„Konto“** → **„Passwort ändern“**. Dafür ist das aktuelle Passwort nötig, das neue muss mindestens 8 Zeichen haben. Auf allen anderen Geräten wird das Mitglied danach abgemeldet. Spieße ohne Admin-Rechte sehen diese Funktion nicht.

   Unter **Mitglieder verwalten** können Admins außerdem Name, Benutzername und E-Mail eines Kontos ändern und ein **neues Startpasswort** erzeugen, wenn jemand sein Passwort vergessen hat. Bei einem neuen Passwort oder Benutzernamen wird das Mitglied auf allen Geräten abgemeldet. Den eigenen Benutzernamen und das eigene Passwort ändert man dort nicht (das eigene Passwort unter „Konto“).
8. **Galerie einrichten** (ersetzt das Plugin „Integrate Google Drive“). Der Zugang läuft über **„Mit Google verbinden“**. Einen Dienstkontoschlüssel braucht es nicht; Google verbietet das Erstellen solcher Schlüssel in neueren Organisationen ohnehin standardmäßig.
   1. In der [Google Cloud Console](https://console.cloud.google.com/) im vorhandenen Projekt die **Google Drive API** aktivieren: [Direktlink](https://console.cloud.google.com/apis/library/drive.googleapis.com).
   2. Unter [Google Auth Platform](https://console.cloud.google.com/auth/overview) die App einrichten, falls noch nicht geschehen: App-Name (z. B. „Kompanie-App“) und Support-E-Mail eintragen.
      - Bei **Zielgruppe** möglichst **„Intern“** wählen. Das geht, wenn das Projekt zu einer Google-Organisation gehört. Dann ist keine Überprüfung durch Google nötig und die Verbindung läuft nicht ab.
      - Geht nur **„Extern“**: die App unter **Zielgruppe → App veröffentlichen** auf „In Produktion“ stellen. Im Status „Test“ läuft die Verbindung nach **7 Tagen** ab. Bei der Anmeldung zeigt Google dann einen Hinweis „Google hat diese App nicht überprüft“; über **Erweitert → Weiter zu …** geht es trotzdem.
   3. Unter [Clients](https://console.developers.google.com/auth/clients) → **Client erstellen** → Typ **Webanwendung**. Bei **Autorisierte Weiterleitungs-URIs** die Adresse eintragen, die WordPress unter **Einstellungen → Kompanie-App → Galerie** anzeigt, also `https://marinekompanie-lank.de/wp-admin/admin-post.php`. **Client-ID** und **Clientschlüssel** sofort kopieren: Google zeigt den Clientschlüssel nur dieses eine Mal vollständig an. Bis eine neue Weiterleitungs-URI greift, kann es laut Google 5 Minuten bis einige Stunden dauern; meldet Google `redirect_uri_mismatch`, also kurz warten.
   4. In WordPress unter **Einstellungen → Kompanie-App → Galerie** beides eintragen und **„Speichern und mit Google verbinden“** klicken. Bei Google mit dem Konto anmelden, dem der Galerie-Ordner gehört oder für das er freigegeben ist, und den Lesezugriff auf Google Drive erlauben.
   5. Danach den Link zum Galerie-Ordner eintragen, speichern und **„Jetzt synchronisieren“** klicken. Beim ersten Mal lädt die Seite selbstständig weiter, bis alle Bilder aufbereitet sind.
   6. Auf der Galerie-Seite der Webseite den bisherigen Block durch einen Shortcode-Block mit `[mkl_galerie]` ersetzen. Danach kann „Integrate Google Drive“ deaktiviert werden.

   Gespeichert wird nur ein Refresh-Token, und zwar ausschließlich auf dem eigenen Server. Die App fordert nur Lesezugriff an (`drive.readonly`), kann damit aber alle Dateien sehen, die das verbundene Google-Konto sehen kann. Wer das enger fassen will, nimmt ein eigenes Google-Konto nur für die Galerie. Mit **„Verbindung trennen“** widerruft das Plugin den Zugriff bei Google. Läuft die Verbindung ab oder wird sie widerrufen, zeigt der Status eine Meldung; dann einfach neu verbinden.

   *Alternative:* Wer Dienstkontoschlüssel erstellen darf, kann unter „Alternative: Dienstkonto mit JSON-Schlüssel“ auch den Schlüssel eines Dienstkontos einfügen und den Galerie-Ordner für dessen E-Mail-Adresse freigeben (Rolle „Betrachter“). Ist zusätzlich eine Google-Verbindung eingerichtet, hat diese Vorrang.

   **Ordnerstruktur:** Galerie-Ordner → Jahr (z. B. `2026`) → Album (z. B. `Schützenfest`) → Bilder.
   - Bilder im Unterordner **`intern`** eines Albums sehen nur angemeldete Mitglieder. Für sie werden diese Bilder ins Album eingemischt.
   - Neue Bilder übernimmt die App alle 15 Minuten automatisch. Das läuft über WP-Cron und startet daher nur, wenn jemand die Webseite aufruft. Sofort geht es mit „Jetzt synchronisieren“.
   - Gelöschte oder verschobene Bilder verschwinden beim nächsten Abgleich.
   - Videos werden nicht angezeigt.

   **Warum es schneller ist:** Jedes Bild wird einmalig in zwei Größen (400 px / 1600 px) auf dem eigenen Server abgelegt.
   - Öffentliche Bilder liefert der Webserver direkt aus.
   - Interne Bilder gibt es nur über `/app/foto/…` nach Prüfung der Anmeldung.
9. **Dienstgrade anlegen und bestehende Mitglieder übernehmen:**
   1. In der App unter **Spieß → Einrichtung → Dienstgrade** alle Dienstgrade mit ihrer Mindestpunktzahl eintragen. Das dürfen Spieße und Admins.
   2. Erst danach die Mitglieder übernehmen. Wer neu angelegt wird, bekommt unter **„+ Mitglied anlegen“ → „Übernahme“** gleich den heutigen Punktestand und seinen Dienstgrad. Der Dienstgrad muss mit diesen Punkten erreichbar sein. Die Punkte erscheinen als Buchung „Anfangsbestand bei Übernahme“.
   3. Mitglieder, die schon ein Konto haben, bekommen ihre Punkte über **„Punkte +“**, z. B. mit dem Grund „Anfangsbestand bei Übernahme“. Danach befördert sie ein Spieß.

   **Regeln:**
   - Den Dienstgrad legen die Spieße fest. Punkte allein ändern den Dienstgrad nie.
   - Befördern geht nur, wenn die Punkte die Schwelle des Zieldienstgrads erreichen. Mehrere Dienstgrade auf einmal überspringen ist erlaubt.
   - Degradieren geht nur, wenn die Punkte unter der Schwelle des aktuellen Dienstgrads liegen.
   - Die Regeln gelten auch für Admins.
   - **Spieß → Dienstgrade** zeigt, wer fällig ist (mit Datum, seit wann) und wem wie viele Punkte fehlen.
   - Mitglieder sehen unter **Meins** ihren eigenen Dienstgrad, die fehlenden Punkte bis zum nächsten Dienstgrad, die Dienstgrade und ihren Verlauf, aber nicht die Dienstgrade anderer.

   **Anwesenheit:** Unter **Spieß → Anwesenheit** wählt der Spieß einen Termin aus dem internen Kalender der letzten 90 Tage oder legt einen **freien Termin** mit Titel und Datum an. Bei laufenden Terminen führt auch der Knopf **„Anwesenheit“** in der Terminansicht dorthin.
   - In der Liste stehen alle Mitglieder und Anwärter. Unter den Zählern steht, wie viele der Anwesenden Mitglieder, Jungmariner und Anwärter sind (Jungmariner zählen dabei nicht zusätzlich als Mitglieder). Anwärter bekommen keine Punkte oder Strafen und werden bei den Vorschlägen ausgelassen. Je Mitglied gibt es drei Häkchen (anwesend, entschuldigt, fehlt), von denen immer genau eins gesetzt ist; anfangs ist es „fehlt“. „Alle anwesend“ setzt alle (bzw. alle Suchtreffer) auf anwesend.
   - **„Punkte für N Anwesende“** öffnet das Punkte-Formular: vorausgewählt sind die Anwesenden, die für diesen Termin noch keine Punkte haben, dazu das Termindatum und – wenn der Titel passt – eine Vorlage aus dem Punktekatalog. Der Spieß kann alles ändern und bestätigt. Wer für den Termin schon Punkte hat, wird beim Buchen übersprungen; nach einer Stornierung kann man neu buchen.
   - **„Strafe für N Unentschuldigte“** öffnet das Strafen-Formular mit allen, die „fehlt“ haben, und dem Grund „Unentschuldigt gefehlt: <Termin>“. Betrag und Auswahl bestätigt der Spieß; automatisch gebucht wird nichts.
   - Titel und Datum werden mit der Liste gespeichert. Sie bleibt also erhalten, auch wenn der Termin später aus dem Kalender verschwindet. Löscht man eine Liste, bleiben bereits gebuchte Punkte bestehen.
   - Mitglieder sehen unter **Meins → Anwesenheit** nur ihre eigene Anwesenheit.

   **Titel:** Kapitän, Ehrenkapitän, Ehrenspieß und Ehrenmitglied sind schon angelegt. Weitere Titel, Umbenennen und Löschen unter **Spieß → Einrichtung → Titel**. Vergeben werden Titel in den Mitgliedsdetails unter **„Titel ändern“**; ein Mitglied kann mehrere Titel haben. Titel hängen nicht von Punkten ab und werden zusätzlich zum Dienstgrad angezeigt: in der Mitgliederliste, in der Beförderungsübersicht und beim Mitglied selbst unter **Meins**. Das dürfen Spieße und Admins. Zugewiesene Titel lassen sich erst löschen, wenn sie niemand mehr hat.
10. **Dokumente hinterlegen:** Als Admin in der App unter **Dokumente → „Dokumente verwalten“**.
    - Zuerst bei Bedarf **Kategorien** anlegen, z. B. „Satzung & Ordnungen“, „Protokolle“, „Formulare“. Dokumente ohne Kategorie stehen unter „Weitere Dokumente“.
    - Dann Dokumente **hochladen**: Datei, Titel, Kategorie, Stand und **Sichtbarkeit** („Alle Mitglieder“ oder „Nur Spieße“; Admins sehen alles).
    - Erlaubt sind PDF, Word (`.doc`, `.docx`), Excel (`.xls`, `.xlsx`), PowerPoint (`.ppt`, `.pptx`), OpenDocument (`.odt`, `.ods`, `.odp`) sowie JPG- und PNG-Bilder, höchstens 20 MB. Der Server prüft Dateiendung und Dateiinhalt. PDFs und Bilder zeigt die App direkt an, Office-Dateien lassen sich herunterladen.
    - **„Neue Fassung“** ersetzt die Datei eines Dokuments; Titel, Kategorie und Sichtbarkeit bleiben. Ältere Fassungen werden nicht aufbewahrt.
    - Eine Satzung aus Version 0.4 wird beim Update automatisch als Dokument „Satzung“ übernommen.
    - Die Dateien liegen in `wp-content/uploads/mkl-private/` mit zufälligem Dateinamen und werden nur über `/app/dokument/<Nummer>` an berechtigte, angemeldete Mitglieder ausgeliefert.
    - Der direkte Abruf der Dateien ist per `.htaccess` gesperrt. **Das wirkt nur unter Apache.** Läuft die Webseite auf nginx, muss der Hoster den Zugriff auf `/wp-content/uploads/mkl-private/` sperren (z. B. `location ^~ /wp-content/uploads/mkl-private/ { deny all; }`). Prüfen lässt sich das, indem man die Adresse des Ordners im Browser aufruft: Es muss eine Fehlermeldung (403) kommen.
    - Ist eine Datei größer als das Upload-Limit des Servers (PHP `upload_max_filesize`/`post_max_size`), meldet die App das beim Hochladen.
    - **Offline:** Was ein Mitglied einmal geöffnet oder heruntergeladen hat, speichert die App auf dem Gerät; es ist danach auch ohne Netz lesbar. Vorab wird nichts geladen. Beim Abmelden wird alles wieder gelöscht.
11. Die **Datenschutzerklärung** ergänzen: Die App verarbeitet Punkte, Dienstgrade, Titel und Strafen der Mitglieder; die Galerie legt Kopien der Bilder aus Google Drive auf dem eigenen Server ab. Die Daten liegen in der WordPress-Datenbank bzw. im Upload-Verzeichnis auf dem eigenen Server.

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

Voraussetzungen: PHP ≥ 7.4 mit SQLite-Erweiterung, Node.js ≥ 22.13 (wegen pdf.js). Docker und MySQL sind nicht nötig.

```bash
# Frontend bauen (landet direkt in plugin/mk-lank-app/app-dist)
cd app && npm ci && npm run build && cd ..

# Lokales Test-WordPress (SQLite) mit Plugin, Testbenutzern und Beispielterminen
scripts/setup-test-wp.sh
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8888 -t .wp-test/wp .wp-test/router.php
```

- App: http://localhost:8888/app/. Benutzer `spiess`, `mariner1`, `mariner2`, `anwaerter`, Passwort `test1234`.
- WP-Admin: http://localhost:8888/wp-admin/ mit `admin` / `admin`.

Nach Änderungen am Frontend `npm run build` erneut ausführen und die Seite neu laden.

### Tests

```bash
# REST-API: Rechte, Validierung, Summen, Dienstgrade, Titel, Dokumente, ICS-Parser
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
