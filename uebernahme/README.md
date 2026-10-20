# Übernahme in die Kompanie-App

Kleines WordPress-Plugin **„Marinekompanie – Übernahme in die Kompanie-App“** (`mkl-uebernahme`). Es kopiert die Daten von `mk-lank-app` in die Kompanie-App (Plugin des [Kompanie-App-Templates](https://github.com/Steuten-IT-Solutions/Kompanie-App-Template)) und hält die bisherigen Adressen erreichbar. Geplant in Lank #49 und Template #25.

## Was es tut

**Kopieren, nicht verändern:**
- Die Tabellen `wp_mkl_*`, die Optionen `mkl_*` und die User-Meta von Lank bleiben unverändert.
- Deshalb lässt sich in den ersten Tagen auf das alte Plugin zurückschalten.
- Ein weiterer Lauf übernimmt nur, was neu ist. Was schon übernommen wurde, merkt sich die Option `mkl_uebernahme_state`.

| Lank | Kompanie-App |
|---|---|
| Punkte (`halved` → Faktor 0,5), Strafen, Punktekatalog, öffentliche Termine | gleiche Tabellen, **gleiche IDs** |
| Dienstgrade (Reihenfolge nach Punkteschwelle), Verlauf (`befoerderung`/`degradierung`/Ersteinstufung → `promotion`/`demotion`/`initial`), `mkl_rank_id` | `ranks`, `rank_history`, `member_ranks` |
| Titel, `mkl_title_id` | `titles`, `member_titles` |
| Anwesenheitslisten (`kalender`/`frei` → `calendar`/`free`) und Einträge (`anwesend`/`entschuldigt`/`fehlt` → `present`/`excused`/`absent`) | `attendance_lists`, `attendance_entries` |
| Dokumente und Kategorien, Dateien aus `uploads/mkl-private/` | `documents` (Sichtbarkeit `spiess` → `managers`), Dateien neu benannt in der geschützten Ablage |
| Alben und Bilder, Dateien aus `uploads/mkl-gallery*/` | `albums`, `photos`, Dateien in `uploads/kompanie-galerie*/` (fehlende Dateien werden aus Google Drive neu aufbereitet) |
| Push-Abos (Arten `strafen`/`punkte`/`dienstgrad`/`termine`/`erinnerung`/`news` → `penalties`/`points`/`ranks`/`events`/`reminder`/`news`), **VAPID-Schlüssel** | `push_subscriptions`, Einstellung `push_vapid`: kein Gerät muss neu zustimmen |
| Kalender (`mkl_ics_url`, `mkl_google_api_key`, `mkl_calendar_ids`, Zwischenspeicher), Galerie-Ordner, Google-Verbindung (OAuth bzw. Dienstkonto) | Einstellungen der Kompanie-App |
| Rollen | |
| – Spieß | eigene Rolle **„Spieß“** (Punkte, Strafen, Anwesenheit, Dienstgrade, Titel, Dokumente verwalten) |
| – Mitglied mit Jungmariner-Haken | eigene Rolle **„Jungmariner“** (Punktefaktor 0,5) |
| – Mitglied | Mitglied |
| – Anwärter | Anwärter |
| – Administratoren | bleiben Admins |

Alle Bereiche, die Lank hat, werden eingeschaltet. Neue Bereiche des Kerns (z. B. Geburtstage, Umfragen, Schwarzes Brett, Inventar) bleiben aus, bis ein Admin sie einschaltet.

**Übersprungen und im Bericht genannt:**
- Einträge von Konten, die es nicht mehr gibt
- Dokumente ohne Datei
- Einstellungen, die in der Kompanie-App schon anders gesetzt sind

**Bisherige Adressen:**
- REST-Namensraum `/wp-json/mk-lank/v1/…` als Alias der Kompanie-App, für noch geladene alte App-Fassungen und Push-Abmeldungen.
- Shortcodes `[mkl_termine]` und `[mkl_galerie]`.
- `/app/`, `/app/dokument/<id>` und `/app/foto/<id>/<größe>` bedient die Kompanie-App selbst, weil die IDs gleich bleiben.
- Anmeldungen bleiben erhalten, weil beide die Sitzung von WordPress nutzen.

## Ablauf der Umstellung

1. **Probe auf einer Kopie** (ausführliche Checkliste: [PROBELAUF.md](PROBELAUF.md)): Datenbank-Dump und `wp-content/uploads/` in eine Testumgebung, dort die Schritte 3–6 durchspielen. Prüfen:
   - Summen je Mitglied
   - Dokumente öffnen, Galerie
   - Push an ein Testgerät
   - installierte App auf iPhone und Android: übernimmt sie den neuen Stand ohne Neuinstallation?
2. **Wartungsmodus** in der alten App einschalten (Lank #56), damit während der Übernahme niemand etwas ändert.
3. **Sicherung** von Datenbank und `wp-content/uploads/`.
4. **Plugins:**
   - `mk-lank-app` **deaktivieren, nicht löschen**.
   - Kompanie-App installieren und aktivieren (ZIP aus dem Kompanie-Repo).
   - `mkl-uebernahme.zip` hochladen und aktivieren (Artefakt der CI bzw. `cd uebernahme && zip -r mkl-uebernahme.zip mkl-uebernahme`).
5. **Werkzeuge → Übernahme Marinekompanie:** erst **Probelauf**, Bericht lesen, dann **Jetzt übernehmen**. Alternativ per WP-CLI:
   ```
   wp mkl-uebernahme probelauf
   wp mkl-uebernahme uebernehmen --yes
   ```
   Der Bericht zeigt je Bereich die Anzahlen und am Ende die **Prüfung**:
   - Punkte, offene Strafen und Dienstgrad je Mitglied, in Lank und in der Kompanie-App
   - Rollen
   - Push-Schlüssel
6. **Kontrolle in der App:**
   - Statusseite prüfen.
   - Wartungsmodus der Kompanie-App beenden.
   - Statusmeldungen (Telemetrie) einschalten.
   - Die ersten Tage im Cockpit beobachten.

## Rückfall

1. Kompanie-App und Übernahme deaktivieren, `mk-lank-app` wieder aktivieren. Lank läuft sofort mit seinen unveränderten Daten weiter.
2. Was in der Zwischenzeit in der Kompanie-App eingetragen wurde, fehlt dort und muss nachgetragen werden.

Ein Rückfall ist deshalb nur in den ersten Tagen sinnvoll.

## Aufräumen (nach etwa 4 Wochen)

Nach einer weiteren Sicherung:

1. `mk-lank-app` und `mkl-uebernahme` löschen.
2. Die Tabellen `wp_mkl_*`, die Optionen `mkl_*` (auch `mkl_uebernahme_state`) und die User-Meta `mkl_jungmariner`, `mkl_rank_id` und `mkl_title_id` entfernen.
3. Die Ordner `uploads/mkl-private`, `uploads/mkl-gallery` und `uploads/mkl-gallery-intern` entfernen.
4. Die WP-Rollen `mkl_spiess`, `mkl_mitglied` und `mkl_anwaerter` entfernen.

## Grenzen

- **Google-Verbindung (OAuth):** wird weiter genutzt, bis sie abläuft oder getrennt wird. Neu verbinden lässt sich in der Kompanie-App erst nach dem Galerie-Test (Template #40); bis dahin hilft ein Dienstkonto.
- **Spieß:** bekommt in der Kompanie-App die Rechte, die er in Lank hatte, und zusätzlich **„Dokumente verwalten“**.
  - In Lank sah der Spieß die Dokumente mit Sichtbarkeit „Spieß“, pflegen durften sie nur Admins. Im Kern sieht eingeschränkte Dokumente nur, wer Dokumente verwalten darf. Ohne dieses Recht sähe der Spieß seine Dokumente nicht mehr; mit ihm darf er sie auch hochladen, ändern und löschen.
  - Mitglieder anlegen und Rollen vergeben bleiben Admins vorbehalten, wie bisher.
  - **Anwärter aufnehmen** durfte der Spieß in Lank. In der Kompanie-App gehört dieser Schritt zum Recht „Mitglieder anlegen“. Soll der Spieß das weiter dürfen, gibt ein Admin der Rolle „Spieß“ dieses Recht; damit darf er dann aber auch Konten anlegen und Passwörter von Mitgliedern zurücksetzen.
- **Push-Abos:** Lank hatte keine eigene Art für Titel. Wer die Art „Dienstgrad“ hatte, bekommt in der Kompanie-App keine Titel-Benachrichtigungen, bis er sie einschaltet.

## Tests

```
KOMPANIE_TEMPLATE=/pfad/zum/Kompanie-App-Template uebernahme/tests/run.sh
```

Die Tests:
1. legen mit aktivem `mk-lank-app` synthetische Daten an (`tests/seed.php`);
2. prüfen Probelauf, Konflikt, Übernahme, Wiederholung und die bisherigen Adressen (`tests/run-tests.php`);
3. prüfen den Rückfall: die Lank-Tabellen sind unverändert (`tests/rollback-test.php`).

Die CI führt sie gegen den `main`-Stand des Templates aus.
