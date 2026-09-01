<?php
/**
 * Der Posteingang: Vertonungen, die in DC-IO erzeugt und hierher geschickt wurden.
 *
 * ANDERSHERUM ALS DER REST DES PLUGINS. Bei der Artikelvertonung schickt diese
 * Installation einen Text weg und holt sich das Audio ab; der Auslöser sitzt
 * hier. Eine Zustellung entsteht in DC-IO, an einem Text, der in WordPress nie
 * stand. Beide enden am selben Ort, der Audiofassung eines Artikels, und
 * teilen sich Player, Meta-Schlüssel und Upload-Verzeichnis.
 *
 * DC-IO SCHIEBT NICHT, DIESE SEITE HOLT. Es gibt keinen eingehenden Endpunkt,
 * keine Zugangsdaten für uns und nichts, was von außen erreichbar sein müsste.
 * Der Preis ist Latenz bis zu einem Cron-Intervall, also fünf Minuten.
 *
 * DIE EINE STELLE, DIE STIMMEN MUSS, ist {@see host_matches()}. Der Posteingang
 * ist mandantenweit: er enthält auch die Zustellungen anderer Installationen
 * desselben Kunden. Was nicht zur eigenen Domain gehört, wird nicht angefasst,
 * nicht heruntergeladen und NICHT GEMELDET. Eine Meldung nähme die Zustellung
 * dem, für den sie bestimmt war.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Deliveries {

	/**
	 * Woher die Fassung an diesem Beitrag stammt.
	 *
	 * Nur `dcio` wird je geschrieben; das Fehlen des Eintrags heißt „im Editor
	 * erzeugt". So bleibt jeder bereits vertonte Artikel unangetastet, ohne dass
	 * irgendwo nachgetragen werden müsste.
	 */
	const META_SOURCE = '_article_tts_source';
	const SOURCE_DCIO = 'dcio';

	/** Die Kennung der Zustellung, aus der diese Fassung stammt. */
	const META_DELIVERY = '_article_tts_delivery_id';

	/**
	 * Die Stimme als KLARTEXT, nicht als Kennung.
	 *
	 * Eine Zustellung bringt einen Namen mit („Otto"), keine Stimmen-ID. In
	 * META_VOICE gehört sie deshalb nicht: dort steht eine Kennung, die durch den
	 * Katalog geschlagen wird, und ein Name fände sich dort nie. Die Box zeigte
	 * „Unbekannte Stimme (Otto)".
	 */
	const META_VOICE_LABEL = '_article_tts_source_voice';

	/**
	 * Der Name des Projekts in Heise I/O, aus dem diese Fassung stammt.
	 *
	 * Sagt einem Redakteur mehr als der Dateiname, der vorher an dieser Stelle
	 * stand: `post-43271-dS1ZW35PW.mp3` benennt nur, wo die Datei liegt, „Hallo
	 * Test" benennt, WORAUS sie kommt — und ist damit das, womit sich die Fassung
	 * drüben im Editor wiederfinden lässt.
	 *
	 * Nur an einer gelieferten Fassung sinnvoll. Eine selbst erzeugte hat kein
	 * Projekt, sie hat den Artikel.
	 */
	const META_PROJECT = '_article_tts_source_project';

	/**
	 * Wann eine gelieferte Fassung eine im Editor erzeugte ersetzt hat.
	 *
	 * Der Beitrag verliert dabei eine Eigenschaft: eine erzeugte Fassung folgt
	 * dem Artikeltext und meldet sich, wenn er sich ändert, eine gelieferte tut
	 * das nicht. Das darf nicht unbemerkt umschlagen (§6.5).
	 */
	const META_REPLACED_AT = '_article_tts_source_switched';

	/**
	 * Wie viele Zustellungen ein Durchgang übernimmt.
	 *
	 * WP-Cron läuft in einem Seitenaufruf. Fünf Downloads sind das, was ein
	 * Besucher unbemerkt mitträgt; der Rest wartet fünf Minuten. Kleiner als die
	 * Zahl der gepollten Vertonungen, weil hier jede Zustellung eine echte Datei
	 * über die Leitung zieht und nicht nur eine Statusfrage stellt.
	 */
	const BATCH = 5;

	/**
	 * Ein Durchgang: abholen, was zu dieser Installation gehört.
	 *
	 * Der Client ist ein Parameter, damit dieser Weg überhaupt prüfbar ist. Was
	 * hier schiefgehen kann, geht auf einer echten Redaktionsseite schief und
	 * kostet dort eine bezahlte Synthese oder die Zustellung einer anderen
	 * Site. Das darf nicht ungetestet bleiben, bloß weil dazwischen HTTP liegt.
	 * Der Cron ruft ohne Argument auf und bekommt den konfigurierten.
	 *
	 * @param Article_TTS_Client|null $client
	 * @return array Zusammenfassung für Tests und WP-CLI.
	 */
	public static function run( $client = null ) {
		$summary = array(
			'seen'      => 0,
			'foreign'   => 0,
			'delivered' => 0,
			'rejected'  => 0,
			'deferred'  => 0,
			'failed'    => 0,
		);

		if ( null === $client ) {
			$client = self::client( Article_TTS_Plugin::get_options() );
		}

		if ( ! $client->is_configured() ) {
			return $summary;
		}

		$response = $client->list_deliveries();

		if ( is_wp_error( $response ) ) {
			// Ein vorübergehender Fehler beendet nichts. Die Zustellungen liegen
			// weiter in DC-IO, der nächste Durchgang fragt erneut, und ihre
			// eigene Frist von sieben Tagen ist das, was sie am Ende schliesst.
			return $summary;
		}

		$items = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
		$taken = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['deliveryId'] ) ) {
				continue;
			}

			++$summary['seen'];

			// SCHRITT 1, UND ER STEHT VORN. Alles Weitere setzt voraus, dass diese
			// Zustellung überhaupt uns gilt.
			if ( ! self::host_matches( isset( $item['targetUrl'] ) ? $item['targetUrl'] : '' ) ) {
				++$summary['foreign'];
				continue;
			}

			if ( $taken >= self::BATCH ) {
				break;
			}

			++$taken;
			$outcome = self::take( $client, $item );
			++$summary[ $outcome ];
		}

		return $summary;
	}

	/**
	 * Auf Zuruf nachsehen, ob für GENAU DIESEN Beitrag etwas bereitliegt.
	 *
	 * Das ist der Knopf „Jetzt von Heise I/O holen". Er löst dasselbe wie der
	 * Cron, bezahlt es aber anders: Ein pollender Editor kostet dauernd, egal ob
	 * etwas kommt, dieser Weg genau dann, wenn ein Mensch danach fragt. Wer eben
	 * aus DC-IO geschickt hat, weiss ja, dass etwas unterwegs ist.
	 *
	 * Antwortet mit einer von vier Lagen, und die Unterscheidung ist der Punkt:
	 *
	 *   delivered   übernommen, das Audio hängt am Beitrag
	 *   composing   liegt, wird aber noch zusammengefügt
	 *   none        für diesen Beitrag liegt nichts bereit
	 *   deferred    an diesem Beitrag läuft gerade eine eigene Vertonung
	 *
	 * `composing` gegen `none` abzugrenzen ist kein Feinschliff. Ohne das meldet
	 * der Knopf „nichts bereitliegend", während in DC-IO „wird zusammengefügt"
	 * steht: zwei Wahrheiten über dieselbe Sache, und die falsche steht dort, wo
	 * jemand gerade wartet.
	 *
	 * @param int                $post_id
	 * @param Article_TTS_Client $client
	 * @return array|WP_Error ['state' => …, 'post_id' => int]
	 */
	public static function fetch_for( $post_id, $client = null ) {
		$post_id = (int) $post_id;

		if ( null === $client ) {
			$client = self::client( Article_TTS_Plugin::get_options() );
		}

		if ( ! $client->is_configured() ) {
			return new WP_Error(
				'article_tts_not_configured',
				__( 'Verbindung zu Heise I/O ist nicht konfiguriert.', 'article-tts' )
			);
		}

		$response = $client->list_deliveries();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['deliveryId'] ) ) {
				continue;
			}

			// Wie im Cron: erst der Host, dann alles andere.
			if ( ! self::host_matches( isset( $item['targetUrl'] ) ? $item['targetUrl'] : '' ) ) {
				continue;
			}

			$target = self::resolve_target( (string) $item['targetUrl'] );

			if ( is_wp_error( $target ) || (int) $target !== $post_id ) {
				// Gilt einem anderen Beitrag dieser Installation, oder die
				// Adresse zeigt ins Leere. Beides ist Sache des Cron-Durchgangs,
				// nicht dieses Knopfes: Er ist für DIESEN Beitrag gedrückt
				// worden und soll nicht nebenbei fremde Zustellungen abhaken.
				continue;
			}

			// `collectable` kommt aus dem Posteingang und fehlt, solange die
			// Gegenseite es noch nicht mitschickt. Fehlt es, gilt der Eintrag
			// als abholbar, denn genau das listete der Posteingang bisher
			// ausschliesslich.
			$collectable = ! array_key_exists( 'collectable', $item ) || (bool) $item['collectable'];

			if ( ! $collectable ) {
				return array( 'state' => 'composing', 'post_id' => $post_id );
			}

			$outcome = self::take( $client, $item );

			return array(
				'state'   => 'deferred' === $outcome ? 'deferred' : $outcome,
				'post_id' => $post_id,
			);
		}

		return array( 'state' => 'none', 'post_id' => $post_id );
	}

	/**
	 * Eine Zustellung übernehmen: Adresse auflösen, Datei holen, anhängen, melden.
	 *
	 * @param Article_TTS_Client $client
	 * @param array              $item Ein Eintrag aus dem Posteingang.
	 * @return string Einer der Schlüssel aus der Zusammenfassung in run().
	 */
	private static function take( $client, $item ) {
		$delivery_id = (string) $item['deliveryId'];
		$target      = self::resolve_target( (string) $item['targetUrl'] );

		if ( is_wp_error( $target ) ) {
			// Der Host passt, die Adresse trotzdem nicht. Das ist der Fall, für
			// den die Meldung gedacht ist. Sie führt in DC-IO zu einem Satz, aus
			// dem eine Handlung folgt, statt zu einer Zustellung, die einfach
			// liegen bleibt.
			$client->confirm_delivery(
				$delivery_id,
				array(
					'error'         => true,
					'errorCategory' => $target->get_error_code(),
				)
			);

			return 'rejected';
		}

		// NICHT IN EINE LAUFENDE VERTONUNG HINEIN (§6.5). Sonst überschreibt die
		// Zustellung das gerade erzeugte Audio, oder die fertig werdende
		// Vertonung überschreibt die Zustellung, je nachdem, wer zuletzt
		// schreibt. Beides ist eine bezahlte Synthese für die Tonne. Ohne
		// Meldung: die Zustellung bleibt offen und wird beim nächsten Durchgang
		// genommen.
		if ( self::rendition_running( $target ) ) {
			return 'deferred';
		}

		$attached = self::attach( $target, $delivery_id, $item, $client );

		if ( is_wp_error( $attached ) ) {
			// Die Datei kam nicht an. KEINE Meldung: die Zustellung ist nicht
			// gescheitert, nur dieser Versuch. Ein zweiter schreibt dieselbe
			// Datei an denselben Ort und ist damit folgenlos (§4.4).
			return 'failed';
		}

		$client->confirm_delivery( $delivery_id, array( 'externalId' => (string) $target ) );

		return 'delivered';
	}

	/**
	 * Gehört diese Adresse zu dieser Installation?
	 *
	 * DREI FALLEN, alle billig zu vermeiden (§6.4):
	 *
	 * - Gegen `home_url()` UND `site_url()` vergleichen. Die beiden dürfen
	 *   auseinanderfallen, etwa wenn WordPress in einem Unterverzeichnis liegt
	 *   und die Seite darüber. Ein Nutzer kopiert die Adresse, unter der er den
	 *   Artikel LIEST, also die aus `home_url()`.
	 * - Nur den Host. Ob jemand `http` oder `https` einfügt, ob ein Port
	 *   dranhängt, darf nicht entscheiden.
	 * - `www.` und Groß-/Kleinschreibung normalisieren. `WWW.The-Decoder.de` und
	 *   `the-decoder.de` sind dieselbe Seite; wer daran scheitert, sucht lange.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function host_matches( $url ) {
		$host = self::normalize_host( $url );

		if ( '' === $host ) {
			return false;
		}

		return in_array(
			$host,
			array(
				self::normalize_host( home_url() ),
				self::normalize_host( site_url() ),
			),
			true
		);
	}

	/**
	 * @param string $url
	 * @return string Kleingeschrieben, ohne führendes `www.`, ohne Port.
	 */
	private static function normalize_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Die Zieladresse in eine Beitrags-ID übersetzen.
	 *
	 * DER AUFRUFER HAT DEN HOST BEREITS GEPRÜFT, und das ist keine Bequemlichkeit,
	 * sondern der Grund, warum diese Funktion so aussehen darf. Schritt 1 unten
	 * umgeht nämlich einen Schutz, den WordPress von sich aus mitbringt:
	 * `url_to_postid()` prüft den Host SELBST und gibt für eine fremde Adresse
	 * NULL zurück. Wer die ID stattdessen aus `post=` entnimmt, fragt
	 * `url_to_postid()` nie. Der Host wäre damit gleichgültig, und eine
	 * Zustellung nach `https://fremde-seite.de/wp-admin/post.php?post=43233`
	 * hinge das Audio an den LOKALEN Beitrag 43233, also an einen völlig anderen
	 * Artikel als gemeint (§3.3).
	 *
	 * Deshalb steht die Hostprüfung beim Aufrufer VOR dem Aufruf und nicht hier
	 * hinter einem Rücksprung, wo sie versehentlich übersprungen werden könnte.
	 *
	 * @param string $url
	 * @return int|WP_Error Beitrags-ID, sonst ein Fehler mit einer der beiden
	 *                      Kategorien aus §3.2 als Code.
	 */
	public static function resolve_target( $url ) {
		$post_id = 0;

		// SCHRITT 1: die Editor-Adresse. Ausgerechnet die Adresse, die beim
		// Schreiben in der Adresszeile steht, löst `url_to_postid()` NICHT auf:
		// `…/wp-admin/post.php?post=123&action=edit` ist eine Admin-Adresse, die
		// es nicht kennt. Ohne diesen Schritt wäre der häufigste Fall der
		// einzige, der nicht ginge.
		$query = wp_parse_url( (string) $url, PHP_URL_QUERY );

		if ( is_string( $query ) && '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );

			if ( isset( $args['post'] ) && ctype_digit( (string) $args['post'] ) ) {
				$post_id = (int) $args['post'];
			}
		}

		// SCHRITT 2: alles andere. Deckt `?p=`, `page_id=`, die Vorschau-Adresse
		// und schöne Permalinks ab. Auch Entwürfe: WordPress gibt ihnen einen
		// Permalink der Form `?p=123`, und das ist wichtig, weil eine Vertonung
		// typischerweise VOR der Veröffentlichung entsteht.
		if ( 0 === $post_id ) {
			$post_id = (int) url_to_postid( (string) $url );
		}

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'target_unresolved',
				__( 'Unter dieser Adresse gibt es keinen Beitrag.', 'article-tts' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'target_unresolved',
				__( 'Diesen Beitrag gibt es nicht mehr.', 'article-tts' )
			);
		}

		// SCHRITT 3: derselbe Beitragstyp-Filter, der auch entscheidet, wo die
		// Audio-Box überhaupt erscheint (D4). Eine EIGENE Kategorie, weil sie zu
		// einer völlig anderen Handlung führt: die Adresse war richtig, die
		// Einstellung ist es nicht. Ohne sie stünde in DC-IO „Ziel nicht
		// gefunden" an einer korrekten Adresse, und niemand käme auf die
		// Einstellung.
		$options = Article_TTS_Plugin::get_options();

		if ( ! in_array( $post->post_type, (array) $options['enabled_post_types'], true ) ) {
			return new WP_Error(
				'post_type_not_enabled',
				__( 'Für diesen Beitragstyp ist die Vertonung nicht freigeschaltet.', 'article-tts' )
			);
		}

		return $post_id;
	}

	/**
	 * Läuft an diesem Beitrag gerade eine Vertonung?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function rendition_running( $post_id ) {
		$status = (string) get_post_meta( $post_id, Article_TTS_Generator::META_JOB_STATUS, true );

		return '' !== $status && ! in_array( $status, Article_TTS_Generator::TERMINAL, true );
	}

	/**
	 * Die Datei holen und zur Audiofassung des Beitrags machen.
	 *
	 * ÜBERSCHREIBT OHNE RÜCKFRAGE (D3). Die Rückfrage war nicht baubar: eine
	 * Zustellung wird vom Cron übernommen, im Hintergrund, ohne dass jemand am
	 * Bildschirm sitzt. Es gibt niemanden zu fragen. Wer sie hätte einbauen
	 * wollen, hätte die Zustellung liegen lassen müssen, bis zufällig ein
	 * Redakteur den Beitrag öffnet.
	 *
	 * Die Reihenfolge ist dieselbe wie beim Erzeugen: erst die Datei, dann die
	 * Meta-Einträge, die Herkunft ZULETZT. Ein halb geschriebener Zustand darf
	 * nie aktuell aussehen.
	 *
	 * @param int                $post_id
	 * @param string             $delivery_id
	 * @param array              $item
	 * @param Article_TTS_Client $client
	 * @return true|WP_Error
	 */
	private static function attach( $post_id, $delivery_id, $item, $client ) {
		$target = Article_TTS_Generator::audio_target_for(
			$post_id,
			'd' . substr( preg_replace( '/[^A-Za-z0-9]/', '', $delivery_id ), -8 )
		);

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$size = $client->download_delivery_audio( $delivery_id, $target['path'] );

		if ( is_wp_error( $size ) ) {
			return $size;
		}

		// Ob hier eine im Editor erzeugte Fassung weicht, muss VOR dem Schreiben
		// der Meta-Einträge feststehen: danach ist die Herkunft überschrieben
		// und die Frage nicht mehr zu beantworten.
		$replaced_generated = self::SOURCE_DCIO !== (string) get_post_meta( $post_id, self::META_SOURCE, true )
			&& '' !== (string) get_post_meta( $post_id, Article_TTS_Generator::META_URL, true );

		$previous = get_post_meta( $post_id, Article_TTS_Generator::META_PATH, true );
		if ( $previous && $previous !== $target['path'] && file_exists( $previous ) ) {
			@unlink( $previous );
		}

		update_post_meta( $post_id, Article_TTS_Generator::META_URL, $target['url'] );
		update_post_meta( $post_id, Article_TTS_Generator::META_PATH, $target['path'] );
		update_post_meta( $post_id, Article_TTS_Generator::META_GENERATED, time() );
		update_post_meta( $post_id, Article_TTS_Generator::META_SIZE, $size );

		// DIE PRÜFSUMME MUSS WEG, nicht bloß ungenutzt bleiben. Bliebe die einer
		// früheren Vertonung stehen, verglicht `is_stale()` sie gegen den
		// heutigen Text, sobald jemand die Herkunft wieder auf „erzeugt"
		// zurücksetzt, und meldete eine Fassung als veraltet, die es nie war.
		delete_post_meta( $post_id, Article_TTS_Generator::META_HASH );
		delete_post_meta( $post_id, Article_TTS_Generator::META_HASH_VERSION );
		delete_post_meta( $post_id, Article_TTS_Generator::META_VOICE );

		$voice = isset( $item['voiceLabel'] ) ? (string) $item['voiceLabel'] : '';
		if ( '' !== $voice ) {
			update_post_meta( $post_id, self::META_VOICE_LABEL, $voice );
		} else {
			delete_post_meta( $post_id, self::META_VOICE_LABEL );
		}

		// Wie bei der Stimme: gesetzt ODER geloescht, nie stehengelassen. Sonst
		// traegt eine zweite Zustellung ohne Projektnamen den der ersten weiter,
		// und die Box benennt ein Projekt, aus dem die gezeigte Datei nicht kommt.
		$project = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
		if ( '' !== $project ) {
			update_post_meta( $post_id, self::META_PROJECT, $project );
		} else {
			delete_post_meta( $post_id, self::META_PROJECT );
		}

		if ( $replaced_generated ) {
			update_post_meta( $post_id, self::META_REPLACED_AT, time() );
		}

		update_post_meta( $post_id, self::META_DELIVERY, $delivery_id );
		update_post_meta( $post_id, self::META_SOURCE, self::SOURCE_DCIO );

		return true;
	}

	/**
	 * Stammt die Fassung an diesem Beitrag aus DC-IO?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_delivered( $post_id ) {
		return self::SOURCE_DCIO === (string) get_post_meta( (int) $post_id, self::META_SOURCE, true );
	}

	/**
	 * Die Meta-Schlüssel, die eine Zustellung hinterlässt. Das Löschen einer
	 * Audiofassung nimmt sie mit, damit der Beitrag nicht mit der Herkunft einer
	 * Datei zurückbleibt, die es nicht mehr gibt.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			self::META_SOURCE,
			self::META_DELIVERY,
			self::META_VOICE_LABEL,
			self::META_PROJECT,
			self::META_REPLACED_AT,
		);
	}

	private static function client( $options ) {
		return new Article_TTS_Client( $options['api_base_url'], $options['api_token'] );
	}
}
