# Heise I/O Article TTS

Eigenständiges WordPress-Plugin, das Artikel-Inhalt (Titel, Body, auf Wunsch das Excerpt) über Heise I/O in eine MP3-Datei umwandelt und einen Audio-Player im Frontend ausspielt. Die Zugangsdaten des Sprachanbieters liegen nicht in dieser Installation, sondern bei Heise I/O; eingetragen werden hier nur die Adresse der Instanz und ein Zugangstoken.

## Features

- Adresse und Zugangstoken über eine eigene Settings-Seite (**Einstellungen → Heise I/O Article TTS**)
- Vertonung pro Artikel über einen Button in der Sidebar des Post-Editors; sie läuft im Hintergrund, die Audio-Box zeigt den Fortschritt
- Stimmen aus dem Katalog der Instanz, nach Sprache gruppiert, pro Post überschreibbar
- Hash-basiertes Caching — gleicher Text wird nicht erneut abgerechnet
- Frontend-Player über `the_content` Filter (Position konfigurierbar: oben/unten/beides/manuell)
- Shortcode `[article_audio]` für manuelle Platzierung im Block-Editor
- Funktioniert mit jedem Post-Type (Standard: `post`, weitere in den Settings aktivierbar)
- Sichtbarkeit des Players pro Rolle steuerbar (inkl. Gäste); leer = alle Besucher
- Nutzt ausschließlich WordPress-Core-APIs — keine Theme-Abhängigkeit
- Nimmt Vertonungen entgegen, die in Heise I/O erzeugt und an einen Artikel geschickt wurden
  (siehe unten): ohne Einrichtung, ohne offenen Eingang

## Zustellung aus Heise I/O

Neben der Vertonung, die hier ausgelöst wird, gibt es die Gegenrichtung: Jemand erzeugt in
Heise I/O eine Vertonung, fügt dort die Adresse eines Artikels ein, und die Datei hängt kurz
darauf an diesem Artikel.

**Einzurichten ist dafür nichts.** Es reicht die Adresse und der Token, die ohnehin in den
Einstellungen stehen. Der Fünf-Minuten-Cron, der laufende Vertonungen verfolgt, fragt danach
noch, ob etwas bereitliegt.

Drei Dinge, die im Betrieb auffallen können:

- **Bis zu fünf Minuten Verzögerung.** Heise I/O schiebt nichts hierher; das Plugin holt ab.
  Das ist Absicht: So braucht es keinen von außen erreichbaren Eingang auf einer
  Redaktionsseite.
- **Eine Zustellung überschreibt eine vorhandene Vertonung**, ohne Rückfrage. Es sitzt
  niemand am Bildschirm, den man fragen könnte. Eine laufende Vertonung wird dabei nie
  unterbrochen; die Zustellung wartet auf den nächsten Durchgang.
- **Eine gelieferte Fassung folgt dem Artikeltext nicht.** Sie stammt aus einem Text, der in
  WordPress nie stand, und wird deshalb nicht als „veraltet" gemeldet, wenn der Artikel sich
  ändert. Die Audio-Box im Editor sagt, woher die Fassung kommt und ob sie eine erzeugte
  ersetzt hat.

Bei einem **unveröffentlichten** Artikel muss in Heise I/O die Vorschau-Adresse oder die Adresse
aus dem Editor eingefügt werden. Den schönen Permalink gibt es dann noch nicht.

## Installation

### Option 1: Manuell via WordPress

1. ZIP des aktuellen Releases von [GitHub Releases](https://github.com/DEEP-CONTENT/article-tts/releases) herunterladen.
2. In WordPress unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** installieren.
3. Aktivieren.
4. Unter **Einstellungen → Heise I/O Article TTS** die Adresse der Heise-I/O-Instanz und den Zugangstoken eintragen.
5. Modell und Standard-Stimme wählen. Beide Listen kommen aus der Instanz und füllen sich erst, wenn Adresse und Token stimmen; ohne Modell weist die Gegenseite jede Vertonung zurück.

### Option 2: Composer

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/DEEP-CONTENT/article-tts" }
    ],
    "require": {
        "composer/installers": "^2.0",
        "deep-content/article-tts": "^1.0"
    },
    "extra": {
        "installer-paths": {
            "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
        }
    }
}
```

## Auto-Updates

Das Plugin nutzt [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) und fragt dieses Repository regelmäßig (voreingestellt zweimal täglich) nach einer neueren Fassung. Updates erscheinen in der WordPress Plugin-Übersicht.

**Wonach der Prüfer schaut, und in welcher Reihenfolge:** das jüngste GitHub-**Release**, dann der höchste Tag, dann der Branch `main` (`setBranch('main')` in `article-tts.php`). Die erste nicht-leere Antwort gewinnt, weitere werden nicht mehr versucht (`Puc/v5p6/Vcs/GitHubApi.php`, `getUpdateDetectionStrategies()`; `Vcs/Api.php`, `chooseReference()`). Die Versionsnummer liest er anschließend aus dem `Version:`-Header von `article-tts.php` **an genau dieser Referenz**.

Daran scheitert ein Release, ohne dass es auffällt: **Solange ein älteres Release existiert, gewinnt es.** Ein Merge nach `main` zeigt dann bei niemandem ein Update, und ein bloßer Tag ebenso wenig. Der `Stable tag` in `readme.txt` entscheidet darüber nichts — er gehört zu den Metadaten, die WordPress im Details-Dialog anzeigt.

Neue Versionen veröffentlichen:

1. Version in `article-tts.php` (Header `Version:` + `ARTICLE_TTS_VERSION`) und `readme.txt` (`Stable tag`) erhöhen — alle drei müssen dieselbe Nummer tragen.
2. In `readme.txt` einen Changelog-Eintrag ergänzen, und wenn die Version einen Eingriff erzwingt, eine `== Upgrade Notice ==`. Das ist der Text, den ein Administrator vor dem Klick zu sehen bekommt; der Update-Prüfer zeigt aus dieser Datei nur Description, Installation und Changelog.
3. Nach `main` mergen, CI abwarten.
4. **Release veröffentlichen** — der Schritt, der die Aktualisierung überhaupt auslöst:

   ```bash
   gh release create v1.1.1 --target main --title v1.1.1 --notes-file release-notes.md
   ```

   Der Tag muss auf einen Commit zeigen, dessen `Version:`-Header dieselbe Nummer trägt.

Zurücknehmen, solange noch keine Installation aktualisiert hat: `gh release delete v1.1.1 --cleanup-tag`.

`enableReleaseAssets()` ist aktiv: Hängt am Release eine Datei, wird **diese** installiert statt des automatisch erzeugten Zipballs — und zwar die erste, ungefiltert. Also entweder ein vollständiges, installierbares ZIP anhängen (Wurzelverzeichnis `article-tts/`) oder gar keins.

## Entwicklung

```bash
composer install   # installiert plugin-update-checker (require-dev)
```

`vendor/` ist committed, damit das Plugin auch ohne Composer-Setup lauffähig ist (manueller WP-Upload).

## Technische Hinweise

- Audio-Dateien werden unter `wp-content/uploads/article-tts/` gespeichert (öffentlich abrufbar, nicht in der Mediathek).
- Der Verkehr mit Heise I/O steht in `includes/class-client.php`, der Posteingang in `includes/class-deliveries.php`.
- Die Rollen-Sichtbarkeit (**Einstellungen → Sichtbar für**) unterdrückt nur Rendering und Asset-Loading des Players — die MP3-Datei selbst bleibt unter ihrer öffentlichen URL erreichbar, wenn sie bekannt ist.

## Lizenz

GPL v2 or later
