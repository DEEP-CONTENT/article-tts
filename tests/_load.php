<?php

declare(strict_types=1);

/**
 * Die reinen Funktionen aus den Plugin-Klassen holen, ohne WordPress zu laden.
 *
 * Jede Klassendatei bricht ohne `ABSPATH` ab, und `class-generator.php` zieht
 * beim Laden WordPress-Funktionen nach. Was hier geprueft wird, sind aber
 * Funktionen, die von WordPress nichts wissen — sie werden deshalb einzeln aus
 * dem Quelltext geschnitten und als freistehende Funktionen bereitgestellt.
 *
 * DASSELBE VERFAHREN wie `tools/check-hash-parity.php`, und mit demselben
 * Haken: es haengt an der Schreibweise der Signatur. Faellt der Schnitt aus,
 * bricht dieser Lauf laut ab, statt stillschweigend nichts zu pruefen — das ist
 * der Unterschied, auf den es ankommt.
 */

/** @return string Der Rumpf der Funktion, ohne Signatur und Klammern. */
function cut_function_body(string $file, string $signature): string
{
    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, "Datei nicht lesbar: {$file}\n");
        exit(2);
    }

    $pattern = '/' . preg_quote($signature, '/') . ' \{(.*?)\n\t\}/s';
    if (preg_match($pattern, $source, $match) !== 1) {
        fwrite(
            STDERR,
            "Die Signatur wurde nicht gefunden — wurde sie umbenannt oder umformatiert?\n"
            . "  Datei:     {$file}\n"
            . "  Gesucht:   {$signature}\n"
        );
        exit(2);
    }

    return $match[1];
}

$generator = __DIR__ . '/../includes/class-generator.php';

// Aus der Klasse gelesen statt hier wiederholt: eine abgeschriebene Version
// stimmte genau so lange, bis jemand die echte erhoeht.
preg_match('/const HASH_VERSION\s*=\s*([^;]+);/', (string) file_get_contents($generator), $version);
define('HASH_VERSION', trim($version[1]));

eval('function n($text) {' . cut_function_body($generator, 'public static function normalize( $text )') . '}');

eval(
    'function content_hash($normalized_text, $voice_id, $model_slug, $language) {'
    . str_replace('self::HASH_VERSION', 'HASH_VERSION', cut_function_body(
        $generator,
        'public static function content_hash( $normalized_text, $voice_id, $model_slug, $language )'
    ))
    . '}'
);
