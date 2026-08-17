<?php

declare(strict_types=1);

/**
 * Die andere Haelfte des Hash-Vertrags.
 *
 * Der Hash entscheidet zweierlei: ob die Gegenseite die Vertonung annimmt
 * (Abweichung = 400, kein Audio), und ob eine vorhandene Fassung als aktuell
 * gilt. Beides sind Zustaende, in denen niemand auf die Idee kaeme, den Hash zu
 * verdaechtigen.
 */

require_once __DIR__ . '/_load.php';

$h = static fn (string $t, string $v = 'stimme', string $m = 'modell', string $l = 'de'): string
    => content_hash($t, $v, $m, $l);

// --- Jedes Feld zaehlt ------------------------------------------------------

assert_that('derselbe Eingang, derselbe Hash', $h('text') === $h('text'));
assert_that('anderer Text', $h('a') !== $h('b'));
assert_that('andere Stimme', $h('a', 'x') !== $h('a', 'y'));
assert_that('anderes Modell', $h('a', 'v', 'm1') !== $h('a', 'v', 'm2'));
assert_that('andere Sprache', $h('a', 'v', 'm', 'de') !== $h('a', 'v', 'm', 'en'));

// Das Modell ist das einzige Feld, das `tools/check-hash-parity.php` nie
// variiert — deshalb steht es hier ausdruecklich.

// --- Die Version gehoert dazu -----------------------------------------------

assert_that(
    'die Versionsangabe geht in den Hash ein',
    $h('a') !== hash('sha256', implode('|', array('stimme', 'modell', 'de', 'a')))
);

// --- Das Trennzeichen ist nicht maskiert ------------------------------------
//
// FESTGEHALTEN, WIE ES IST. Die Felder werden mit `|` verkettet, ohne sie zu
// maskieren — eine Pipe IN einem Feld verschiebt also die Grenze. Heute
// folgenlos, weil Stimmen- und Modellkennungen keine Pipes enthalten, und die
// Gegenseite rechnet ohnehin dieselbe Formel.
//
// Es steht hier, damit der Tag, an dem ein Feld eine Pipe enthalten koennte
// (ein Stimmenname? eine zusammengesetzte Kennung?), nicht unbemerkt vorbeigeht.

assert_that(
    'eine Pipe im Feld verschiebt die Grenze — bekannt, heute folgenlos',
    content_hash('e', 'a|b', 'c', 'd') === content_hash('d|e', 'a', 'b', 'c')
);

// --- Leere Felder -----------------------------------------------------------

assert_that('leeres Modell ist ein eigener Fall', $h('a', 'v', '') !== $h('a', 'v', 'm'));
assert_that('ein Hash ist immer 64 Zeichen', strlen($h('a')) === 64);
