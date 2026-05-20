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
					'generating'    => __( 'Generiere Audio…', 'article-tts' ),
					'deleting'      => __( 'Lösche…', 'article-tts' ),
					'failed'        => __( 'Fehler', 'article-tts' ),
					'success'       => __( 'Erfolgreich.', 'article-tts' ),
					'skipped'       => __( 'Audio ist bereits aktuell — keine neue API-Anfrage.', 'article-tts' ),
				),
			)
		);
	}

	public function render( $post ) {
		$options    = Article_TTS_Plugin::get_options();
		$api_ready  = ! empty( $options['api_key'] );
		$generated  = (int) get_post_meta( $post->ID, Article_TTS_Generator::META_GENERATED, true );
		$url        = get_post_meta( $post->ID, Article_TTS_Generator::META_URL, true );
		$voice      = get_post_meta( $post->ID, Article_TTS_Generator::META_VOICE, true );
		$hash       = get_post_meta( $post->ID, Article_TTS_Generator::META_HASH, true );
		$size       = (int) get_post_meta( $post->ID, Article_TTS_Generator::META_SIZE, true );
		$override   = get_post_meta( $post->ID, Article_TTS_Generator::META_OVERRIDE, true );
		$resolved   = Article_TTS_Generator::resolve_voice_id( $post->ID, $options );
		$current_hash = '';
		if ( $generated ) {
			$current_hash = md5( $resolved . '|' . $options['model_id'] . '|' . Article_TTS_Generator::build_text( $post, $options ) );
		}
		$stale = $generated && $hash && $current_hash && $hash !== $current_hash;

		wp_nonce_field( 'article_tts_metabox', 'article_tts_metabox_nonce' );
		?>
		<div class="article-tts-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
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

			<p class="article-tts-status">
				<?php if ( $generated ) : ?>
					<strong><?php esc_html_e( 'Status:', 'article-tts' ); ?></strong>
					<?php
					printf(
						/* translators: 1: human-readable date, 2: file size */
						esc_html__( 'Generiert vor %1$s · %2$s', 'article-tts' ),
						esc_html( human_time_diff( $generated, current_time( 'timestamp' ) ) ),
						esc_html( size_format( $size ) )
					);
					?>
					<br>
					<small><?php esc_html_e( 'Stimme:', 'article-tts' ); ?> <strong><?php echo esc_html( Article_TTS_Plugin::get_voice_label( $voice ) ); ?></strong></small>
					<?php if ( $stale ) : ?>
						<br><span class="article-tts-stale"><?php esc_html_e( 'Artikel wurde nach der Audio-Generierung verändert — neu generieren empfohlen.', 'article-tts' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<em><?php esc_html_e( 'Noch keine Audio-Version generiert.', 'article-tts' ); ?></em>
				<?php endif; ?>
			</p>

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
				<button type="button" class="button button-primary" id="article-tts-generate" <?php disabled( ! $api_ready ); ?>>
					<?php echo $generated ? esc_html__( 'Audio neu generieren', 'article-tts' ) : esc_html__( 'Audio generieren', 'article-tts' ); ?>
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
