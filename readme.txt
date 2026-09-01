=== Heise I/O Article TTS ===
Contributors: deep-content-by-heise
Tags: tts, audio, text-to-speech, audio-articles
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
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

= 1.1.1 =
* **Die Vertonung läuft über Heise I/O, nicht mehr direkt beim Sprachanbieter.** Der Anbieter-Schlüssel entfällt; er liegt jetzt dort, wo er verwaltet wird, und nicht mehr in dieser WordPress-Installation.
* **Vor der ersten Vertonung einzutragen:** Adresse und Zugangstoken unter **Einstellungen → Heise I/O Article TTS**. Den Token erhalten Sie von Heise I/O. Ohne beides bleibt die Vertonung stehen — bereits erzeugte Audios spielen unverändert weiter.
* Neu: **Zustellungen aus dem Heise-I/O-Editor.** Eine dort erzeugte Vertonung, an die Adresse eines Artikels geschickt, hängt kurz darauf an diesem Artikel. Einzurichten ist dafür nichts; abgeholt wird im Fünf-Minuten-Takt, es kann also so lange dauern. Eine Zustellung ersetzt eine vorhandene Vertonung, unterbricht aber nie eine laufende.
* Neu: Knopf **„Jetzt von Heise I/O holen"** in der Audio-Box, wenn das Warten auf den nächsten Durchgang zu lang ist.
* Die Vertonung hält keinen Seitenaufruf mehr offen: Sie läuft im Hintergrund, die Audio-Box zeigt den Fortschritt.
* Die Stimmenauswahl kommt aus dem Katalog Ihrer Instanz. Die Box nennt den Stand des Katalogs und lässt ihn neu laden. Das Modell ist ein Pflichtfeld — ohne Modell weist die Gegenseite jede Vertonung zurück.
* Bestehende Installationen werden beim Update übernommen. Eine vor der Umstellung erzeugte Fassung bleibt abspielbar und meldet sich als solche, statt als „veraltet" zu erscheinen.
* Behoben: Der Fünf-Minuten-Cron starb im Frontend an einer Funktion, die es dort nicht gibt.
* Behoben: Ein einzelnes ungültiges Byte im Artikeltext verschluckte den ganzen Artikel.
* Behoben: Das Speichern der Einstellungen löschte das Modell — und damit jede weitere Vertonung.
* Behoben: Frisch erzeugtes Audio war erst nach einem Neuladen des Editors abspielbar.

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

== Upgrade Notice ==

= 1.1.1 =
Diese Fassung spricht nicht mehr direkt mit dem Sprachanbieter, sondern mit Heise I/O. Tragen Sie danach unter Einstellungen → Heise I/O Article TTS Adresse und Zugangstoken ein — den Token erhalten Sie von Heise I/O. Bis dahin lässt sich nichts Neues vertonen; vorhandene Audios spielen weiter.
