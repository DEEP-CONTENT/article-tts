<?php
/**
 * Admin-only AJAX endpoints for ElevenLabs TTS plugin.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Ajax {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_article_tts_generate', array( $this, 'generate' ) );
		add_action( 'wp_ajax_article_tts_delete', array( $this, 'delete' ) );
	}

	private function check_nonce() {
		check_ajax_referer( 'article_tts_nonce', 'nonce' );
	}

	public function generate() {
		$this->check_nonce();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}

		// Optional override coming from the metabox UI before saving the post.
		if ( isset( $_POST['voice_override'] ) ) {
			$override = sanitize_text_field( wp_unslash( $_POST['voice_override'] ) );
			if ( '' === $override ) {
				delete_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE );
			} else {
				update_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE, $override );
			}
		}

		$result = Article_TTS_Generator::generate( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'url'           => $result['url'],
				'voice'         => $result['voice'],
				'generated'     => $result['generated'],
				'generated_iso' => date_i18n( 'c', $result['generated'] ),
				'size'          => $result['size'],
				'size_human'    => size_format( $result['size'] ),
				'skipped'       => ! empty( $result['skipped'] ),
			)
		);
	}

	public function delete() {
		$this->check_nonce();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}
		Article_TTS_Generator::delete( $post_id );
		wp_send_json_success();
	}
}
