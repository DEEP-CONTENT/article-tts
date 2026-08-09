<?php
/**
 * Settings page for ElevenLabs TTS plugin.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Settings {

	const PAGE_SLUG     = 'article-tts';
	const SETTINGS_GROUP = 'article_tts_settings_group';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ARTICLE_TTS_FILE ), array( $this, 'add_action_links' ) );
	}

	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Einstellungen', 'article-tts' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function add_menu() {
		add_options_page(
			__( 'Heise I/O Article TTS', 'article-tts' ),
			__( 'Heise I/O Article TTS', 'article-tts' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
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
				),
			)
		);
	}

	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			ARTICLE_TTS_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Article_TTS_Plugin::get_default_options(),
			)
		);

		add_settings_section(
			'article_tts_api',
			__( 'Verbindung', 'article-tts' ),
			function () {
				echo '<p>' . esc_html__( 'Adresse und Zugangstoken der DC-IO-Instanz. Die Adresse muss die Domain sein, unter der euer Mandant erreichbar ist — an ihr wird der Zugang aufgelöst.', 'article-tts' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_base_url',
			__( 'Adresse', 'article-tts' ),
			array( $this, 'field_api_base_url' ),
			self::PAGE_SLUG,
			'article_tts_api'
		);

		add_settings_field(
			'api_token',
			__( 'Zugangstoken', 'article-tts' ),
			array( $this, 'field_api_token' ),
			self::PAGE_SLUG,
			'article_tts_api'
		);

		add_settings_section(
			'article_tts_voices',
			__( 'Stimme', 'article-tts' ),
			function () {
				echo '<p>' . esc_html__( 'Wähle die Standard-Stimme. Pro Artikel lässt sich diese in der Sidebar-Box des Post-Editors überschreiben.', 'article-tts' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'default_voice_id',
			__( 'Standard-Stimme', 'article-tts' ),
			array( $this, 'field_default_voice' ),
			self::PAGE_SLUG,
			'article_tts_voices'
		);

		add_settings_section(
			'article_tts_behaviour',
			__( 'Verhalten', 'article-tts' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled_post_types',
			__( 'Aktive Post-Typen', 'article-tts' ),
			array( $this, 'field_enabled_post_types' ),
			self::PAGE_SLUG,
			'article_tts_behaviour'
		);

		add_settings_field(
			'player_position',
			__( 'Position des Players', 'article-tts' ),
			array( $this, 'field_player_position' ),
			self::PAGE_SLUG,
			'article_tts_behaviour'
		);

		add_settings_field(
			'include_parts',
			__( 'Vorgelesene Bestandteile', 'article-tts' ),
			array( $this, 'field_include_parts' ),
			self::PAGE_SLUG,
			'article_tts_behaviour'
		);

		add_settings_field(
			'visibility_roles',
			__( 'Sichtbar für', 'article-tts' ),
			array( $this, 'field_visibility_roles' ),
			self::PAGE_SLUG,
			'article_tts_behaviour'
		);

		add_settings_section(
			'article_tts_appearance',
			__( 'Erscheinungsbild', 'article-tts' ),
			function () {
				echo '<p>' . esc_html__( 'Eigenes CSS für den Frontend-Player. Wird nach dem Standard-Stylesheet eingebunden, kann also alles überschreiben.', 'article-tts' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Verfügbare CSS-Klassen:', 'article-tts' ) . '</strong></p>';
				echo '<ul style="margin-left:1em;list-style:disc;">';
				echo '<li><code>.article-tts-player</code> — ' . esc_html__( 'Outer wrapper (figure)', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__label</code> — ' . esc_html__( 'Überschrift "Diesen Artikel anhören"', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__controls</code> — ' . esc_html__( 'Controls-Reihe (Play-Button + Progress + Zeit)', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__play</code> — ' . esc_html__( 'Play/Pause-Button', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__progress</code> — ' . esc_html__( 'Progress-Bar-Hintergrund', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__progress-fill</code> — ' . esc_html__( 'Progress-Bar-Füllung', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player__time</code> — ' . esc_html__( 'Zeit-Display (current / total)', 'article-tts' ) . '</li>';
				echo '<li><code>.article-tts-player.is-playing</code> — ' . esc_html__( 'Wird gesetzt, solange Audio läuft', 'article-tts' ) . '</li>';
				echo '</ul>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'player_label',
			__( 'Player-Überschrift', 'article-tts' ),
			array( $this, 'field_player_label' ),
			self::PAGE_SLUG,
			'article_tts_appearance'
		);

		add_settings_field(
			'custom_css',
			__( 'Eigenes CSS', 'article-tts' ),
			array( $this, 'field_custom_css' ),
			self::PAGE_SLUG,
			'article_tts_appearance'
		);
	}

	public function sanitize( $input ) {
		$defaults = Article_TTS_Plugin::get_default_options();
		$out      = $defaults;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		// esc_url_raw, nicht sanitize_text_field: die Adresse wird zur Basis jedes
		// Requests, und ein Tippfehler mit Leerzeichen oder Pfad soll hier
		// auffallen und nicht erst im Client.
		$out['api_base_url'] = isset( $input['api_base_url'] )
			? untrailingslashit( esc_url_raw( trim( (string) $input['api_base_url'] ) ) )
			: '';
		$out['api_token']    = isset( $input['api_token'] ) ? trim( sanitize_text_field( $input['api_token'] ) ) : '';

		$out['default_voice_id'] = isset( $input['default_voice_id'] ) ? sanitize_text_field( $input['default_voice_id'] ) : '';
		// Das Modell kommt jetzt aus dem Katalog der Instanz. Leer heisst: die
		// Gegenseite entscheidet — das ist der sinnvolle Default, weil sie weiss,
		// welche Modelle dort ueberhaupt aktiv sind.
		$out['model_id'] = isset( $input['model_id'] ) ? sanitize_text_field( $input['model_id'] ) : '';
		$out['language'] = isset( $input['language'] ) ? sanitize_text_field( $input['language'] ) : 'de';

		// Der Katalog haengt an der Verbindung: aendert sie sich, ist der
		// zwischengespeicherte Stand nicht mehr zustaendig.
		Article_TTS_Voices::flush();

		$out['enabled_post_types'] = array();
		if ( isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			$public = get_post_types( array( 'public' => true ) );
			foreach ( $input['enabled_post_types'] as $pt ) {
				$pt = sanitize_key( $pt );
				if ( isset( $public[ $pt ] ) ) {
					$out['enabled_post_types'][] = $pt;
				}
			}
		}
		if ( empty( $out['enabled_post_types'] ) ) {
			$out['enabled_post_types'] = array( 'post' );
		}

		$valid_positions        = array( 'before', 'after', 'both', 'manual' );
		$position               = isset( $input['player_position'] ) ? $input['player_position'] : 'before';
		$out['player_position'] = in_array( $position, $valid_positions, true ) ? $position : 'before';

		$out['include_title']   = ! empty( $input['include_title'] ) ? 1 : 0;
		$out['include_excerpt'] = ! empty( $input['include_excerpt'] ) ? 1 : 0;
		$out['player_label']    = isset( $input['player_label'] ) ? sanitize_text_field( $input['player_label'] ) : '';

		$out['visibility_roles'] = array();
		if ( isset( $input['visibility_roles'] ) && is_array( $input['visibility_roles'] ) ) {
			$valid_roles    = array_keys( wp_roles()->roles );
			$valid_roles[]  = 'guest';
			foreach ( $input['visibility_roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( in_array( $role, $valid_roles, true ) ) {
					$out['visibility_roles'][] = $role;
				}
			}
		}

		$css = isset( $input['custom_css'] ) ? (string) $input['custom_css'] : '';
		// Defuse closing style tags so the inline-style block can't be broken out of.
		$css = str_ireplace( '</style>', '<\/style>', $css );
		$out['custom_css'] = trim( $css );

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap article-tts-settings">
			<h1><?php esc_html_e( 'Heise I/O Article TTS — Einstellungen', 'article-tts' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function field_api_base_url() {
		$options = Article_TTS_Plugin::get_options();
		printf(
			'<input type="url" name="%1$s[api_base_url]" value="%2$s" class="regular-text" placeholder="https://kunde.example.de" />',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			esc_attr( $options['api_base_url'] )
		);
	}

	public function field_api_token() {
		$options = Article_TTS_Plugin::get_options();
		printf(
			'<input type="password" autocomplete="new-password" name="%1$s[api_token]" value="%2$s" class="regular-text" />',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			esc_attr( $options['api_token'] )
		);
		echo '<p class="description">' . esc_html__( 'Wird von eurem DC-IO-Administrator ausgestellt.', 'article-tts' ) . '</p>';
	}

	public function field_default_voice() {
		$options = Article_TTS_Plugin::get_options();
		$this->render_voice_select(
			ARTICLE_TTS_OPTION_KEY . '[default_voice_id]',
			$options['default_voice_id']
		);
	}

	private function render_voice_select( $name, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '" class="regular-text">';
		Article_TTS_Plugin::render_voice_options( $selected );
		echo '</select>';
	}

	public function field_enabled_post_types() {
		$options = Article_TTS_Plugin::get_options();
		$enabled = isset( $options['enabled_post_types'] ) ? (array) $options['enabled_post_types'] : array();
		$public  = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $public as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			printf(
				'<label style="margin-right:1em;"><input type="checkbox" name="%1$s[enabled_post_types][]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( ARTICLE_TTS_OPTION_KEY ),
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $enabled, true ), true, false ),
				esc_html( $pt->labels->singular_name )
			);
		}
	}

	public function field_player_position() {
		$options   = Article_TTS_Plugin::get_options();
		$positions = array(
			'before' => __( 'Über dem Artikel', 'article-tts' ),
			'after'  => __( 'Unter dem Artikel', 'article-tts' ),
			'both'   => __( 'Über und unter dem Artikel', 'article-tts' ),
			'manual' => __( 'Nur manuell via Shortcode [article_audio]', 'article-tts' ),
		);
		foreach ( $positions as $val => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s[player_position]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( ARTICLE_TTS_OPTION_KEY ),
				esc_attr( $val ),
				checked( $options['player_position'], $val, false ),
				esc_html( $label )
			);
		}
	}

	public function field_player_label() {
		$options = Article_TTS_Plugin::get_options();
		$value   = isset( $options['player_label'] ) ? $options['player_label'] : '';
		printf(
			'<input type="text" name="%1$s[player_label]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			esc_attr( $value ),
			esc_attr__( 'Diesen Artikel anhören', 'article-tts' )
		);
		echo '<p class="description">' . esc_html__( 'Überschrift über dem Player. Leer lassen, um den Standard zu verwenden.', 'article-tts' ) . '</p>';
	}

	public function field_custom_css() {
		$options = Article_TTS_Plugin::get_options();
		$value   = isset( $options['custom_css'] ) ? $options['custom_css'] : '';
		printf(
			'<textarea name="%1$s[custom_css]" rows="10" cols="60" spellcheck="false" style="font-family:Menlo,Consolas,monospace;font-size:12px;width:100%%;max-width:640px;" placeholder="%2$s">%3$s</textarea>',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			esc_attr__( ".article-tts-player { ... }\n.article-tts-player__label { ... }", 'article-tts' ),
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'Leer lassen, um die Standard-Darstellung zu nutzen.', 'article-tts' ) . '</p>';
	}

	public function field_visibility_roles() {
		$options = Article_TTS_Plugin::get_options();
		$enabled = isset( $options['visibility_roles'] ) ? (array) $options['visibility_roles'] : array();
		$roles   = wp_roles()->roles;

		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[visibility_roles][]" value="guest" %2$s /> %3$s</label>',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			checked( in_array( 'guest', $enabled, true ), true, false ),
			esc_html__( 'Gäste (nicht eingeloggt)', 'article-tts' )
		);

		foreach ( $roles as $slug => $role ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[visibility_roles][]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( ARTICLE_TTS_OPTION_KEY ),
				esc_attr( $slug ),
				checked( in_array( $slug, $enabled, true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}

		echo '<p class="description">' . esc_html__( 'Wenn nichts ausgewählt ist, sehen alle Besucher den Player (Standard). Sobald mindestens eine Auswahl gesetzt ist, wird der Player nur für die ausgewählten Gruppen ausgespielt.', 'article-tts' ) . '</p>';
	}

	public function field_include_parts() {
		$options = Article_TTS_Plugin::get_options();
		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[include_title]" value="1" %2$s /> %3$s</label>',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			checked( ! empty( $options['include_title'] ), true, false ),
			esc_html__( 'Titel mitsprechen', 'article-tts' )
		);
		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[include_excerpt]" value="1" %2$s /> %3$s</label>',
			esc_attr( ARTICLE_TTS_OPTION_KEY ),
			checked( ! empty( $options['include_excerpt'] ), true, false ),
			esc_html__( 'Excerpt mitsprechen (falls vorhanden)', 'article-tts' )
		);
		echo '<p class="description">' . esc_html__( 'Der Artikel-Body wird immer vorgelesen.', 'article-tts' ) . '</p>';
	}
}
