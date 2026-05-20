=== Heise I/O Article TTS ===
Contributors: deep-content-by-heise
Tags: tts, audio, text-to-speech, audio-articles
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later

Wandelt WordPress-Artikel über eine Text-to-Speech API in Audio um und zeigt einen HTML5-Player im Frontend.

== Description ==

Eigenständiges WordPress-Plugin, das Artikel-Inhalt (Titel + Excerpt + Body) über eine Text-to-Speech API in eine MP3-Datei umwandelt und einen Audio-Player im Frontend ausspielt.

Features:

* API-Key Konfiguration über eine eigene Settings-Seite (Einstellungen → Heise I/O Article TTS)
* Manuelle Audio-Generierung pro Artikel über einen Button in der Sidebar des Post-Editors
* Kuratierte Stimmen-Auswahl (männlich / weiblich), pro Post überschreibbar
* Hash-basiertes Caching — gleicher Text wird nicht erneut abgerechnet
* Frontend-Player über `the_content` Filter (Position konfigurierbar: oben/unten/beides/manuell)
* Shortcode `[article_audio]` für manuelle Platzierung im Block-Editor
* Funktioniert mit jedem Post-Type (Standard: `post`, weitere in den Settings aktivierbar)
* Nutzt ausschließlich WordPress-Core-APIs — keine Theme-Abhängigkeit, portabel zu jeder WP-Installation

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/article-tts/` kopieren.
2. Unter Plugins aktivieren.
3. Unter **Einstellungen → Heise I/O Article TTS** den API-Key eintragen.
4. Standard-Stimme wählen.
5. Im Post-Editor in der Sidebar-Box „Audio-Version" auf **Audio generieren** klicken.

== Technische Hinweise ==

* Audio-Dateien werden unter `wp-content/uploads/article-tts/` gespeichert (öffentlich abrufbar, nicht in der Mediathek).
* Details zum verwendeten TTS-Anbieter siehe Code-Kommentare in `includes/class-api.php`.

== Changelog ==

= 1.0.4 =
* "Einstellungen"-Link in der Aktions-Spalte der Plugin-Übersicht.

= 1.0.3 =
* "Excerpt mitsprechen" ist bei Neuinstallationen jetzt standardmäßig deaktiviert. Bestehende Installationen behalten ihre Einstellung.

= 1.0.2 =
* Neues Setting "Sichtbar für": Player kann auf bestimmte Rollen (inkl. Gäste) beschränkt werden. Leer = alle Besucher (Standard).

= 1.0.1 =
* Doku: provider-neutrale Formulierung in README.

= 1.0.0 =
* Initial release.
