=== Heise I/O Article TTS ===
Contributors: deep-content-by-heise
Tags: tts, audio, text-to-speech, audio-articles
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

Vertont WordPress-Artikel über Heise I/O und spielt das Ergebnis als HTML5-Player im Frontend aus.

== Description ==

Eigenständiges WordPress-Plugin, das Artikel-Inhalt (Titel, Body, auf Wunsch das Excerpt) über Heise I/O in eine MP3-Datei umwandelt und einen Audio-Player im Frontend ausspielt.

Die Zugangsdaten des Sprachanbieters liegen nicht in dieser Installation, sondern bei Heise I/O. Hier einzutragen sind nur die Adresse Ihrer Instanz und ein Zugangstoken.

Features:

* Adresse und Zugangstoken über eine eigene Settings-Seite (Einstellungen → Heise I/O Article TTS)
* Vertonung pro Artikel über einen Knopf in der Sidebar des Post-Editors; sie läuft im Hintergrund, die Audio-Box zeigt den Fortschritt
* Stimmen aus dem Katalog Ihrer Instanz, nach Sprache gruppiert, pro Artikel überschreibbar
* Nimmt Vertonungen entgegen, die im Heise-I/O-Editor erzeugt und an einen Artikel geschickt wurden — ohne Einrichtung, ohne von außen erreichbaren Eingang
* Hash-basiertes Caching — gleicher Text wird nicht erneut abgerechnet
* Frontend-Player über `the_content` Filter (Position konfigurierbar: oben/unten/beides/manuell)
* Shortcode `[article_audio]` für manuelle Platzierung im Block-Editor
* Funktioniert mit jedem Post-Type (Standard: `post`, weitere in den Settings aktivierbar)
* Sichtbarkeit des Players pro Rolle steuerbar (inkl. Gäste); leer = alle Besucher
* Nutzt ausschließlich WordPress-Core-APIs — keine Theme-Abhängigkeit, portabel zu jeder WP-Installation

Zustellungen aus dem Heise-I/O-Editor: Wer dort eine Vertonung erzeugt und die Adresse eines Artikels einfügt, findet die Datei kurz darauf an diesem Artikel. Einzurichten ist dafür nichts, es genügen Adresse und Token aus den Einstellungen. Abgeholt wird im Fünf-Minuten-Takt — Heise I/O schiebt nichts hierher, diese Seite holt, damit auf einer Redaktionsseite kein von außen erreichbarer Eingang nötig ist. Eine Zustellung ersetzt eine vorhandene Vertonung ohne Rückfrage, unterbricht aber nie eine laufende. Bei einem noch unveröffentlichten Artikel muss drüben die Vorschau-Adresse eingefügt werden; den schönen Permalink gibt es dann noch nicht.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/article-tts/` kopieren — oder das ZIP unter **Plugins → Neues Plugin hinzufügen → Plugin hochladen** einspielen.
2. Unter Plugins aktivieren.
3. Unter **Einstellungen → Heise I/O Article TTS** die Adresse Ihrer Heise-I/O-Instanz und den Zugangstoken eintragen. Den Token erhalten Sie von Heise I/O.
4. Modell und Standard-Stimme wählen. Beide Listen kommen aus Ihrer Instanz und füllen sich erst, wenn Adresse und Token stimmen. Ohne Modell weist die Gegenseite jede Vertonung zurück.
5. Im Post-Editor in der Sidebar-Box „Audio-Version" auf **Audio generieren** klicken.

== Technische Hinweise ==

* Audio-Dateien werden unter `wp-content/uploads/article-tts/` gespeichert (öffentlich abrufbar, nicht in der Mediathek).
* Der Verkehr mit Heise I/O steht in `includes/class-client.php`, der Posteingang in `includes/class-deliveries.php`.
* Der Zugangstoken liegt in den Plugin-Optionen (`wp_options`) und geht als `Authorization: Bearer` an die eingetragene Adresse.
* Die Rollen-Sichtbarkeit (**Sichtbar für**) unterdrückt nur den Player und seine Assets — die MP3-Datei bleibt unter ihrer öffentlichen Adresse erreichbar, wenn sie jemand kennt.
* Eine gelieferte Fassung folgt dem Artikeltext nicht: Sie stammt aus einem Text, der in WordPress nie stand, und wird deshalb nicht als „veraltet" gemeldet, wenn der Artikel sich ändert.

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
