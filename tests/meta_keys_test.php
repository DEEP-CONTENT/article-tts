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
