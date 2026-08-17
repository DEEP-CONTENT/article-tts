<?php

declare(strict_types=1);

/**
 * Der Domainabgleich des Posteingangs — laut seinem eigenen Kommentar
 * „DIE EINE STELLE, DIE STIMMEN MUSS".
 *
 * Der Posteingang ist mandantenweit: er enthaelt auch die Zustellungen anderer
 * Installationen desselben Kunden. Ein falsches JA haengt fremdes Audio an einen
 * eigenen Artikel. Ein falsches NEIN laesst die eigene Zustellung liegen, bis
 * sie nach sieben Tagen ablaeuft — lautlos, denn wer nichts erwartet, vermisst
 * nichts.
 *
 * Die Funktion verhaelt sich in allen Faellen unten richtig. Das ist der Punkt:
 * bisher hielt das NICHTS fest, und wer sie umbaut, haette keinen Waechter.
 *
 * Anders als bei der Normalisierung wird hier nichts ausgeschnitten — die Klasse
 * laesst sich mit drei Ersatzfunktionen laden und dann direkt aufrufen. Das ist
 * die ehrlichere Pruefung: sie geht durch denselben Code, den WordPress aufruft.
 */

require_once __DIR__ . '/_wp_stubs.php';
require_once __DIR__ . '/../includes/class-deliveries.php';

$eigene = 'https://the-decoder.de';
wp_stub_site($eigene);

$matches = static fn (string $url): bool => Article_TTS_Deliveries::host_matches($url);

// --- Was dazugehoert --------------------------------------------------------

assert_that('schlichter Permalink', $matches('https://the-decoder.de/ein-artikel/'));
assert_that('Editor-Adresse', $matches('https://the-decoder.de/wp-admin/post.php?post=1&action=edit'));
assert_that('Grossschreibung ist egal', $matches('https://THE-DECODER.de/x'));
assert_that('fuehrendes www. faellt weg', $matches('https://www.the-decoder.de/x'));
assert_that('ein Port stoert nicht', $matches('http://the-decoder.de:8443/?p=1'));
assert_that('schemenlose Protokoll-Adresse', $matches('//the-decoder.de/x'));
assert_that('Zugangsdaten in der Adresse', $matches('https://nutzer:geheim@the-decoder.de/x'));

// --- Was NICHT dazugehoert --------------------------------------------------
//
// Die beiden ersten sind die, auf die es ankommt: ein Suffix-Vergleich statt
// eines Gleichheitsvergleichs wuerde bei „the-decoder.de.evil.com" JA sagen und
// fremdes Audio an einen fremden Artikel haengen.

assert_that('angehaengte Fremddomain', ! $matches('https://the-decoder.de.evil.com/x'));
assert_that('eigene Domain nur in der Abfrage', ! $matches('https://evil.de/x?u=https://the-decoder.de/'));
assert_that('eigene Domain nur im Pfad', ! $matches('https://evil.de/the-decoder.de/x'));
assert_that('aehnlich, aber anders', ! $matches('https://wwwx.the-decoder.de/x'));
assert_that('andere Domain', ! $matches('https://example.com/x'));

// --- Was gar keine Adresse ist ----------------------------------------------
//
// Ohne Schema findet `parse_url` keinen Host — die Adresse gilt als fremd. Das
// ist die sichere Richtung: liegenlassen statt falsch zuordnen.

assert_that('ohne Schema kein Host', ! $matches('the-decoder.de/wp-admin/post.php?post=1'));
assert_that('leere Adresse', ! $matches(''));
assert_that('Unsinn', ! $matches('kein-url'));

// --- Die Installation selbst ------------------------------------------------

wp_stub_site('https://www.the-decoder.de');
assert_that(
    'ein www. in der eigenen Adresse aendert nichts',
    Article_TTS_Deliveries::host_matches('https://the-decoder.de/x')
);

wp_stub_site('https://kunde-a.de', 'https://kunde-a.de/blog');
assert_that(
    'abweichende Site-Adresse zaehlt mit',
    Article_TTS_Deliveries::host_matches('https://kunde-a.de/blog/artikel/')
);
