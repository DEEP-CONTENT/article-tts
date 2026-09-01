<?php

declare(strict_types=1);

/**
 * Proves that this plugin and the DC-IO backend hash the same text identically.
 *
 * The submit sends a content hash and the backend recomputes it; a mismatch is a
 * 400 and no article is ever rendered. The two implementations therefore have to
 * agree byte for byte — over CRLF, non-breaking spaces, emoji and every other
 * character nobody looks at. This script is how that is checked rather than
 * hoped for.
 *
 * Not part of the plugin runtime. Run it from a checkout that has both repos:
 *
 *     php tools/check-hash-parity.php /pfad/zu/Laravel-DC-IO
 *
 * Exit code 0 means the two agree.
 */

// Kein Webaufruf. Diese Datei wird seit 1.1.1 mit ins Release-Paket gepackt und
// liegt damit in einem Verzeichnis, das viele Installationen ueber HTTP
// ausliefern — und anders als die Klassendateien hat sie keinen ABSPATH-Riegel,
// hinter den sie sich stellen koennte.
if (PHP_SAPI !== 'cli') {
    exit;
}


$laravelRoot = $argv[1] ?? getenv('DC_IO_PATH') ?: '';

if ($laravelRoot === '' || !is_dir($laravelRoot)) {
    fwrite(STDERR, "Usage: php tools/check-hash-parity.php /pfad/zu/Laravel-DC-IO\n");
    exit(2);
}

$base = rtrim($laravelRoot, '/') . '/app/Services/TtsArticles/';
require $base . 'ArticleText.php';
require $base . 'ContentHasher.php';

use App\Services\TtsArticles\ArticleText;
use App\Services\TtsArticles\ContentHasher;

// --- Plugin-Seite: die beiden statischen Methoden aus der Klasse herausziehen ---
$pluginSource = file_get_contents(__DIR__ . '/../includes/class-generator.php');

preg_match('/public static function normalize\( \$text \) \{(.*?)\n\t\}/s', $pluginSource, $m1);
preg_match('/public static function content_hash\( \$normalized_text, \$voice_id, \$model_slug, \$language \) \{(.*?)\n\t\}/s', $pluginSource, $m2);

if (!isset($m1[1], $m2[1])) {
    fwrite(STDERR, "Konnte die Plugin-Methoden nicht extrahieren — Signatur geaendert?\n");
    exit(2);
}

eval('function plugin_normalize($text) {' . $m1[1] . '}');
// self::HASH_VERSION ist ausserhalb der Klasse nicht aufloesbar — durch den
// Literalwert aus derselben Datei ersetzen, damit exakt DIESE Konstante gilt.
preg_match('/const HASH_VERSION\s*=\s*(\d+);/', $pluginSource, $mv);
$body2 = str_replace('self::HASH_VERSION', $mv[1], $m2[1]);
eval('function plugin_content_hash($normalized_text, $voice_id, $model_slug, $language) {' . $body2 . '}');

$cases = [
    'einfach' => "Ein Artikel. Noch ein Satz.",
    'crlf' => "Zeile eins.\r\n\r\nZeile zwei.",
    'cr' => "Zeile eins.\r\rZeile zwei.",
    'viele leerzeilen' => "Absatz eins.\n\n\n\n\nAbsatz zwei.",
    'tabs und spaces' => "Wort\t\tWort   Wort",
    'space am zeilenende' => "Zeile eins.   \n   Zeile zwei.",
    'umlaute' => "Größe, Übermaß und Straße — schöne Grüße.",
    'emoji' => "Ein Artikel 🎧 mit Zeichen jenseits der BMP 𝄞.",
    'nbsp' => "Wort\u{00A0}Wort",
    'fuehrend/nachlaufend' => "\n\n  Text mit Rand.  \n\n",
    'nur whitespace' => "   \n\n  ",
    'leer' => '',
    'langer text' => str_repeat("Ein Satz mit Inhalt. ", 500),
];

$fail = 0;
$ok = 0;

foreach ($cases as $name => $raw) {
    $a = ArticleText::normalize($raw);
    $b = plugin_normalize($raw);

    if ($a !== $b) {
        $fail++;
        printf("  FAIL normalize [%s]\n    laravel=%s\n    plugin =%s\n", $name, var_export($a, true), var_export($b, true));
        continue;
    }

    $ha = (new ContentHasher())->hash($a, 'voice-1', 'model-a', 'de');
    $hb = plugin_content_hash($b, 'voice-1', 'model-a', 'de');

    if ($ha !== $hb) {
        $fail++;
        printf("  FAIL hash [%s]\n    laravel=%s\n    plugin =%s\n", $name, $ha, $hb);
        continue;
    }

    $ok++;
    printf("  ok   %-22s len=%d hash=%s…\n", $name, mb_strlen($a), substr($ha, 0, 12));
}

// Gegenprobe: unterschiedliche Eingaben muessen unterschiedliche Hashes geben.
$h1 = plugin_content_hash(plugin_normalize('Text A'), 'v', 'm', 'de');
$h2 = plugin_content_hash(plugin_normalize('Text B'), 'v', 'm', 'de');
$h3 = plugin_content_hash(plugin_normalize('Text A'), 'w', 'm', 'de');
$h4 = plugin_content_hash(plugin_normalize('Text A'), 'v', 'm', 'en');

foreach ([['Text', $h1, $h2], ['Stimme', $h1, $h3], ['Sprache', $h1, $h4]] as [$what, $x, $y]) {
    if ($x === $y) {
        $fail++;
        echo "  FAIL $what aendert den Hash nicht\n";
    } else {
        $ok++;
        echo "  ok   $what aendert den Hash\n";
    }
}

// Und der Versionspraefix trennt vom Alt-Format.
if (md5('v|m|Text A') === $h1) {
    $fail++;
    echo "  FAIL v1-Formel kollidiert\n";
} else {
    $ok++;
    echo "  ok   v1-Formel kollidiert nicht\n";
}

echo "\n== $ok ok, $fail fehlgeschlagen ==\n";
exit($fail === 0 ? 0 : 1);
