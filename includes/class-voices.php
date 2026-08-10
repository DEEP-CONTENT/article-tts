<?php
/**
 * The voice and model catalogue, as DC-IO reports it.
 *
 * Replaces the hard-coded provider voice list. Two reasons it could not stay:
 * the ids belonged to one specific vendor, and a curated list in the plugin goes
 * stale the moment somebody adds a voice — every customer would need a plugin
 * update to see it.
 *
 * The catalogue is already scoped correctly when it arrives: DC-IO mints a token
 * for this installation's company, and the speech service returns global plus
 * own-company voices. Nothing has to be filtered here.
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

	/**
	 * Last known good catalogue, kept in the options table.
	 *
	 * A transient can vanish at any moment (object cache flush, eviction). If the
	 * connection is also down at that moment, an empty dropdown would make the
	 * editor believe their voices are gone. Stale names are a much smaller lie.
	 */
	const FALLBACK_OPTION = 'article_tts_catalog_fallback';

	/**
	 * Why the last catalogue request came back empty.
	 *
	 * An empty dropdown with no explanation is the worst thing this screen can
	 * do: address and token look filled in, nothing is marked red, and the only
	 * conclusion left is "the plugin is broken". It usually is not — the instance
	 * cannot be reached, the token is refused, or the API is not deployed there.
	 * Whichever it is, the answer already arrived; it was simply thrown away.
	 *
	 * @var WP_Error|null
	 */
	private static $last_error = null;

	public static function last_error() {
		return self::$last_error;
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
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$options = Article_TTS_Plugin::get_options();
		$client  = new Article_TTS_Client( $options['api_base_url'], $options['api_token'] );

		if ( ! $client->is_configured() ) {
			self::$last_error = new WP_Error(
				'article_tts_not_configured',
				__( 'Adresse und Zugangstoken sind noch nicht vollständig hinterlegt.', 'article-tts' )
			);

			return self::fallback( $kind );
		}

		$response = 'voices' === $kind ? $client->list_voices() : $client->list_models();

		if ( is_wp_error( $response ) ) {
			self::$last_error = $response;

			return self::fallback( $kind );
		}

		if ( ! isset( $response['items'] ) || ! is_array( $response['items'] ) ) {
			// A 200 that carries no catalogue. Rare, but it must not look like a
			// connection problem — the connection plainly worked.
			self::$last_error = new WP_Error(
				'article_tts_empty_catalog',
				__( 'Die Instanz hat geantwortet, aber keinen Stimmen-Katalog geliefert.', 'article-tts' )
			);

			return self::fallback( $kind );
		}

		self::$last_error = null;

		$items = array_values( $response['items'] );

		set_transient( $transient, $items, self::TTL );
		self::remember( $kind, $items );

		return $items;
	}

	private static function fallback( $kind ) {
		$stored = get_option( self::FALLBACK_OPTION, array() );

		return isset( $stored[ $kind ] ) && is_array( $stored[ $kind ] ) ? $stored[ $kind ] : array();
	}

	private static function remember( $kind, $items ) {
		$stored          = get_option( self::FALLBACK_OPTION, array() );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored[ $kind ] = $items;

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
