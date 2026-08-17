<?php

declare(strict_types=1);

/**
 * Gerade so viel WordPress, dass sich eine Klassendatei laden laesst.
 *
 * NUR FUNKTIONEN, DEREN VERHALTEN TRIVIAL IST: `wp_parse_url` ist hier `parse_url`
 * (WordPress' Fassung fuegt nur eine Behandlung schemenloser Adressen fuer alte
 * PHP-Versionen hinzu), `__()` gibt seinen Text zurueck. Wo eine Ersatzfunktion
 * mehr taete als das, gehoert die Pruefung nicht hierher, sondern in ein echtes
 * WordPress — sonst prueft man die eigene Nachbildung.
 *
 * `ABSPATH` ist gesetzt, damit die Klassendateien nicht ihren Schutzriegel
 * ziehen. Beim Laden rufen sie nichts auf; die WordPress-Aufrufe stehen alle in
 * Methodenkoerpern.
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['wp_stub_home'] = 'https://example.com';
$GLOBALS['wp_stub_site'] = 'https://example.com';

/** Die Adressen setzen, unter denen die Installation sich selbst kennt. */
function wp_stub_site(string $home, ?string $site = null): void
{
    $GLOBALS['wp_stub_home'] = $home;
    $GLOBALS['wp_stub_site'] = $site ?? $home;
}

if (! function_exists('home_url')) {
    function home_url()
    {
        return $GLOBALS['wp_stub_home'];
    }
}

if (! function_exists('site_url')) {
    function site_url()
    {
        return $GLOBALS['wp_stub_site'];
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url((string) $url, $component);
    }
}

if (! function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
