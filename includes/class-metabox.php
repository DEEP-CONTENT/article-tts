<?php
/**
 * Post-editor metabox for generating audio per article.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Metabox {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'save_post', array( $this, 'save_override' ) );
	}

	public function register() {
		$options = Article_TTS_Plugin::get_options();
		foreach ( (array) $options['enabled_post_types'] as $pt ) {
			add_meta_box(
				'article-tts-metabox',
				__( 'Audio-Version', 'article-tts' ),
				array( $this, 'render' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$options = Article_TTS_Plugin::get_options();
		if ( ! in_array( $screen->post_type, (array) $options['enabled_post_types'], true ) ) {
			return;
		}
		wp_enqueue_style(
			'article-tts-admin',
			ARTICLE_TTS_URL . 'assets/css/admin.css',
			array(),
			ARTICLE_TTS_VERSION
		);
		wp_enqueue_script(
			'article-tts-admin',
			ARTICLE_TTS_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ARTICLE_TTS_VERSION,
			true
		);
		wp_localize_script(
			'article-tts-admin',
			'articleTTS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'article_tts_nonce' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Audio-Datei dauerhaft löschen?', 'article-tts' ),
					'submitting'    => __( 'Übergebe Artikel…', 'article-tts' ),
					'generating'    => __( 'Vertonung läuft…', 'article-tts' ),
					/* translators: 1: finished sections, 2: total sections */
					'progress'      => __( 'Abschnitt %1$s von %2$s', 'article-tts' ),
					'background'    => __( 'Die Vertonung läuft im Hintergrund weiter. Das Audio erscheint automatisch, sobald es fertig ist.', 'article-tts' ),
					'deleting'      => __( 'Lösche…', 'article-tts' ),
					'failed'        => __( 'Fehler', 'article-tts' ),
					'success'       => __( 'Erfolgreich.', 'article-tts' ),
					'deleted'       => __( 'Audio gelöscht.', 'article-tts' ),
					'generate'      => __( 'Audio generieren', 'article-tts' ),
					'regenerate'    => __( 'Audio neu generieren', 'article-tts' ),
					/* translators: %s: technical reason, e.g. "HTTP 412" */
					'audioBlocked'  => __( 'Das Audio wurde erzeugt, ließ sich hier aber nicht laden (%s). Nach dem Speichern und Neuladen der Seite ist es abspielbar.', 'article-tts' ),
				),
			)
		);
	}

	/**
	 * The status line as a string, because two places need exactly the same one.
	 *
	 * The editor sees it rendered by PHP on load, and again the moment a rendition
	 * finishes — that second time over Ajax, without a reload. Building it in
	 * JavaScript instead would mean a second implementation of the wording, the
	 * size formatting and the legacy/stale rules, and the two would drift.
	 *
	 * @param WP_Post $post
	 * @return string Inner HTML of the status paragraph, already escaped.
	 */
	public static function status_html( $post ) {
		$generated = (int) get_post_meta( $post->ID, Article_TTS_Generator::META_GENERATED, true );

		if ( ! $generated ) {
			return '<em>' . esc_html__( 'Noch keine Audio-Version generiert.', 'article-tts' ) . '</em>';
		}

		$size      = (int) get_post_meta( $post->ID, Article_TTS_Generator::META_SIZE, true );
		$delivered = Article_TTS_Deliveries::is_delivered( $post->ID );

		// The formula lives in ONE place now. It used to be repeated here, which
		// is exactly how two copies of a hash drift apart.
		$stale  = Article_TTS_Generator::is_stale( $post->ID, $post );
		$legacy = ! $delivered
			&& (int) get_post_meta( $post->ID, Article_TTS_Generator::META_HASH_VERSION, true ) !== Article_TTS_Generator::HASH_VERSION;

		$html = '<strong>' . esc_html__( 'Status:', 'article-tts' ) . '</strong> ';

		$html .= sprintf(
			$delivered
				/* translators: 1: human-readable age, 2: file size */
				? esc_html__( 'Geliefert vor %1$s · %2$s', 'article-tts' )
				/* translators: 1: human-readable age, 2: file size */
				: esc_html__( 'Generiert vor %1$s · %2$s', 'article-tts' ),
			// time(), NOT current_time( 'timestamp' ). META_GENERATED is written
			// with time() — a UTC epoch — while current_time() adds the site's
			// offset on top. On a UTC+2 site a rendition that had just finished
			// therefore announced itself as "generiert vor 2 Stunden".
			esc_html( human_time_diff( $generated, time() ) ),
			esc_html( size_format( $size ) )
		);

		$html .= '<br><small>' . esc_html__( 'Stimme:', 'article-tts' ) . ' <strong>'
			. esc_html( self::voice_name( $post->ID, $delivered ) ) . '</strong></small>';

		if ( $delivered ) {
			$html .= '<br><span class="article-tts-note">' . esc_html( self::delivery_note( $post->ID ) ) . '</span>';
		} elseif ( $legacy ) {
			$html .= '<br><span class="article-tts-note">'
				. esc_html__( 'Diese Fassung stammt aus der früheren Anbindung. Sie bleibt spielbar; eine neue Vertonung ist nur nötig, wenn sich der Text geändert hat.', 'article-tts' )
				. '</span>';
		} elseif ( $stale ) {
			$html .= '<br><span class="article-tts-stale">'
				. esc_html__( 'Artikel wurde nach der Audio-Generierung verändert — neu generieren empfohlen.', 'article-tts' )
				. '</span>';
		}

		return $html;
	}

	/**
	 * Der Stimmenname, aus der Quelle, die ihn tatsächlich hat.
	 *
	 * Eine erzeugte Fassung merkt sich eine Stimmen-KENNUNG und lässt sie durch
	 * den Katalog auflösen. Eine gelieferte bringt einen NAMEN mit und keine
	 * Kennung. Durch den Katalog geschlagen ergäbe er „Unbekannte Stimme
	 * (Otto)", also eine Warnung über etwas völlig Normales.
	 *
	 * @param int  $post_id
	 * @param bool $delivered
	 * @return string
	 */
	private static function voice_name( $post_id, $delivered ) {
		if ( $delivered ) {
			$label = (string) get_post_meta( $post_id, Article_TTS_Deliveries::META_VOICE_LABEL, true );

			return '' !== $label ? $label : __( 'Keine Angabe', 'article-tts' );
		}

		return Article_TTS_Plugin::get_voice_label(
			get_post_meta( $post_id, Article_TTS_Generator::META_VOICE, true )
		);
	}

	/**
	 * Was an einer gelieferten Fassung gesagt werden muss.
	 *
	 * ZWEI SÄTZE, und der zweite nur, wenn er zutrifft. Der erste erklärt, warum
	 * hier nie „Artikel wurde verändert" steht: diese Fassung folgt dem
	 * Artikeltext nicht (§6.2). Der zweite ist der wichtigere: Hat die
	 * Zustellung eine im Editor erzeugte Fassung ersetzt, hat der Beitrag eine
	 * Eigenschaft VERLOREN, und das darf nicht unbemerkt umschlagen (§6.5).
	 *
	 * @param int $post_id
	 * @return string
	 */
	private static function delivery_note( $post_id ) {
		$note = __( 'Diese Fassung kommt aus DC-IO und folgt nicht dem Artikeltext.', 'article-tts' );

		$replaced = (int) get_post_meta( $post_id, Article_TTS_Deliveries::META_REPLACED_AT, true );

		if ( $replaced ) {
			$note .= ' ' . sprintf(
				/* translators: %s: date the delivery replaced a generated version */
				__( 'Sie hat am %s eine im Editor erzeugte Fassung ersetzt.', 'article-tts' ),
				// date_i18n() und nicht date(): der Zeitstempel ist eine
				// UTC-Epoche, die Anzeige gehört in die Zeitzone der Seite.
				date_i18n( 'd.m.Y', $replaced )
			);
		}

		return $note;
	}

	/**
	 * Turn the service's error category into something an editor can act on.
	 *
	 * The categories are an API vocabulary, not a message. "text_rejected" in
	 * particular sends everyone to inspect the article text, where nothing is
	 * wrong — the usual cause is a voice the instance does not have, left over
	 * from the previous provider.
	 *
	 * An unknown category still prints: a new one on the far side must not turn
	 * into an empty warning box.
	 *
	 * @param string $category
	 * @return string
	 */
	public static function error_sentence( $category ) {
		switch ( $category ) {
			case 'text_rejected':
				return __( 'Die letzte Vertonung wurde abgelehnt. Meist liegt es an der eingestellten Stimme — bitte in den Einstellungen prüfen, ob sie noch angeboten wird.', 'article-tts' );
			case 'upstream_unreachable':
				return __( 'Der Sprachdienst war nicht erreichbar. Ein erneuter Versuch lohnt sich.', 'article-tts' );
			case 'upstream_timeout':
				return __( 'Der Sprachdienst hat zu lange gebraucht. Ein erneuter Versuch lohnt sich.', 'article-tts' );
			case 'upstream_failed':
				return __( 'Der Sprachdienst konnte den Artikel nicht vertonen. Bitte erneut versuchen; bleibt es dabei, wenden Sie sich an Heise I/O.', 'article-tts' );
			case 'compose_failed':
				return __( 'Die Teile des Artikels ließen sich nicht zu einer Datei zusammenfügen. Bitte erneut versuchen.', 'article-tts' );
			case 'job_expired':
				return __( 'Die Vertonung wurde nicht rechtzeitig fertig und ist abgelaufen. Bitte erneut starten.', 'article-tts' );
			case 'internal':
				return __( 'Bei der Vertonung ist ein interner Fehler aufgetreten. Bitte erneut versuchen; bleibt es dabei, wenden Sie sich an Heise I/O.', 'article-tts' );
		}

		return sprintf(
			/* translators: %s: error category reported by the service */
			__( 'Die letzte Vertonung ist fehlgeschlagen (%s).', 'article-tts' ),
			$category
		);
	}

	public function render( $post ) {
		$options    = Article_TTS_Plugin::get_options();
		$api_ready  = '' !== $options['api_base_url'] && '' !== $options['api_token'];
		$generated  = (int) get_post_meta( $post->ID, Article_TTS_Generator::META_GENERATED, true );
		$url        = get_post_meta( $post->ID, Article_TTS_Generator::META_URL, true );
		$override   = get_post_meta( $post->ID, Article_TTS_Generator::META_OVERRIDE, true );

		// An article that was never saved has no content in the database — and
		// build_text() reads from there, not from the editor. Rendering it would
		// either fail as "empty text" or, worse, vertone a stale revision.
		$unsaved    = in_array( $post->post_status, array( 'auto-draft' ), true );

		$job_status = (string) get_post_meta( $post->ID, Article_TTS_Generator::META_JOB_STATUS, true );
		$job_error  = (string) get_post_meta( $post->ID, Article_TTS_Generator::META_JOB_ERROR, true );
		$running    = '' !== $job_status && ! in_array( $job_status, Article_TTS_Generator::TERMINAL, true );

		wp_nonce_field( 'article_tts_metabox', 'article_tts_metabox_nonce' );
		?>
		<div class="article-tts-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-job-pending="<?php echo $running ? '1' : '0'; ?>">
			<?php if ( ! $api_ready ) : ?>
				<p class="article-tts-warning">
					<?php
					printf(
						/* translators: %s: settings link */
						wp_kses_post( __( 'Kein API-Key konfiguriert. Bitte unter %s eintragen.', 'article-tts' ) ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=' . Article_TTS_Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Einstellungen → Heise I/O Article TTS', 'article-tts' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_html() escapes every part it assembles. ?>
			<p class="article-tts-status"><?php echo self::status_html( $post ); ?></p>

			<?php if ( $unsaved ) : ?>
				<p class="article-tts-warning">
					<?php esc_html_e( 'Bitte den Beitrag zuerst speichern — vertont wird der gespeicherte Text, nicht der im Editor.', 'article-tts' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $running ) : ?>
				<p class="article-tts-running">
					<?php esc_html_e( 'Vertonung läuft — das Audio erscheint automatisch, auch wenn Sie diese Seite schließen.', 'article-tts' ); ?>
				</p>
			<?php elseif ( '' !== $job_error ) : ?>
				<p class="article-tts-warning">
					<?php echo esc_html( self::error_sentence( $job_error ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $url ) : ?>
				<audio controls preload="metadata" src="<?php echo esc_url( $url ); ?>" style="width:100%;"></audio>
			<?php endif; ?>

			<p>
				<label for="article-tts-voice-override"><strong><?php esc_html_e( 'Stimme (optional, überschreibt Standard):', 'article-tts' ); ?></strong></label><br>
				<select id="article-tts-voice-override" name="article_tts_voice_override">
					<?php Article_TTS_Plugin::render_voice_options( $override, __( '— Standard verwenden —', 'article-tts' ) ); ?>
				</select>
			</p>

			<p class="article-tts-actions">
				<?php
				// Disabled while something is running: a second submit would be a
				// second rendition on the invoice for the same article.
				$label = __( 'Audio generieren', 'article-tts' );
				if ( '' !== $job_error && ! $generated ) {
					$label = __( 'Erneut vertonen', 'article-tts' );
				} elseif ( $generated ) {
					$label = __( 'Audio neu generieren', 'article-tts' );
				}
				?>
				<button type="button" class="button button-primary" id="article-tts-generate" <?php disabled( ! $api_ready || $running || $unsaved ); ?>>
					<?php echo esc_html( $label ); ?>
				</button>
				<?php if ( $generated ) : ?>
					<button type="button" class="button" id="article-tts-delete"><?php esc_html_e( 'Löschen', 'article-tts' ); ?></button>
				<?php endif; ?>
			</p>
			<p class="article-tts-feedback" aria-live="polite"></p>
		</div>
		<?php
	}

	public function save_override( $post_id ) {
		if ( ! isset( $_POST['article_tts_metabox_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['article_tts_metabox_nonce'] ) ), 'article_tts_metabox' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$override = isset( $_POST['article_tts_voice_override'] ) ? sanitize_text_field( wp_unslash( $_POST['article_tts_voice_override'] ) ) : '';
		if ( '' === $override ) {
			delete_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE );
		} else {
			update_post_meta( $post_id, Article_TTS_Generator::META_OVERRIDE, $override );
		}
	}
}
