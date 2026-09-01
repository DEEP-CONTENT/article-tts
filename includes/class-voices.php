<?php
/**
 * The voice and model catalogue, as DC-IO reports it.
 *
 * Replaces the hard-coded provider voice list. Two reasons it could not stay:
 * the ids belonged to one specific vendor, and a curated list in the plugin goes
 * stale the moment somebody adds a voice — every customer would need a plugin
 * update to see it.
 *
 * Das Plugin filtert den Katalog nicht — und zwar weil es ihn nicht filtern
 * KANN. Wer welche Stimme hoeren darf, entscheiden zwei Stufen weiter oben:
 * io-tts liefert global plus eigene Firmenstimmen, DC-IO zieht davon noch die
 * Stimmen ab, die nur bestimmten Teams freigegeben sind. Was hier ankommt, ist
 * bereits die fertige Auswahl fuer genau diesen Zugang.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Voices {

	const VOICE_TRANSIENT = 'article_tts_voices';
	const MODEL_TRANSIENT = 'article_tts_models';

	/**
	 * Twelve hours. The catalogue changes when an operator syncs a provider, not
	 * when an editor opens a post — and every editor opening the editor would
	 * otherwise cost a round trip through two services.
	 */
	const TTL = 43200;

	/** Gerade eben geholt. */
	const SOURCE_FRESH = 'fresh';

	/** Aus dem Transient — hoechstens {@see TTL} alt. */
	const SOURCE_CACHE = 'cache';

	/** Der Abruf schlug fehl; gezeigt wird die zuletzt gelungene Liste. */
	const SOURCE_FALLBACK = 'fallback';

	/**
	 * Last known good catalogue, kept in the options table.
	 *
	 * A transient can vanish at any moment (object cache flush, eviction). If the
	 * connection is also down at that moment, an empty dropdown would make the
	 * editor believe their voices are gone. Stale names are a much smaller lie.
	 */
	const FALLBACK_OPTION = 'article_tts_catalog_fallback';

	/**
	 * Was der letzte Abruf ergeben hat — Grund und Herkunft, JE KATALOG.
	 *
	 * Ein leeres Dropdown ohne Erklaerung ist das Schlimmste, was dieser Schirm
	 * tun kann: Adresse und Token sehen ausgefuellt aus, nichts ist rot, und der
	 * einzige Schluss, der bleibt, ist „das Plugin ist kaputt". Meist ist es das
	 * nicht — die Instanz ist nicht erreichbar, der Token wird abgelehnt, oder
	 * die Schnittstelle ist dort nicht ausgerollt. Die Antwort ist jedes Mal
	 * schon da; sie wurde nur weggeworfen.
	 *
	 * JE KATALOG und nicht eine Variable fuer beide: Die Einstellungsseite holt
	 * erst die Stimmen, dann die Modelle. Mit einem gemeinsamen Feld haengt die
	 * Richtigkeit der Meldung an der Reihenfolge, in der die Felder gerendert
	 * werden — ein Modellfehler stuende sonst als Stimmenfehler da.
	 *
	 * @var array<string,array{error:WP_Error|null,source:string}>
	 */
	private static $state = array();

	/**
	 * @param string $kind 'voices' oder 'models'.
	 * @return WP_Error|null
	 */
	public static function last_error( $kind = 'voices' ) {
		return isset( self::$state[ $kind ]['error'] ) ? self::$state[ $kind ]['error'] : null;
	}

	/**
	 * Woher die zuletzt gelieferte Liste kam — eine der SOURCE_*-Konstanten.
	 *
	 * @param string $kind 'voices' oder 'models'.
	 * @return string Leer, solange in diesem Aufruf nichts geholt wurde.
	 */
	public static function served_from( $kind = 'voices' ) {
		return isset( self::$state[ $kind ]['source'] ) ? self::$state[ $kind ]['source'] : '';
	}

	/**
	 * Wann die gezeigte Liste zuletzt erfolgreich geholt wurde (UTC-Epoche).
	 *
	 * Gilt fuer BEIDE Wege: Transient und Options-Kopie werden im selben Zug
	 * geschrieben, der Zeitstempel gehoert also immer zu dem, was gerade zu
	 * sehen ist. 0 heisst „unbekannt" — nie eine Liste bekommen, oder eine aus
	 * der Zeit vor diesem Zeitstempel; dann ist sie ohnehin aelter als dieses
	 * Update.
	 *
	 * @param string $kind 'voices' oder 'models'.
	 * @return int
	 */
	public static function updated_at( $kind = 'voices' ) {
		$stored = get_option( self::FALLBACK_OPTION, array() );
		$key    = $kind . '_saved_at';

		return is_array( $stored ) && isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
	}

	/**
	 * „Stand: …" fuer die Liste, die gerade gezeigt wird.
	 *
	 * Der Satz, der diese ganze Suche erspart haette: Eine fuenf Tage alte Liste
	 * sah im Editor genauso aus wie eine frische. Leer, wenn nie eine Liste
	 * ankam — dann ist der Fehler die Nachricht und nicht das Alter.
	 *
	 * @param string $kind 'voices' oder 'models'.
	 * @return string
	 */
	public static function age_sentence( $kind = 'voices' ) {
		$saved_at = self::updated_at( $kind );

		if ( ! $saved_at ) {
			return '';
		}

		return sprintf(
			/* translators: 1: date and time of the last successful fetch, 2: human-readable age, e.g. "5 Tagen" */
			__( 'Stand: %1$s (vor %2$s).', 'article-tts' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $saved_at ),
			human_time_diff( $saved_at, time() )
		);
	}

	public static function voices( $force = false ) {
		return self::fetch( self::VOICE_TRANSIENT, 'voices', $force );
	}

	public static function models( $force = false ) {
		return self::fetch( self::MODEL_TRANSIENT, 'models', $force );
	}

	public static function flush() {
		delete_transient( self::VOICE_TRANSIENT );
		delete_transient( self::MODEL_TRANSIENT );
	}

	/**
	 * @param string $transient
	 * @param string $kind      'voices' or 'models'.
	 * @param bool   $force     Skip the cache (the settings screen's refresh).
	 * @return array List of catalogue entries; empty only when nothing is known.
	 */
	private static function fetch( $transient, $kind, $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( $transient );

			// `! empty()` und nicht nur `is_array()`: Vor der Regel weiter unten
			// konnte eine leere Liste in den Transient geraten, und die laege
			// dort noch bis zu zwoelf Stunden — ein Update, das den Fehler
			// abstellt, wuerde seine eigenen Altlasten weiter ausliefern. So
			// gilt ein leerer Eintrag als Fehlschlag des Zwischenspeichers und
			// der Abruf laeuft erneut.
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				self::note( $kind, null, self::SOURCE_CACHE );

				return $cached;
			}
		}

		$options = Article_TTS_Plugin::get_options();
		$client  = new Article_TTS_Client( $options['api_base_url'], $options['api_token'] );

		if ( ! $client->is_configured() ) {
			return self::failed(
				$kind,
				new WP_Error(
					'article_tts_not_configured',
					__( 'Adresse und Zugangstoken sind noch nicht vollständig hinterlegt.', 'article-tts' )
				)
			);
		}

		$response = 'voices' === $kind ? $client->list_voices() : $client->list_models();

		if ( is_wp_error( $response ) ) {
			return self::failed( $kind, $response );
		}

		if ( ! isset( $response['items'] ) || ! is_array( $response['items'] ) ) {
			// A 200 that carries no catalogue. Rare, but it must not look like a
			// connection problem — the connection plainly worked.
			return self::failed(
				$kind,
				new WP_Error(
					'article_tts_empty_catalog',
					sprintf(
						/* translators: %s: the catalogue's name, e.g. "Stimmen-Katalog" */
						__( 'Die Instanz hat geantwortet, aber keinen %s geliefert.', 'article-tts' ),
						self::kind_label( $kind )
					)
				)
			);
		}

		$items = array_values( $response['items'] );

		// EINE LEERE LISTE IST KEIN ERFOLG.
		//
		// Vorher lief sie glatt durch: `array()` ist ein Array, wurde zwoelf
		// Stunden in den Transient geschrieben UND ueberschrieb die Notkopie in
		// der Optionstabelle. Damit war die zuletzt gelungene Liste weg, das
		// Dropdown einen halben Tag leer — und weil kein Fehler vorlag, stand
		// nirgends ein Wort darueber.
		//
		// Ein Mandant ohne eine einzige Stimme ist theoretisch denkbar. Dann
		// bleibt das Dropdown ebenfalls leer, nur eben mit einem Satz dazu, und
		// die alte Liste ueberlebt. Das ist in beide Richtungen der guenstigere
		// Irrtum.
		if ( empty( $items ) ) {
			return self::failed(
				$kind,
				new WP_Error(
					'article_tts_no_entries',
					sprintf(
						/* translators: %s: the catalogue's name, e.g. "Stimmen-Katalog" */
						__( 'Die Instanz hat einen leeren %s geliefert. Angezeigt wird deshalb weiter der zuletzt bekannte Stand.', 'article-tts' ),
						self::kind_label( $kind )
					)
				)
			);
		}

		self::note( $kind, null, self::SOURCE_FRESH );

		set_transient( $transient, $items, self::TTL );
		self::remember( $kind, $items );

		return $items;
	}

	/**
	 * Der Fehlerweg: Grund merken, zuletzt gelungene Liste zeigen.
	 *
	 * Schreibt WEDER Transient NOCH Notkopie — ein misslungener Abruf darf einen
	 * gelungenen nicht ueberschreiben, und der naechste Seitenaufruf soll es
	 * erneut versuchen statt den Fehlschlag zwoelf Stunden festzuhalten.
	 *
	 * @return array
	 */
	private static function failed( $kind, WP_Error $error ) {
		self::note( $kind, $error, self::SOURCE_FALLBACK );

		return self::fallback( $kind );
	}

	private static function note( $kind, $error, $source ) {
		self::$state[ $kind ] = array(
			'error'  => $error,
			'source' => $source,
		);
	}

	/**
	 * Der Name des Katalogs in einer Meldung.
	 *
	 * `fetch()` bedient beide, die Meldung sagte aber immer „Stimmen-Katalog" —
	 * ein Modellfehler beschuldigte damit die Stimmen.
	 */
	private static function kind_label( $kind ) {
		return 'voices' === $kind
			? __( 'Stimmen-Katalog', 'article-tts' )
			: __( 'Modell-Katalog', 'article-tts' );
	}

	private static function fallback( $kind ) {
		$stored = get_option( self::FALLBACK_OPTION, array() );

		return isset( $stored[ $kind ] ) && is_array( $stored[ $kind ] ) ? $stored[ $kind ] : array();
	}

	private static function remember( $kind, $items ) {
		$stored          = get_option( self::FALLBACK_OPTION, array() );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored[ $kind ] = $items;

		// Der Zeitstempel ist der Grund, warum diese Kopie ueberhaupt lesbar ist.
		// Ohne ihn sieht eine fuenf Tage alte Liste aus wie eine frische, und
		// genau daran ist eine freigegebene Stimme tagelang unbemerkt gefehlt.
		//
		// time(), NICHT current_time(): eine UTC-Epoche, die wp_date() spaeter in
		// die Zeitzone der Seite rechnet. current_time() haette den Versatz schon
		// drin und wuerde ein zweites Mal verschoben.
		//
		// Eigener Schluessel je Katalog und neben den Daten, nicht darin: Der
		// Lesepfad greift unveraendert auf $stored[ $kind ] zu, alte Kopien ohne
		// Zeitstempel bleiben also gueltig und melden nur „unbekannt".
		$stored[ $kind . '_saved_at' ] = time();

		update_option( self::FALLBACK_OPTION, $stored, false );
	}

	/**
	 * Human-readable name for a voice id, for the metabox and the settings page.
	 */
	public static function voice_label( $voice_id ) {
		if ( '' === (string) $voice_id ) {
			return '—';
		}

		foreach ( self::voices() as $voice ) {
			if ( isset( $voice['id'] ) && $voice['id'] === $voice_id ) {
				return isset( $voice['name'] ) ? (string) $voice['name'] : (string) $voice_id;
			}
		}

		// A voice that vanished from the catalogue — shared away, deleted, or the
		// catalogue is simply unreachable right now. Showing the id beats showing
		// nothing: it is what the support case will be about.
		return sprintf(
			/* translators: %s: voice id */
			__( 'Unbekannte Stimme (%s)', 'article-tts' ),
			$voice_id
		);
	}

	/**
	 * Render <option> markup for a voice <select>. The caller owns the <select>.
	 *
	 * Grouped by language when the catalogue reports one — a German newsroom
	 * scrolling past forty English voices was the single most common complaint
	 * about the previous flat list.
	 */
	public static function render_options( $selected, $empty_label = '' ) {
		if ( '' === $empty_label ) {
			$empty_label = __( '— keine —', 'article-tts' );
		}

		printf( '<option value="">%s</option>', esc_html( $empty_label ) );

		$grouped = array();
		foreach ( self::voices() as $voice ) {
			if ( ! isset( $voice['id'] ) ) {
				continue;
			}

			$languages = isset( $voice['languages'] ) && is_array( $voice['languages'] ) ? $voice['languages'] : array();
			$group     = ! empty( $languages ) ? strtoupper( (string) $languages[0] ) : __( 'Weitere', 'article-tts' );

			$grouped[ $group ][] = $voice;
		}

		ksort( $grouped );

		$known = array();
		foreach ( $grouped as $group => $voices ) {
			usort(
				$voices,
				static function ( $a, $b ) {
					return strnatcasecmp(
						isset( $a['name'] ) ? $a['name'] : '',
						isset( $b['name'] ) ? $b['name'] : ''
					);
				}
			);

			printf( '<optgroup label="%s">', esc_attr( $group ) );
			foreach ( $voices as $voice ) {
				$known[] = $voice['id'];
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $voice['id'] ),
					selected( $selected, $voice['id'], false ),
					esc_html( isset( $voice['name'] ) ? $voice['name'] : $voice['id'] )
				);
			}
			echo '</optgroup>';
		}

		// Keep a stored selection that the catalogue no longer offers, rather
		// than silently switching the article to a different voice.
		if ( $selected && ! in_array( $selected, $known, true ) ) {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr( $selected ),
				esc_html( self::voice_label( $selected ) )
			);
		}
	}
}
