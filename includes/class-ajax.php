<?php
/**
 * Admin-only AJAX endpoints.
 *
 * Three actions now, because generation is no longer one call: hand over, ask
 * how far it is, and delete. The editor's browser drives the second one only for
 * as long as somebody is watching — the cron does it either way.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Ajax {

	/**
	 * How long a post is blocked from a second submit.
	 *
	 * Two clicks — or a double-click on a slow connection — used to be able to
	 * run two generations over the same post meta and delete each other's file
	 * through the cleanup in the generator. The lock is short enough that a
	 * genuinely failed submit can be retried straight away.
	 */
	const LOCK_SECONDS = 300;

	/**
	 * Wie lange der Abhol-Knopf gesperrt bleibt.
	 *
	 * Kurz genug, dass ein zweiter Versuch nicht nervt, lang genug, dass
	 * Doppelklicks und mehrere offene Editoren nicht zu einer Anfragewelle
	 * werden.
	 */
	const FETCH_LOCK_SECONDS = 15;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_article_tts_generate', array( $this, 'generate' ) );
		add_action( 'wp_ajax_article_tts_status', array( $this, 'status' ) );
		add_action( 'wp_ajax_article_tts_delete', array( $this, 'delete' ) );
		add_action( 'wp_ajax_article_tts_fetch_delivery', array( $this, 'fetch_delivery' ) );
	}

	private function check_nonce() {
		check_ajax_referer( 'article_tts_nonce', 'nonce' );
	}

	/**
	 * @return int Post id, or 0 when the caller may not touch it.
	 */
	private function authorised_post_id() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	private function lock_key( $post_id ) {
		return 'article_tts_lock_' . (int) $post_id;
	}

	public function generate() {
		$this->check_nonce();
		$post_id = $this->authorised_post_id();

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}

		if ( get_transient( $this->lock_key( $post_id ) ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Für diesen Beitrag läuft bereits eine Vertonung.', 'article-tts' ) ),
				409
			);
		}
		set_transient( $this->lock_key( $post_id ), 1, self::LOCK_SECONDS );

		// Optional override coming from the metabox UI before saving the post.
		if ( isset( $_POST['voice_override'] ) ) {
			$override = sanitize_text_field( wp_unslash( $_POST['voice_override'] ) );
			if ( '' === $override ) {
				delete_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE );
			} else {
				update_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE, $override );
			}
		}

		$result = Article_TTS_Generator::submit( $post_id );

		if ( is_wp_error( $result ) ) {
			// Release immediately: the submit did not start anything, and making
			// the editor wait five minutes to retry a typo would be absurd.
			delete_transient( $this->lock_key( $post_id ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'job_id'     => $result['job_id'],
				'job_status' => $result['job_status'],
				'chunks'     => (int) $result['chunks'],
				'replayed'   => (bool) $result['replayed'],
			)
		);
	}

	/**
	 * Where is it? Also the moment the finished audio is collected, so an editor
	 * who keeps the tab open sees the player without waiting for the next cron
	 * tick.
	 */
	public function status() {
		$this->check_nonce();
		$post_id = $this->authorised_post_id();

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}

		$status = Article_TTS_Generator::poll( $post_id );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ), 500 );
		}

		$url = '';

		if ( 'completed' === $status['job_status'] ) {
			$ingested = Article_TTS_Generator::ingest( $post_id );

			if ( is_wp_error( $ingested ) ) {
				// Not an error for the editor: the rendition exists and the cron
				// will fetch it. Reporting a failure here would be misleading.
				$status['job_status'] = 'fetching';
				update_post_meta( $post_id, Article_TTS_Generator::META_JOB_STATUS, 'fetching' );
			} else {
				delete_transient( $this->lock_key( $post_id ) );
				$url = $ingested['url'];
			}
		}

		if ( in_array( $status['job_status'], array( 'failed', 'expired' ), true ) ) {
			delete_transient( $this->lock_key( $post_id ) );
		}

		wp_send_json_success(
			array(
				'job_status' => $status['job_status'],
				'done'       => (int) $status['done'],
				'total'      => (int) $status['total'],
				'terminal'   => in_array( $status['job_status'], Article_TTS_Generator::TERMINAL, true ),
				'error'      => $status['error'],
				'url'        => $url,
				// Rendered here rather than assembled in JavaScript: the box shows
				// this line on page load too, and one wording is worth more than
				// two implementations of it.
				'statusHtml' => Article_TTS_Metabox::status_html( get_post( $post_id ) ),
			)
		);
	}

	/**
	 * Der Knopf „Jetzt von Heise I/O holen".
	 *
	 * Fragt den Posteingang sofort ab, statt auf den Cron zu warten. Die kurze
	 * Sperre ist kein Zierrat: Ohne sie werden aus Doppelklicks und drei offenen
	 * Editoren Lastspitzen auf einer Schnittstelle, die 240 Abfragen je Minute
	 * erlaubt.
	 */
	public function fetch_delivery() {
		$this->check_nonce();
		$post_id = $this->authorised_post_id();

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}

		$lock = 'article_tts_fetch_' . $post_id;

		if ( get_transient( $lock ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Gerade eben schon nachgesehen. Bitte einen Moment warten.', 'article-tts' ) ),
				429
			);
		}

		set_transient( $lock, 1, self::FETCH_LOCK_SECONDS );

		$result = Article_TTS_Deliveries::fetch_for( $post_id );

		if ( is_wp_error( $result ) ) {
			// Die Sperre sofort lösen: Ein Fehler auf der Gegenseite ist kein
			// Grund, den Redakteur eine halbe Minute warten zu lassen.
			delete_transient( $lock );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		wp_send_json_success(
			array(
				'state'      => $result['state'],
				'url'        => (string) get_post_meta( $post_id, Article_TTS_Generator::META_URL, true ),
				'statusHtml' => Article_TTS_Metabox::status_html( get_post( $post_id ) ),
			)
		);
	}

	public function delete() {
		$this->check_nonce();
		$post_id = $this->authorised_post_id();

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'article-tts' ) ), 403 );
		}

		Article_TTS_Generator::delete( $post_id );
		delete_transient( $this->lock_key( $post_id ) );

		wp_send_json_success(
			array( 'statusHtml' => Article_TTS_Metabox::status_html( get_post( $post_id ) ) )
		);
	}
}
