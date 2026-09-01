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

	/** admin-post-Aktion hinter „Katalog neu laden". */
	const ACTION_REFRESH = 'article_tts_refresh_catalog';

	/**
	 * Praefix des Einmal-Hinweises nach dem Neuladen, je Benutzer.
	 *
	 * Und ausdruecklich KEIN Parameter in der Adresse: Die Settings-API legt die
	 * aufgerufene Adresse als `_wp_http_referer` ins Formular und leitet nach
	 * dem Speichern genau dorthin zurueck. Ein `?article-tts-refreshed=1` klebte
	 * damit an jedem weiteren „Aenderungen speichern" und meldete ein Neuladen,
	 * das gar nicht stattgefunden hat.
	 */
	const REFRESH_NOTICE = 'article_tts_refresh_notice_';

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
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( $this, 'handle_refresh' ) );
	}

	/**
	 * „Katalog neu laden".
	 *
	 * Der Umweg ueber admin-post statt eines zweiten Knopfes im Formular: Das
	 * Formular der Seite gehoert der Settings-API und geht an options.php. Ein
	 * eigener Knopf darin wuerde entweder die Einstellungen mitspeichern oder ein
	 * Formular im Formular verlangen; beides ist schlechter als ein Link mit
	 * Nonce.
	 *
	 * Vorher gab es diesen Weg gar nicht: Der einzige Weg zu einer frischen Liste
	 * war „Aenderungen speichern", weil das nebenbei flush() ausloest. Wer das
	 * nicht wusste, wartete bis zu zwoelf Stunden auf eine gerade freigegebene
	 * Stimme.
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Dafür fehlen die Rechte.', 'article-tts' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_REFRESH );

		// Erst wegwerfen, dann holen. Das Holen allein genuegt nicht: Schlaegt es
		// fehl, laege der alte Transient noch da und die Seite zeigte danach
		// wieder die alte Liste — ohne ein Wort darueber, dass das Neuladen
		// nicht geklappt hat.
		Article_TTS_Voices::flush();
		Article_TTS_Voices::voices( true );
		Article_TTS_Voices::models( true );

		$failed = Article_TTS_Voices::last_error( 'voices' ) instanceof WP_Error
			|| Article_TTS_Voices::last_error( 'models' ) instanceof WP_Error;

		set_transient(
			self::REFRESH_NOTICE . get_current_user_id(),
			$failed ? 'error' : 'success',
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
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
				echo '<p>' . esc_html__( 'Adresse und Zugangstoken der Heise I/O-Instanz. Beispiel https://instanzname.platforms.heise-io.de/', 'article-tts' ) . '</p>';
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
				echo '<p>' . esc_html__( 'Wählen Sie die Standard-Stimme. Pro Artikel lässt sich diese in der Sidebar-Box des Post-Editors überschreiben.', 'article-tts' ) . '</p>';
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

		add_settings_field(
			'model_id',
			__( 'Modell', 'article-tts' ),
			array( $this, 'field_model' ),
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

		// Auf den GESPEICHERTEN Wert zurueckfallen, nicht auf den Default.
		//
		// Ein Feld, das das Formular nicht mitschickt, war frueher danach leer —
		// und genau das ist mit dem Modell passiert: es hatte keine Eingabe auf
		// der Seite, also hat jedes Speichern der Verbindungsdaten es geloescht.
		// Eine Vertonung ohne Modell wird abgelehnt, sichtbar nur als
		// "text_rejected" am Artikel. Fehlt ein Schluessel, heisst das "nicht
		// veraendert", nicht "geleert".
		$stored = get_option( ARTICLE_TTS_OPTION_KEY );
		$stored = is_array( $stored ) ? $stored : array();

		$out['model_id'] = isset( $input['model_id'] )
			? sanitize_text_field( $input['model_id'] )
			: (string) ( $stored['model_id'] ?? $defaults['model_id'] );

		$out['language'] = isset( $input['language'] )
			? sanitize_text_field( $input['language'] )
			: (string) ( $stored['language'] ?? $defaults['language'] );

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
			<?php $this->render_refresh_notice(); ?>
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

	/**
	 * Das Ergebnis von „Katalog neu laden", nach der Rueckleitung.
	 *
	 * Nur die Ueberschrift steht hier. Der GRUND eines Fehlschlags steht weiter
	 * unten an der Stimmenauswahl, wo er hingehoert — dorthin schaut, wer das
	 * Dropdown vermisst, und dort steht er auch ohne diesen Knopf.
	 */
	private function render_refresh_notice() {
		$key    = self::REFRESH_NOTICE . get_current_user_id();
		$result = get_transient( $key );

		if ( ! $result ) {
			return;
		}

		// Einmal gezeigt, dann weg — sonst stuende er beim naechsten Aufruf der
		// Seite erneut da, ohne dass jemand etwas neu geladen haette.
		delete_transient( $key );

		$ok = 'success' === $result;

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$ok ? 'success' : 'error',
			esc_html(
				$ok
					? __( 'Der Katalog wurde neu geladen.', 'article-tts' )
					: __( 'Der Katalog konnte nicht neu geladen werden — der Grund steht unten bei der Stimmenauswahl.', 'article-tts' )
			)
		);
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
		echo '<p class="description">' . esc_html__( 'Den Zugangstoken erhalten Sie von Heise I/O. Falls Ihnen keiner vorliegt, wenden Sie sich bitte dorthin.', 'article-tts' ) . '</p>';
	}

	public function field_default_voice() {
		$options = Article_TTS_Plugin::get_options();
		$this->render_voice_select(
			ARTICLE_TTS_OPTION_KEY . '[default_voice_id]',
			$options['default_voice_id']
		);
	}

	/**
	 * The model, which is NOT optional even though it looks like it.
	 *
	 * It used to have no field at all — the assumption was that an empty value
	 * lets the instance choose. It does not: a rendition without a model is
	 * rejected, and the only trace at the article is the word "text_rejected",
	 * which points at the text, where nothing is wrong.
	 *
	 * So it gets a field, and no empty option to fall into.
	 */
	public function field_model() {
		$options = Article_TTS_Plugin::get_options();
		$current = (string) $options['model_id'];
		$models  = Article_TTS_Voices::models();

		echo '<select name="' . esc_attr( ARTICLE_TTS_OPTION_KEY . '[model_id]' ) . '" class="regular-text">';

		if ( '' === $current ) {
			printf( '<option value="" selected>%s</option>', esc_html__( '— bitte auswählen —', 'article-tts' ) );
		}

		$known = array();
		foreach ( $models as $model ) {
			if ( ! isset( $model['slug'] ) ) {
				continue;
			}

			$known[] = $model['slug'];
			$label   = isset( $model['display_name'] ) ? (string) $model['display_name'] : (string) $model['slug'];

			// Which languages a model speaks decides whether it fits at all, and
			// the character ceiling decides how an article gets split.
			$languages = isset( $model['languages'] ) && is_array( $model['languages'] ) ? $model['languages'] : array();
			$fits      = empty( $languages ) || in_array( $options['language'], $languages, true );
			$max       = isset( $model['capabilities']['max_characters'] ) ? (int) $model['capabilities']['max_characters'] : 0;

			$suffix = array();
			if ( $max ) {
				/* translators: %s: formatted character count */
				$suffix[] = sprintf( __( 'bis %s Zeichen', 'article-tts' ), number_format_i18n( $max ) );
			}
			if ( ! $fits ) {
				/* translators: %s: language code */
				$suffix[] = sprintf( __( 'spricht kein %s', 'article-tts' ), strtoupper( $options['language'] ) );
			}

			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $model['slug'] ),
				selected( $current, $model['slug'], false ),
				esc_html( $suffix ? $label . ' (' . implode( ', ', $suffix ) . ')' : $label )
			);
		}

		// A stored model the catalogue no longer offers stays selectable rather
		// than silently switching the installation to a different one.
		if ( '' !== $current && ! in_array( $current, $known, true ) ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $current ),
				/* translators: %s: model id */
				esc_html( sprintf( __( 'Unbekanntes Modell (%s)', 'article-tts' ), $current ) )
			);
		}

		echo '</select>';

		// Der Modellfehler gehoert HIERHIN und nirgendwo sonst. Er wurde bisher
		// ueberhaupt nicht ausgegeben: `render_voice_select()` zeigt den Fehler
		// des STIMMEN-Katalogs, und „Katalog neu laden" zaehlt einen
		// Modellfehler mit, ohne ihn zeigen zu koennen. Der rote Kasten oben
		// verwies damit auf einen Grund, den die Seite verschwieg.
		$error = Article_TTS_Voices::last_error( 'models' );

		if ( $error instanceof WP_Error ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Modelle konnten nicht geladen werden.', 'article-tts' ),
				esc_html( $error->get_error_message() )
			);
		}

		if ( '' === $current ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Ohne Modell schlägt jede Vertonung fehl. Bitte eines auswählen und speichern.', 'article-tts' )
			);
		}
	}

	private function render_voice_select( $name, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '" class="regular-text">';
		Article_TTS_Plugin::render_voice_options( $selected );
		echo '</select> ';

		// Ein Link, kein Knopf: Dieses Feld wird INNERHALB des
		// Einstellungsformulars gerendert, ein Formular im Formular waere
		// kaputtes HTML. Mit `button`-Klasse sieht der Link aus wie einer.
		printf(
			'<a href="%1$s" class="button">%2$s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_REFRESH ), self::ACTION_REFRESH ) ),
			esc_html__( 'Katalog neu laden', 'article-tts' )
		);

		// Erst NACH dem Rendern der Optionen zu haben — dieser Aufruf ist es, der
		// den Katalog holt und deshalb auch erfaehrt, warum er leer blieb.
		$error = Article_TTS_Voices::last_error();
		$age   = Article_TTS_Voices::age_sentence();

		printf(
			'<p class="description">%1$s%2$s</p>',
			esc_html(
				sprintf(
					/* translators: %s: cache lifetime, e.g. "12 Stunden" */
					__( 'Der Katalog wird %s zwischengespeichert. Eine gerade erst freigegebene Stimme erscheint erst danach — oder sofort über diesen Knopf. Er benutzt die gespeicherten Zugangsdaten; frisch eingetippte also zuerst speichern.', 'article-tts' ),
					human_time_diff( 0, Article_TTS_Voices::TTL )
				)
			),
			'' !== $age ? ' ' . esc_html( $age ) : ''
		);

		if ( ! $error instanceof WP_Error ) {
			return;
		}

		printf(
			'<div class="notice notice-error inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Stimmen konnten nicht geladen werden.', 'article-tts' ),
			esc_html( $error->get_error_message() )
		);
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
