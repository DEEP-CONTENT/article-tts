<?php
/**
 * The background half of the asynchronous flow.
 *
 * THIS IS THE MANDATORY CHANNEL, not a fallback. The browser poll only exists so
 * somebody watching the editor sees progress; it dies with the tab. A rendition
 * takes minutes, and an editor who clicks "generate" and closes the window must
 * still end up with audio on the article.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Cron {

	const HOOK     = 'article_tts_poll_jobs';
	const SCHEDULE = 'article_tts_five_minutes';

	/**
	 * How many posts one tick looks at.
	 *
	 * WP-Cron runs inside a page request; a newsroom that renders thirty articles
	 * at once must not turn one visitor's page load into thirty API calls. The
	 * rest simply waits five minutes.
	 */
	const BATCH = 20;

	/**
	 * Give up after this long — the same hour the server gives a rendition.
	 *
	 * Not longer: a job the server has already abandoned would otherwise be
	 * polled forever. Not shorter either, or we stop asking about a job that is
	 * still legitimately running.
	 */
	const DEADLINE = 3600;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	public function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 300,
			'display'  => __( 'Alle 5 Minuten (Artikel-Vertonung)', 'article-tts' ),
		);
		return $schedules;
	}

	public function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * One tick: advance every rendition that is still running.
	 */
	public function run() {
		foreach ( $this->pending_posts() as $post_id ) {
			$this->advance( (int) $post_id );
		}
	}

	/**
	 * Posts with a job that has not reached a terminal state.
	 *
	 * @return int[]
	 */
	private function pending_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// Oldest first: a rendition that has been waiting longest is the
				// one somebody is most likely still waiting for.
				'orderby'        => 'meta_value_num',
				'meta_key'       => Article_TTS_Generator::META_JOB_START,
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => Article_TTS_Generator::META_JOB_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Article_TTS_Generator::META_JOB_STATUS,
						'value'   => Article_TTS_Generator::TERMINAL,
						'compare' => 'NOT IN',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Move one rendition forward by exactly one step.
	 *
	 * @param int $post_id
	 * @return string The state afterwards, for tests and WP-CLI.
	 */
	public function advance( $post_id ) {
		$started = (int) get_post_meta( $post_id, Article_TTS_Generator::META_JOB_START, true );

		if ( $started && ( time() - $started ) > self::DEADLINE ) {
			// The server has given up on it by now; asking again only produces a
			// 404 once its retention window closes.
			update_post_meta( $post_id, Article_TTS_Generator::META_JOB_STATUS, 'expired' );
			update_post_meta( $post_id, Article_TTS_Generator::META_JOB_ERROR, 'job_expired' );

			return 'expired';
		}

		$status = Article_TTS_Generator::poll( $post_id );

		if ( is_wp_error( $status ) ) {
			// A transient failure — DC-IO restarting, a network hiccup — must not
			// end the rendition. The deadline above is what ends it; until then
			// the next tick simply asks again.
			return 'error';
		}

		if ( 'completed' !== $status['job_status'] ) {
			return $status['job_status'];
		}

		$ingested = Article_TTS_Generator::ingest( $post_id );

		if ( is_wp_error( $ingested ) ) {
			// The rendition IS finished server-side; only the download failed.
			//
			// The status has to go BACK to a non-terminal value, or this post
			// drops out of the query above and the audio is never collected —
			// paid for, finished, and left on the server. `fetching` is the
			// honest name for the remaining work: the file still has to come
			// across. The next tick polls again and retries the download.
			update_post_meta( $post_id, Article_TTS_Generator::META_JOB_STATUS, 'fetching' );

			return 'download_failed';
		}

		return 'completed';
	}
}
