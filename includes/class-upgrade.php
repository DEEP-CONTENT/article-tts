<?php
/**
 * Carries an existing installation across the provider change.
 *
 * This plugin updates itself from a public repository, without anyone pressing
 * anything. The release that moves synthesis to DC-IO therefore arrives on live
 * sites unannounced — and lands on a configuration that no longer fits: the old
 * provider key is meaningless, address and token are empty, and nothing explains
 * why the button stopped working.
 *
 * Everything here exists to make that morning uneventful.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Upgrade {

	/**
	 * Migration state, deliberately NOT the plugin version.
	 *
	 * The two change for different reasons — a release with no data change must
	 * not re-run migrations, and a data change inside one release must be able to
	 * run. Bump this only when a step is added.
	 */
	const DB_VERSION_OPTION = 'article_tts_db_version';
	const DB_VERSION        = 3;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// admin_init, not plugins_loaded: the work touches options and post meta,
		// and there is no reason for a visitor's page load to pay for it.
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function maybe_upgrade() {
		$from = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $from >= self::DB_VERSION ) {
			return;
		}

		// A site that never had this plugin needs no migration — only the marker,
		// so the steps below never look at it again.
		if ( 0 === $from && ! $this->looks_like_existing_installation() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
			return;
		}

		if ( $from < 2 ) {
			$this->mark_legacy_audio();
			$this->drop_provider_key();
		}

		if ( $from < 3 ) {
			$this->drop_provider_model();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Did this site ever generate audio, or hold a configuration?
	 */
	private function looks_like_existing_installation() {
		if ( is_array( get_option( ARTICLE_TTS_OPTION_KEY ) ) ) {
			return true;
		}

		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
				Article_TTS_Generator::META_HASH
			)
		);
	}

	/**
	 * Stamp every already-generated article as version 1 of the hash formula.
	 *
	 * THE MOST IMPORTANT STEP. The old formula was an md5 over voice, model and
	 * text; the new one is a sha256 with a version prefix. Without this marker
	 * the editor compares the two, finds them different, and reports the ENTIRE
	 * back catalogue as "changed since generation" — inviting a paid
	 * re-rendering of every article a customer ever published.
	 *
	 * {@see Article_TTS_Generator::is_stale()} only ever compares within one
	 * version, so a stamped article is simply older, never stale.
	 *
	 * One statement rather than a loop: a newsroom can have thousands of these,
	 * and this runs inside an ordinary admin request.
	 */
	private function mark_legacy_audio() {
		global $wpdb;

		// LEFT JOIN rather than NOT IN: idempotent, and it survives being run
		// twice without writing a duplicate meta row.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 SELECT h.post_id, %s, %s
				 FROM {$wpdb->postmeta} h
				 LEFT JOIN {$wpdb->postmeta} v
				   ON v.post_id = h.post_id AND v.meta_key = %s
				 WHERE h.meta_key = %s AND v.meta_id IS NULL",
				Article_TTS_Generator::META_HASH_VERSION,
				'1',
				Article_TTS_Generator::META_HASH_VERSION,
				Article_TTS_Generator::META_HASH
			)
		);

		// The meta cache is deliberately NOT flushed. A stale entry here can only
		// mean "no version stamp yet", and a missing stamp makes is_stale() answer
		// false — the harmless direction. Flushing a persistent object cache to
		// avoid a wrong "up to date" would cost far more than it saves.
	}

	/**
	 * Remove the old provider key.
	 *
	 * It cannot be used any more, and it sits in an autoloaded option in plain
	 * text. Reads go through get_options(), which merges over the defaults, so
	 * this operates on the RAW stored array — otherwise the removal would be
	 * undone by the very next save.
	 */
	private function drop_provider_key() {
		$stored = get_option( ARTICLE_TTS_OPTION_KEY );

		if ( ! is_array( $stored ) || ! array_key_exists( 'api_key', $stored ) ) {
			return;
		}

		unset( $stored['api_key'] );
		update_option( ARTICLE_TTS_OPTION_KEY, $stored );
	}

	/**
	 * Remove the old provider's model id.
	 *
	 * A model belongs to one vendor. `eleven_multilingual_v2` means nothing to
	 * io-tts, which rejects the segment — the rendition fails after the job has
	 * been created, and the settings screen offers no field to correct it,
	 * because the model now comes from the instance's catalogue.
	 *
	 * Empty is not a gap here: it means "the instance decides", which is the
	 * right answer, since it knows which models it actually has.
	 *
	 * The VOICE is deliberately left alone. Clearing it would silently switch
	 * every article to a different speaker; a wrong one is refused before
	 * anything is submitted instead {@see Article_TTS_Generator::submit()}.
	 */
	private function drop_provider_model() {
		$stored = get_option( ARTICLE_TTS_OPTION_KEY );

		if ( ! is_array( $stored ) || ! isset( $stored['model_id'] ) || '' === $stored['model_id'] ) {
			return;
		}

		// Anything from the io-tts catalogue is prefixed with its provider —
		// `elevenlabs-eleven-multilingual-v2`. The provider-era ids never were.
		if ( false !== strpos( (string) $stored['model_id'], '-' ) ) {
			return;
		}

		$stored['model_id'] = '';
		update_option( ARTICLE_TTS_OPTION_KEY, $stored );
	}

	/**
	 * Say what happened, and what to do about it.
	 *
	 * Not dismissible: the plugin cannot vertonen until this is answered, and a
	 * notice somebody clicked away is a support case three weeks later. It
	 * disappears by itself the moment address and token are set.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = Article_TTS_Plugin::get_options();

		if ( '' !== $options['api_base_url'] && '' !== $options['api_token'] ) {
			return;
		}

		// Only where it is actionable — a notice on every screen of the admin is
		// noise, not information.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$relevant = array( 'plugins', 'dashboard', 'settings_page_' . Article_TTS_Settings::PAGE_SLUG );

		if ( $screen && ! in_array( $screen->id, $relevant, true ) && ! in_array( $screen->base, array( 'post' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'Heise I/O Article TTS:', 'article-tts' ),
			esc_html__( 'Die Vertonung läuft jetzt über Heise I/O. Bis Adresse und Zugangstoken eingetragen sind, lassen sich keine neuen Audios erzeugen — bereits erzeugte bleiben unverändert abrufbar.', 'article-tts' ),
			sprintf(
				'<a href="%1$s" class="button button-primary">%2$s</a>',
				esc_url( admin_url( 'options-general.php?page=' . Article_TTS_Settings::PAGE_SLUG ) ),
				esc_html__( 'Jetzt einrichten', 'article-tts' )
			)
		);
	}
}
