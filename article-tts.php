<?php
/**
 * Plugin Name: Heise I/O Article TTS
 * Description: Wandelt WordPress-Artikel über eine Text-to-Speech API in Audio um und zeigt einen HTML5-Player im Frontend.
 * Version: 1.0.1
 * Author: Deep Content by Heise
 * License: GPL v2 or later
 * Text Domain: article-tts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARTICLE_TTS_VERSION', '1.0.1' );
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

require_once ARTICLE_TTS_PATH . 'includes/class-api.php';
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
			'api_key'           => '',
			// Charlie (locker, Podcast/Conversational) — funktioniert mit eleven_multilingual_v2 auch auf DE.
			'default_voice_id'  => 'IKne3meq5aSn9XLyUdCD',
			'model_id'          => 'eleven_multilingual_v2',
			'enabled_post_types' => array( 'post' ),
			'player_position'   => 'before',
			'include_title'     => 1,
			'include_excerpt'   => 1,
			'player_label'      => '',
			'custom_css'        => '',
		);
	}

	/**
	 * Resolve a voice ID to a human-readable label.
	 *
	 * @param string $voice_id Voice ID to look up.
	 * @return string Voice name from the curated list, "Eigene Stimme" for
	 *                anything not in the list, or a dash for empty input.
	 */
	public static function get_voice_label( $voice_id ) {
		if ( '' === (string) $voice_id ) {
			return '—';
		}
		foreach ( Article_TTS_API::get_recommended_voices() as $voice ) {
			if ( $voice['id'] === $voice_id ) {
				return $voice['name'];
			}
		}
		return __( 'Eigene Stimme', 'article-tts' );
	}

	/**
	 * Render the <option>/<optgroup> markup for a voice <select>.
	 *
	 * The caller is responsible for the surrounding <select name="…"> tag.
	 * Only the curated/recommended voices are offered; any previously stored
	 * value not in that list is shown as a "Manuell:" fallback so it is not
	 * silently lost.
	 *
	 * @param string $selected    Currently selected voice ID.
	 * @param string $empty_label Label for the first "no selection" option.
	 */
	public static function render_voice_options( $selected, $empty_label = '' ) {
		if ( '' === $empty_label ) {
			$empty_label = __( '— keine —', 'article-tts' );
		}
		printf(
			'<option value="">%s</option>',
			esc_html( $empty_label )
		);

		$recommended = Article_TTS_API::get_recommended_voices();
		$labels      = Article_TTS_API::get_recommended_voice_group_labels();
		$rec_ids     = wp_list_pluck( $recommended, 'id' );

		$grouped = array();
		foreach ( $recommended as $voice ) {
			$grouped[ $voice['group'] ][] = $voice;
		}

		foreach ( $grouped as $group_key => $voices ) {
			usort(
				$voices,
				static function ( $a, $b ) {
					return strnatcasecmp( $a['name'], $b['name'] );
				}
			);
			$label = isset( $labels[ $group_key ] ) ? $labels[ $group_key ] : $group_key;
			printf( '<optgroup label="%s">', esc_attr( $label ) );
			foreach ( $voices as $voice ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $voice['id'] ),
					selected( $selected, $voice['id'], false ),
					esc_html( $voice['name'] )
				);
			}
			echo '</optgroup>';
		}

		if ( $selected && ! in_array( $selected, $rec_ids, true ) ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $selected ),
				esc_html__( 'Eigene Stimme', 'article-tts' )
			);
		}
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
