<?php
/**
 * Plugin Name: Heise I/O Article TTS
 * Description: Wandelt WordPress-Artikel über eine Text-to-Speech API in Audio um und zeigt einen HTML5-Player im Frontend.
 * Version: 1.0.4
 * Author: Deep Content by Heise
 * License: GPL v2 or later
 * Text Domain: article-tts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARTICLE_TTS_VERSION', '1.0.4' );
define( 'ARTICLE_TTS_FILE', __FILE__ );
define( 'ARTICLE_TTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ARTICLE_TTS_URL', plugin_dir_url( __FILE__ ) );
define( 'ARTICLE_TTS_OPTION_KEY', 'article_tts_options' );
define( 'ARTICLE_TTS_UPLOAD_SUBDIR', 'article-tts' );

// Auto-Updates aus dem GitHub-Repo.
if ( file_exists( ARTICLE_TTS_PATH . 'vendor/autoload.php' ) ) {
	require_once ARTICLE_TTS_PATH . 'vendor/autoload.php';

	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$article_tts_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/DEEP-CONTENT/article-tts/',
			__FILE__,
			'article-tts'
		);
		$article_tts_update_checker->setBranch( 'main' );
		$article_tts_update_checker->getVcsApi()->enableReleaseAssets();
	}
}

require_once ARTICLE_TTS_PATH . 'includes/class-client.php';
require_once ARTICLE_TTS_PATH . 'includes/class-voices.php';
require_once ARTICLE_TTS_PATH . 'includes/class-settings.php';
require_once ARTICLE_TTS_PATH . 'includes/class-generator.php';
require_once ARTICLE_TTS_PATH . 'includes/class-metabox.php';
require_once ARTICLE_TTS_PATH . 'includes/class-player.php';
require_once ARTICLE_TTS_PATH . 'includes/class-ajax.php';

class Article_TTS_Plugin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Article_TTS_Settings::get_instance();
		Article_TTS_Metabox::get_instance();
		Article_TTS_Player::get_instance();
		Article_TTS_Ajax::get_instance();
	}

	public static function get_options() {
		$defaults = self::get_default_options();
		$saved    = get_option( ARTICLE_TTS_OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	public static function get_default_options() {
		return array(
			// Address and token of the DC-IO instance. There is no sensible
			// default: the address is the customer's own tenant domain, and
			// tenancy is resolved from it — a wrong host means the token is
			// looked up in a foreign database and simply is not found.
			'api_base_url'      => '',
			'api_token'         => '',
			// Empty, not a curated id. Voices come from the connected instance
			// now, and a hard-coded default would point at a voice the customer
			// may not have.
			'default_voice_id'  => '',
			'model_id'          => '',
			'language'          => 'de',
			'enabled_post_types' => array( 'post' ),
			'player_position'   => 'before',
			'include_title'     => 1,
			'include_excerpt'   => 0,
			'player_label'      => '',
			'custom_css'        => '',
			'visibility_roles'  => array(),
		);
	}

	/**
	 * Resolve a voice ID to a human-readable label.
	 *
	 * Delegates to the catalogue the connected instance reports; there is no
	 * curated list in the plugin any more.
	 *
	 * @param string $voice_id Voice ID to look up.
	 * @return string
	 */
	public static function get_voice_label( $voice_id ) {
		return Article_TTS_Voices::voice_label( $voice_id );
	}

	/**
	 * Render the <option>/<optgroup> markup for a voice <select>.
	 *
	 * The caller is responsible for the surrounding <select name="…"> tag.
	 * Delegates to the catalogue of the connected instance; a stored value the
	 * catalogue no longer offers is kept rather than silently swapped for another
	 * voice.
	 *
	 * @param string $selected    Currently selected voice ID.
	 * @param string $empty_label Label for the first "no selection" option.
	 */
	public static function render_voice_options( $selected, $empty_label = '' ) {
		Article_TTS_Voices::render_options( $selected, $empty_label );
	}

	public static function activate() {
		$existing = get_option( ARTICLE_TTS_OPTION_KEY );
		if ( ! is_array( $existing ) ) {
			update_option( ARTICLE_TTS_OPTION_KEY, self::get_default_options() );
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . ARTICLE_TTS_UPLOAD_SUBDIR;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\n" );
		}
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}
}

register_activation_hook( __FILE__, array( 'Article_TTS_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'Article_TTS_Plugin', 'get_instance' ) );
