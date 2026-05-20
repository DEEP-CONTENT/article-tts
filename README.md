# Heise I/O Article TTS

Eigenständiges WordPress-Plugin, das Artikel-Inhalt (Titel + Excerpt + Body) über eine Text-to-Speech API in eine MP3-Datei umwandelt und einen Audio-Player im Frontend ausspielt.

## Features

- API-Key Konfiguration über eine eigene Settings-Seite (**Einstellungen → Heise I/O Article TTS**)
- Manuelle Audio-Generierung pro Artikel über einen Button in der Sidebar des Post-Editors
- Kuratierte Stimmen-Auswahl (männlich / weiblich), pro Post überschreibbar
- Hash-basiertes Caching — gleicher Text wird nicht erneut abgerechnet
- Frontend-Player über `the_content` Filter (Position konfigurierbar: oben/unten/beides/manuell)
- Shortcode `[article_audio]` für manuelle Platzierung im Block-Editor
- Funktioniert mit jedem Post-Type (Standard: `post`, weitere in den Settings aktivierbar)
- Nutzt ausschließlich WordPress-Core-APIs — keine Theme-Abhängigkeit

## Installation

### Option 1: Manuell via WordPress

1. ZIP des aktuellen Releases von [GitHub Releases](https://github.com/DEEP-CONTENT/article-tts/releases) herunterladen.
2. In WordPress unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** installieren.
3. Aktivieren.
4. Unter **Einstellungen → Heise I/O Article TTS** den API-Key eintragen.

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

Das Plugin nutzt [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) und prüft regelmäßig dieses Repository auf neue Tags. Updates erscheinen direkt in der WordPress Plugin-Übersicht.

Neue Versionen veröffentlichen:

1. Version in `article-tts.php` (Header `Version:` + `ARTICLE_TTS_VERSION`) und `readme.txt` (`Stable tag`) erhöhen.
2. Commit pushen.
3. Tag setzen und pushen: `git tag v1.0.1 && git push --tags`.
4. Optional: GitHub Release mit Changelog erstellen.

## Entwicklung

```bash
composer install   # installiert plugin-update-checker (require-dev)
```

`vendor/` ist committed, damit das Plugin auch ohne Composer-Setup lauffähig ist (manueller WP-Upload).

## Technische Hinweise

- Audio-Dateien werden unter `wp-content/uploads/article-tts/` gespeichert (öffentlich abrufbar, nicht in der Mediathek).
- API-Aufruf in `includes/class-api.php`.

## Lizenz

GPL v2 or later
