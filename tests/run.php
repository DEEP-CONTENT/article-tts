<?php

declare(strict_types=1);

/**
 * Der Testlauf dieses Plugins. Ohne Abhängigkeit, mit Absicht.
 *
 * WARUM KEIN PHPUNIT. `vendor/` ist eingecheckt und wird mit ausgeliefert — ein
 * Testwerkzeug dort landete in jeder Installation. Es liesse sich beim Packen
 * herausnehmen, aber dann haengt die Richtigkeit des Pakets an einem Schritt,
 * an den jemand denken muss. Was hier geprueft wird, sind reine Funktionen:
 * Zeichenketten hinein, Werte heraus. Dafuer reichen `assert_that` und ein
 * Rueckgabewert.
 *
 * WAS HIER HINEINGEHOERT: alles, was ohne WordPress laeuft und einen Vertrag
 * traegt — die Normalisierung und der Hash (beide muessen zeichengleich mit
 * Laravel sein), der Domainabgleich des Posteingangs, die Liste der
 * Meta-Schluessel. WAS NICHT: alles, was eine Datenbank oder WordPress braucht.
 * Dafuer waere ein echtes WP-Testgerüst noetig, und das kostet mehr, als es in
 * diesem Repo traegt.
 *
 *     php tests/run.php
 *
 * Rueckgabe 0 heisst: alle Zusicherungen halten.
 */

$failures = [];
$checks   = 0;

/**
 * @param string $what Was zugesichert wird — erscheint nur im Fehlerfall.
 */
function assert_that(string $what, bool $condition): void
{
    global $failures, $checks;
    ++$checks;
    if (! $condition) {
        $failures[] = $what;
    }
}

function assert_same(string $what, $expected, $actual): void
{
    global $failures, $checks;
    ++$checks;
    if ($expected !== $actual) {
        $failures[] = sprintf(
            "%s\n      erwartet: %s\n      erhalten: %s",
            $what,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

$files = glob(__DIR__ . '/*_test.php');
sort($files);

foreach ($files as $file) {
    echo basename($file, '_test.php'), "\n";
    require $file;
}

echo "\n";

if ($failures === []) {
    printf("%d Zusicherungen, alle gehalten.\n", $checks);
    exit(0);
}

printf("%d von %d Zusicherungen gebrochen:\n\n", count($failures), $checks);
foreach ($failures as $failure) {
    echo '  - ', $failure, "\n";
}
exit(1);
