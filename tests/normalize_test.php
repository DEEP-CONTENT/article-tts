<?php

declare(strict_types=1);

/**
 * Die Normalisierung — die eine Haelfte des Hash-Vertrags mit Heise I/O.
 *
 * Der Beitrag schickt seinen Text UND dessen Hash; die Gegenseite rechnet den
 * Hash aus dem empfangenen Text nach und lehnt bei Abweichung mit 400 ab. Die
 * beiden Implementierungen muessen also zeichengleich rechnen — ueber CRLF,
 * geschuetzte Leerzeichen, Emoji und jedes andere Zeichen, auf das niemand
 * schaut. `tools/check-hash-parity.php` prueft das gegen einen Laravel-Checkout;
 * hier stehen die Klassen, die auch ohne einen gelten.
 *
 * Diese Datei laedt die Plugin-Klasse NICHT: `class-generator.php` bricht ohne
 * WordPress ab (`ABSPATH`). Sie schneidet die beiden reinen Funktionen heraus,
 * wie das Paritaetsskript es tut.
 */

// Kein Webaufruf. Diese Datei wird seit 1.1.1 mit ins Release-Paket gepackt und
// liegt damit in einem Verzeichnis, das viele Installationen ueber HTTP
// ausliefern — und anders als die Klassendateien hat sie keinen ABSPATH-Riegel,
// hinter den sie sich stellen koennte.
if (PHP_SAPI !== 'cli') {
    exit;
}


require_once __DIR__ . '/_load.php';

// --- CRLF und einzelne CR ---------------------------------------------------

assert_same('CRLF wird zu LF', "a\nb", n("a\r\nb"));
assert_same('einzelnes CR wird zu LF', "a\nb", n("a\rb"));

// --- Waagerechter Leerraum --------------------------------------------------

assert_same('Tabulatoren und Mehrfach-Leerzeichen fallen zusammen', 'a b', n("a \t  b"));
assert_same('geschuetztes Leerzeichen faellt mit zusammen', 'a b', n("a\xC2\xA0b"));
assert_same('ideographisches Leerzeichen ebenso', 'a b', n("a\xE3\x80\x80b"));

// Kein waagerechter Leerraum im Sinne von \h — bleibt also stehen. Festgehalten,
// weil beide Seiten dieselbe Meinung dazu haben muessen, welche das sind.
assert_same('Nullbreiten-Leerzeichen bleibt', "a\xE2\x80\x8Bb", n("a\xE2\x80\x8Bb"));

// --- Absaetze ---------------------------------------------------------------

assert_same('Leerzeichen am Zeilenumbruch verschwinden', "a\nb", n("a  \n  b"));
assert_same('drei und mehr Umbrueche werden zwei', "a\n\nb", n("a\n\n\n\nb"));
assert_same('die Leerzeile ueberlebt', "a\n\nb", n("a\n\nb"));
assert_same('aussen wird getrimmt', 'a', n("  \n a \n  "));
assert_same('nur Leerraum ergibt leer', '', n("  \n\t "));

// --- Zeichen jenseits der Grundebene ---------------------------------------

assert_same('Emoji bleibt unangetastet', "a \xF0\x9F\x8E\xA7 b", n("a \xF0\x9F\x8E\xA7  b"));

// --- Zweimal normalisieren aendert nichts mehr ------------------------------

foreach (["a \t b", "a\r\n\r\n\r\nb", "  x  ", "a\xC2\xA0b"] as $sample) {
    assert_same(
        'zweimal normalisieren ist wie einmal: ' . var_export($sample, true),
        n($sample),
        n(n($sample))
    );
}

// --- UNGUELTIGES UTF-8 ------------------------------------------------------
//
// FESTGEHALTEN, WIE ES IST, und nicht wie man es sich wuenscht. `preg_replace`
// mit `/u` gibt bei ungueltigem UTF-8 NULL zurueck, der `(string)`-Cast macht
// daraus '', und die beiden folgenden Aufrufe setzen `preg_last_error()` wieder
// zurueck — der Fehler hinterlaesst keine Spur.
//
// Laravels `ArticleText::normalize()` ist zeichengleich, samt Cast. Diese
// Zusicherung darf also NICHT „repariert" werden: wer sie hier gruen macht,
// bricht die Paritaet und damit jede Vertonung eines Textes, den beide Seiten
// verschieden lesen.
//
// Der Fehler lag nie in dieser Funktion, sondern davor: `submit()` gab
// ungereinigten Text hinein und meldete beim Leerstring „Der zusammengesetzte
// Artikeltext ist leer." — ein Artikel voller Text meldete sich als leer.
// Gereinigt wird deshalb VOR dem Normalisieren (`wp_check_invalid_utf8`), wo es
// die Paritaet nicht beruehrt: die Gegenseite bekommt bereits sauberen Text.

assert_same(
    'ungueltiges UTF-8 ergibt hier leer — zeichengleich mit Laravel, absichtlich',
    '',
    n("Ein ganz normaler Artikel\xFF mit viel Text.")
);

assert_same('gueltiger Text bleibt gueltiger Text', 'unveraendert', n('unveraendert'));
