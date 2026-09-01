<?php

declare(strict_types=1);

/**
 * Die Liste der Meta-Schluessel, die eine Zustellung hinterlaesst.
 *
 * Sie wird an zwei Stellen durchlaufen — beim Uebernehmen einer erzeugten
 * Fassung und beim Loeschen des Audios —, damit ein Beitrag nicht mit der
 * Herkunft einer Datei zurueckbleibt, die es nicht mehr gibt.
 *
 * Der Fehler, den das hier faengt, ist banal und deshalb wahrscheinlich: eine
 * neue `META_*`-Konstante anlegen und die Liste vergessen. Genau das ist bei
 * `META_PROJECT` beinahe passiert, im letzten Commit dieses Branches.
 */

// Kein Webaufruf. Diese Datei wird seit 1.1.1 mit ins Release-Paket gepackt und
// liegt damit in einem Verzeichnis, das viele Installationen ueber HTTP
// ausliefern — und anders als die Klassendateien hat sie keinen ABSPATH-Riegel,
// hinter den sie sich stellen koennte.
if (PHP_SAPI !== 'cli') {
    exit;
}


require_once __DIR__ . '/_wp_stubs.php';
require_once __DIR__ . '/../includes/class-deliveries.php';

$listed = Article_TTS_Deliveries::meta_keys();
$reflection = new ReflectionClass('Article_TTS_Deliveries');

foreach ($reflection->getConstants() as $name => $value) {
    if (strpos($name, 'META_') !== 0) {
        continue;
    }

    assert_that(
        sprintf('%s steht in meta_keys() — sonst bleibt sie beim Loeschen stehen', $name),
        in_array($value, $listed, true)
    );
}

assert_that('meta_keys() enthaelt keine Dubletten', count($listed) === count(array_unique($listed)));
assert_that('und ist nicht leer', $listed !== array());

// --- Dieselbe Falle im Generator --------------------------------------------
//
// `Article_TTS_Generator::delete()` fuehrt seine Schluessel als Liste, und die
// Klasse laesst sich hier nicht laden (sie zieht beim Einbinden WordPress nach).
// Geprueft wird deshalb der Quelltext: Jede `META_*`-Konstante muss in dieser
// Liste stehen — oder ausdruecklich als Ausnahme benannt sein.
//
// WO EIN SCHNITT AUSFAELLT, WIRD ABGEBROCHEN, nicht weitergeprueft. Dasselbe
// Verfahren wie in `_load.php`, und aus demselben Grund: Ein Waechter, der
// stillschweigend nichts mehr prueft, ist schlimmer als keiner. Genau das ist
// hier passiert — die erste Fassung schnitt bei fehlendem Endanker ein Fenster
// zurecht, dessen Groesse allein an der Dateilaenge hing, und blieb gruen.

$source = file_get_contents(__DIR__ . '/../includes/class-generator.php');
assert_that('class-generator.php ist lesbar', is_string($source) && $source !== '');

// Beide Anfuehrungsarten und Ziffern im Namen, damit eine anders geschriebene
// Konstante nicht unbemerkt an der Pruefung vorbeilaeuft.
preg_match_all('/const\s+(META_[A-Z0-9_]+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $matches, PREG_SET_ORDER);

$roh = preg_match_all('/const\s+META_/', $source);
if (count($matches) !== $roh) {
    fwrite(STDERR, "META_-Konstanten in anderer Schreibweise gefunden: {$roh} deklariert, " . count($matches) . " erfasst.\n");
    exit(2);
}
assert_that('der Generator hat META_-Konstanten', count($matches) > 5);

$start = strpos($source, 'public static function delete(');
if ($start === false) {
    fwrite(STDERR, "delete() nicht gefunden — umbenannt oder umformatiert. Der Waechter kann so nichts pruefen.\n");
    exit(2);
}

$ende = strpos($source, "\n\tpublic static function is_stale", $start);
if ($ende === false) {
    fwrite(STDERR, "Der Endanker is_stale() steht nicht mehr hinter delete(). Der Waechter kann so nichts pruefen.\n");
    exit(2);
}

$body = substr($source, $start, $ende - $start);

// Kommentare zaehlen nicht: Ein Schluessel, der nur noch in einer Erlaeuterung
// vorkommt, ist geloescht und nicht aufgeraeumt.
$body = (string) preg_replace('!//[^\n]*|/\*.*?\*/!s', '', $body);

assert_that(
    'zwischen delete() und is_stale() steht keine weitere Methode — sonst pruefte der Ausschnitt zu viel',
    substr_count($body, 'public static function') === 1
);

// BEWUSST NICHT in der Loeschliste: die pro Beitrag gewaehlte Stimme ist eine
// Einstellung, keine Spur der Vertonung. Wer sie mitloeschte, naehme dem
// Redakteur bei jedem „Audio entfernen" seine Auswahl weg.
$ausnahmen = ['META_OVERRIDE'];

// MIT WORTGRENZE. `strpos` allein fand `self::META_HASH` auch in
// `self::META_HASH_VERSION` — ausgerechnet der Schluessel, den `is_stale()` als
// einzigen Vergleichswert liest, konnte so aus der Liste fallen, ohne dass
// hier etwas rot wurde.
$steht_in_delete = static function ($name) use ($body) {
    $muster = '/(?:self|static|Article_TTS_Generator)::' . preg_quote($name, '/') . '\b/';

    return preg_match($muster, $body) === 1;
};

foreach ($matches as $match) {
    $name = $match[1];

    if (in_array($name, $ausnahmen, true)) {
        assert_that(
            sprintf('%s ist als Ausnahme benannt und steht folgerichtig NICHT in delete()', $name),
            !$steht_in_delete($name)
        );
        continue;
    }

    assert_that(
        sprintf('%s steht in Article_TTS_Generator::delete() — sonst bleibt sie am Beitrag stehen', $name),
        $steht_in_delete($name)
    );
}

$werte = array_column($matches, 2);
assert_that('keine zwei Generator-Konstanten teilen sich einen Schluessel', count($werte) === count(array_unique($werte)));
