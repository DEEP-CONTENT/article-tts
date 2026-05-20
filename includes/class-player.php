<?php
/**
 * Frontend audio-player injection + shortcode.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Player {

	private static $instance = null;
	private $shortcode_used  = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Run after wpautop (priority 10) so injected inline markup isn't broken by auto-inserted <p> tags.
		add_filter( 'the_content', array( $this, 'inject_player' ), 20 );
		add_shortcode( 'article_audio', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	public function enqueue_styles() {
		if ( ! is_singular() ) {
			return;
		}
		$options = Article_TTS_Plugin::get_options();
		if ( ! in_array( get_post_type(), (array) $options['enabled_post_types'], true ) ) {
			return;
		}
		if ( ! get_post_meta( get_the_ID(), Article_TTS_Generator::META_URL, true ) ) {
			return;
		}
		wp_enqueue_style(
			'article-tts-frontend',
			ARTICLE_TTS_URL . 'assets/css/frontend.css',
			array(),
			ARTICLE_TTS_VERSION
		);
		wp_enqueue_script(
			'article-tts-frontend',
			ARTICLE_TTS_URL . 'assets/js/frontend.js',
			array(),
			ARTICLE_TTS_VERSION,
			true
		);

		if ( ! empty( $options['custom_css'] ) ) {
			wp_add_inline_style( 'article-tts-frontend', $options['custom_css'] );
		}
	}

	public function inject_player( $content ) {
		if ( is_admin() || ! in_the_loop() || ! is_singular() || ! is_main_query() ) {
			return $content;
		}
		$options = Article_TTS_Plugin::get_options();
		if ( 'manual' === $options['player_position'] ) {
			return $content;
		}
		if ( ! in_array( get_post_type(), (array) $options['enabled_post_types'], true ) ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}
		$url = get_post_meta( $post_id, Article_TTS_Generator::META_URL, true );
		if ( ! $url ) {
			return $content;
		}

		// Skip auto-injection if the shortcode was already used on this post.
		if ( $this->shortcode_used ) {
			return $content;
		}

		$player = $this->render_player( $url );

		switch ( $options['player_position'] ) {
			case 'after':
				return $content . $player;
			case 'both':
				return $player . $content . $player;
			case 'before':
			default:
				return $player . $content;
		}
	}

	public function shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$url = get_post_meta( $post_id, Article_TTS_Generator::META_URL, true );
		if ( ! $url ) {
			return '';
		}
		$this->shortcode_used = true;
		return $this->render_player( $url );
	}

	private function render_player( $url ) {
		$options       = Article_TTS_Plugin::get_options();
		$custom_label  = isset( $options['player_label'] ) ? trim( $options['player_label'] ) : '';
		$label         = '' !== $custom_label
			? esc_html( $custom_label )
			: esc_html__( 'Diesen Artikel anhören', 'article-tts' );
		$play_label  = esc_attr__( 'Wiedergabe starten', 'article-tts' );
		$pause_label = esc_attr__( 'Pause', 'article-tts' );
		$seek_label  = esc_attr__( 'Wiedergabe-Position', 'article-tts' );
		// Inline SVG icons keep us free of external dependencies and use currentColor for theming.
		$icon_play  = '<svg class="article-tts-player__icon article-tts-player__icon--play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
		$icon_pause = '<svg class="article-tts-player__icon article-tts-player__icon--pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>';

		return sprintf(
			'<figure class="article-tts-player" data-tts-player>'
				. '<figcaption class="article-tts-player__label">%1$s</figcaption>'
				. '<div class="article-tts-player__controls">'
					. '<button type="button" class="article-tts-player__play" aria-label="%2$s" data-play-label="%2$s" data-pause-label="%3$s">%4$s%5$s</button>'
					. '<div class="article-tts-player__progress" role="slider" tabindex="0" aria-label="%6$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
						. '<div class="article-tts-player__progress-fill"></div>'
					. '</div>'
					. '<span class="article-tts-player__time" aria-live="off">0:00 / 0:00</span>'
				. '</div>'
				. '<audio preload="metadata" src="%7$s"></audio>'
			. '</figure>',
			$label,
			$play_label,
			$pause_label,
			$icon_play,
			$icon_pause,
			$seek_label,
			esc_url( $url )
		);
	}
}
