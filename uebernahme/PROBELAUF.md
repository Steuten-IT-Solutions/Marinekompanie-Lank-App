# Checkliste: Probelauf der Übernahme auf einer Kopie

Gehört zu Schritt 1 im [README](README.md). Ziel: die Übernahme einmal vollständig auf einer Kopie der Website durchspielen, bevor sie auf der echten Website läuft. Auf der echten Website ändert der Probelauf nichts.

Abhaken, was erledigt ist, und Auffälligkeiten unten im Protokoll notieren.

## 1. Kopie anlegen

- [ ] Kopie auf einer eigenen Adresse anlegen, z. B. `test.example.de` oder lokal. Dazu gehören:
  - Datenbank-Dump
  - `wp-content/` mit `uploads/`
  - WordPress-Dateien
- [ ] Adressen in der Datenbank umschreiben, z. B. `wp search-replace 'https://www.example.de' 'https://test.example.de'`.
- [ ] Kopie vor Fremden schützen, z. B. Passwortschutz des Hosters, und unter *Einstellungen → Lesen* Suchmaschinen ausschließen.
- [ ] **Verbindungen nach außen sperren, bevor sich jemand anmeldet** (siehe unten). In `wp-config.php` der Kopie:
  ```php
  define('WP_HTTP_BLOCK_EXTERNAL', true);
  // Nur was der Probelauf braucht: Google Drive/Kalender (API, Anmeldung) und die iCal-Adresse des Kalenders.
  define('WP_ACCESSIBLE_HOSTS', 'www.googleapis.com,oauth2.googleapis.com,calendar.google.com');
  ```
  - Die iCal-Adresse (`mkl_ics_url`) liegt auf einem anderen Host? Dann diesen ergänzen, ebenso den Host der News-Quelle, falls sie extern ist.
  - **Nicht** `*.googleapis.com` eintragen: Darunter fällt auch der Push-Dienst von Google (`fcm.googleapis.com`).
- [ ] E-Mails der Kopie abschalten, z. B. mit einer Datei `wp-content/mu-plugins/keine-mails.php`:
  ```php
  <?php add_filter('pre_wp_mail', '__return_false');
  ```

> **Warum sperren?**
> - Die Kopie enthält die Push-Abos der Mitglieder und denselben Push-Schlüssel.
> - Eine Push-Nachricht von der Kopie, etwa eine Erinnerung von Lank oder eine Test-Benachrichtigung, käme bei den echten Mitgliedern an und sähe aus wie von der echten Website.
> - Beide Plugins schicken Push-Nachrichten über die HTTP-Funktionen von WordPress. Die Sperre greift deshalb für beide.

## 2. Ausgangslage in Lank festhalten

Auf der Kopie, noch mit `mk-lank-app`:

- [ ] Anzahl der Konten je Rolle: Spieß, Mitglied, Anwärter, Jungmariner-Haken.
- [ ] Bei 3–5 Mitgliedern notieren: Punkte, offene Strafen, Dienstgrad, Titel. Darunter mindestens ein Jungmariner und ein Spieß.
- [ ] Anzahl der Dokumente, Alben und Fotos sowie der Anwesenheitslisten.
- [ ] **Kalender:** Unter *Einstellungen → Kompanie-App* darf die Quelle nicht „Nicht konfiguriert“ sein (Google-Zugang, eigener API-Key oder iCal-Adresse). Bei Google-Zugang oder API-Key **muss das Feld „Google-Kalender-IDs“ ausgefüllt sein**; ohne eigene IDs hätte die Kompanie-App nach der Übernahme keine Termine (bei iCal genügt die iCal-Adresse).
- [ ] Ein Dokument, das nur Spieße sehen, und ein internes Album merken.

Den Vergleich aller Mitglieder übernimmt der Bericht (Schritt 4). Diese Stichproben prüfen zusätzlich die Anzeige in der App.

## 3. Übernahme durchführen

Wie im README, Schritte 2–5:

- [ ] Wartungsmodus in der alten App einschalten.
- [ ] `mk-lank-app` deaktivieren, nicht löschen.
- [ ] Kompanie-App installieren und aktivieren.
- [ ] `mkl-uebernahme` hochladen und aktivieren.
- [ ] *Werkzeuge → Übernahme Marinekompanie → Probelauf.* Bericht lesen. Konflikte oder unerwartet viele übersprungene Einträge? Dann hier abbrechen und klären.
- [ ] **Jetzt übernehmen.** Dauer notieren, sie zeigt, wie lange die echte Umstellung ungefähr dauert.

## 4. Bericht prüfen

Im Abschnitt **Prüfung** muss alles grün sein:

