<?php
/**
 * Exercises the upgrade path without a WordPress installation.
 *
 * The migration runs unattended on live customer sites — the plugin updates
 * itself — so it is the one piece that must not be tested by trying it there.
 * WordPress is stubbed down to the handful of functions the class touches, which
 * is enough to cover what can actually go wrong: running twice, running on a
 * fresh install, and removing a key from an option that is read through a
 * defaults merge.
 *
 * The SQL itself is verified separately against a real MySQL fixture; here the
 * statement is only checked for shape.
 *
 *     php tools/check-upgrade.php
 *
 * Exit code 0 means the path behaves.
 */

// Kein Webaufruf. Diese Datei wird seit 1.1.1 mit ins Release-Paket gepackt und
// liegt damit in einem Verzeichnis, das viele Installationen ueber HTTP
// ausliefern — und anders als die Klassendateien hat sie keinen ABSPATH-Riegel,
// hinter den sie sich stellen koennte.
if (PHP_SAPI !== 'cli') {
    exit;
}


define('ABSPATH', '/tmp/');
define('ARTICLE_TTS_OPTION_KEY', 'article_tts_options');
define('ARTICLE_TTS_PATH', __DIR__ . '/../');

$GLOBALS['options'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['queries'] = [];

function add_action($hook, $cb) { $GLOBALS['actions'][] = $hook; }
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function current_user_can($c) { return true; }
function get_current_screen() { return null; }
function admin_url($p) { return 'http://example.test/wp-admin/' . $p; }
function esc_html__($s, $d = null) { return $s; }
function esc_html($s) { return $s; }
function esc_url($s) { return $s; }
function __($s, $d = null) { return $s; }

class FakeWpdb {
    public $postmeta = 'wp_postmeta';
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%s/', "'" . $a . "'", $sql, 1);
        }
        return $sql;
    }
    public function query($sql) { $GLOBALS['queries'][] = $sql; return 1; }
    public function get_var($sql) { return $GLOBALS['has_legacy_audio'] ?? null; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

// Nur die Konstanten, die das Upgrade aus dem Generator liest.
class Article_TTS_Generator {
    const META_HASH = '_article_tts_audio_hash';
    const META_HASH_VERSION = '_article_tts_hash_v';
}
class Article_TTS_Settings { const PAGE_SLUG = 'article-tts'; }
class Article_TTS_Plugin {
    public static function get_options() {
        return array_merge(
            ['api_base_url' => '', 'api_token' => ''],
            $GLOBALS['options'][ARTICLE_TTS_OPTION_KEY] ?? []
        );
    }
}

require ARTICLE_TTS_PATH . 'includes/class-upgrade.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($detail ? " — $detail" : '') . "\n"; }
}

function reset_state(array $options = [], $hasLegacyAudio = null): Article_TTS_Upgrade {
    $GLOBALS['options'] = $options;
    $GLOBALS['queries'] = [];
    $GLOBALS['has_legacy_audio'] = $hasLegacyAudio;

    // Frische Instanz erzwingen (Singleton).
    $r = new ReflectionClass('Article_TTS_Upgrade');
    $p = $r->getProperty('instance');
    $p->setAccessible(true);
    $p->setValue(null, null);

    return Article_TTS_Upgrade::get_instance();
}

echo "== Neuinstallation ==\n";
$u = reset_state([], null);
$u->maybe_upgrade();
check('Marker gesetzt', get_option('article_tts_db_version') === Article_TTS_Upgrade::DB_VERSION);
check('keine Migration ausgefuehrt', $GLOBALS['queries'] === [], count($GLOBALS['queries']) . ' Queries');

echo "== Bestandsinstallation mit altem Schluessel ==\n";
$u = reset_state([ARTICLE_TTS_OPTION_KEY => [
    'api_key' => 'sk-geheim',
    'default_voice_id' => 'IKne3meq5aSn9XLyUdCD',
    'model_id' => 'eleven_multilingual_v2',
]], null);
$u->maybe_upgrade();
$opts = get_option(ARTICLE_TTS_OPTION_KEY);
check('api_key entfernt', !array_key_exists('api_key', $opts));
check('uebrige Optionen unangetastet', ($opts['default_voice_id'] ?? null) === 'IKne3meq5aSn9XLyUdCD');
check('Stempel-Query lief', count($GLOBALS['queries']) === 1);
check('Query enthaelt LEFT JOIN', str_contains($GLOBALS['queries'][0] ?? '', 'LEFT JOIN'));
check('Marker gesetzt', get_option('article_tts_db_version') === Article_TTS_Upgrade::DB_VERSION);

echo "== Zweiter Aufruf laeuft nicht erneut ==\n";
$GLOBALS['queries'] = [];
$u->maybe_upgrade();
check('keine erneute Query', $GLOBALS['queries'] === []);

echo "== Bestandsinstallation ohne Optionen, aber mit Altaudio ==\n";
$u = reset_state([], 42);
$u->maybe_upgrade();
check('Migration lief trotzdem', count($GLOBALS['queries']) === 1);

echo "== Optionen ohne api_key ==\n";
$u = reset_state([ARTICLE_TTS_OPTION_KEY => ['default_voice_id' => 'x']], null);
$u->maybe_upgrade();
check('kein Fehler, Optionen intakt', (get_option(ARTICLE_TTS_OPTION_KEY)['default_voice_id'] ?? null) === 'x');

echo "== Hinweis erscheint nur unkonfiguriert ==\n";
$u = reset_state([ARTICLE_TTS_OPTION_KEY => []], null);
ob_start(); $u->render_notice(); $out = ob_get_clean();
check('Hinweis bei leerer Konfiguration', str_contains($out, 'Jetzt einrichten'));

$u = reset_state([ARTICLE_TTS_OPTION_KEY => ['api_base_url' => 'https://x.test', 'api_token' => 't']], null);
ob_start(); $u->render_notice(); $out = ob_get_clean();
check('kein Hinweis wenn konfiguriert', '' === trim($out), $out);

echo "\n== $ok ok, $fail fehlgeschlagen ==\n";
exit($fail === 0 ? 0 : 1);