- [ ] Je Bereich: Anzahl in der Kompanie-App = Lank minus übersprungen.
- [ ] Punkte, offene Strafen und Dienstgrad je Mitglied: „bei allen gleich“.
- [ ] Rollen in der App: alle Konten mit passender Rolle.
- [ ] Push-Schlüssel: derselbe öffentliche Schlüssel wie bisher.
- [ ] Übersprungene Einträge durchsehen. Erwartet sind nur Konten, die es nicht mehr gibt, Dokumente ohne Datei und Einstellungen, die schon gesetzt waren.

## 5. In der App prüfen

Wartungsmodus der Kompanie-App beenden. Dann mit verschiedenen Konten anmelden. Passwörter lassen sich auf der Kopie zurücksetzen, E-Mails gehen ja nicht raus.

- [ ] **Admin:** Zustand/Statusseite ohne Fehler; Bereiche wie erwartet eingeschaltet.
- [ ] **Spieß:**
  - Punkte buchen, Strafe vergeben, Anwesenheitsliste öffnen
  - Dienstgrade und Titel pflegen
  - das Spieß-Dokument öffnen; die Rolle „Spieß“ hat dafür zusätzlich „Dokumente verwalten“ (siehe README, Grenzen)
- [ ] **Mitglied:** Werte in *Meins* stimmen mit den Stichproben aus Schritt 2 überein. Das Spieß-Dokument ist nicht sichtbar.
- [ ] **Jungmariner:**
  - Rolle „Jungmariner“
  - alte Punkte zählen halb wie bisher
  - eine neue Buchung zählt halb
- [ ] **Anwärter:** sieht den internen Kalender, aber keine Mitgliederbereiche.
- [ ] **Galerie:** öffentliches und internes Album, Bilder laden; das interne Album ist nur angemeldet sichtbar.
- [ ] **Dokumente:** PDF öffnen, andere Datei herunterladen.
- [ ] **Kalender und News:** Termine erscheinen, News laden.
- [ ] **Website:** Seiten mit `[mkl_termine]` und `[mkl_galerie]` zeigen Termine bzw. Galerie wie vorher.
- [ ] **Alte Adressen:** `/app/` öffnet die neue App, `/app/dokument/<id>` und `/app/foto/<id>/thumb` liefern die Dateien. `/wp-json/mk-lank/v1/…` antwortet, z. B. ein angemeldeter Aufruf von `/wp-json/mk-lank/v1/me`.
- [ ] **Handy:** Die App lässt sich auf dem Handy öffnen und zum Startbildschirm hinzufügen.

## 6. Push testen (nur mit eigenem Gerät)

- [ ] Auf der Kopie alle übernommenen Abos löschen, damit nichts an echte Mitglieder gehen kann:
  ```sql
  DELETE FROM wp_kompanie_push_subscriptions;
  ```
  Steht in `wp-config.php` ein anderes Tabellen-Präfix als `wp_`, dieses verwenden.
- [ ] Erst danach die Push-Dienste vorübergehend freigeben: `WP_ACCESSIBLE_HOSTS` um `fcm.googleapis.com,web.push.apple.com,updates.push.services.mozilla.com` ergänzen.
- [ ] Mit dem eigenen Handy auf der Kopie anmelden, Benachrichtigungen einschalten und **Testnachricht senden**.
- [ ] Eine echte Auslösung prüfen, z. B. Punkte an das eigene Konto buchen. Erscheint die Nachricht? Bei Dienstgraden: Wird das Abzeichen gezeigt?
- [ ] Danach die Push-Dienste wieder aus `WP_ACCESSIBLE_HOSTS` entfernen.

## 7. Rückfall üben

- [ ] Kompanie-App und Übernahme deaktivieren, `mk-lank-app` wieder aktivieren.
- [ ] Die alte App läuft mit den Daten von vorher. Stichproben aus Schritt 2 prüfen.
- [ ] Wieder umschalten: `mk-lank-app` deaktivieren, Kompanie-App und Übernahme aktivieren, **Probelauf**. Er darf nur Neues melden und keine Konflikte.

## Was die Kopie nicht prüfen kann

Diese Punkte gleich nach der echten Umstellung mit dem eigenen Gerät prüfen:

- **Installierte App auf iPhone und Android:** Übernimmt sie den neuen Stand ohne Neuinstallation? Das geht nur auf der echten Adresse, weil App und Service Worker an die Adresse gebunden sind.
- **Bestehende Push-Abos:** Kommen Nachrichten an Geräte an, die schon vor der Umstellung zugestimmt hatten? Der Bericht prüft nur, dass der Schlüssel gleich ist.
- **Server des Hosters:** Komprimierung und Cache-Header der App-Dateien, z. B. in den Entwicklerwerkzeugen des Browsers: `content-encoding` und `cache-control` für Dateien unter `app/assets/`.
- **Google-Verbindung:** wird weiter genutzt. „Mit Google verbinden“ ist noch ungetestet (Template #40).

## Protokoll

| Datum | Schritt | Beobachtung | erledigt |
|---|---|---|---|
| | | | |
