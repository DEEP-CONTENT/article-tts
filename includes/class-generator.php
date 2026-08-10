<?php
/**
 * Extract text from a post, hand it to DC-IO, collect the audio.
 *
 * The old flow was one blocking call: text in, MP3 bytes out, done inside a
 * single admin request. Synthesis is now asynchronous — a long article is split,
 * synthesised in parts and mastered into one file, which takes minutes — so the
 * work is split into three moments that no longer share a request:
 *
 *   submit()  hands the article over and stores a job id
 *   poll()    asks how far it is
 *   ingest()  downloads the finished audio into the uploads directory
 *
 * What did NOT change is where the audio ends up. The player still reads
 * `_article_tts_audio_url`, so everything already generated keeps working.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Generator {

	const META_URL       = '_article_tts_audio_url';
	const META_PATH      = '_article_tts_audio_path';
	const META_GENERATED = '_article_tts_audio_generated';
	const META_VOICE     = '_article_tts_audio_voice';
	const META_HASH      = '_article_tts_audio_hash';
	const META_SIZE      = '_article_tts_audio_size';
	const META_OVERRIDE  = '_article_tts_voice_override';

	/** Job state, all of it new with the asynchronous flow. */
	const META_JOB_ID     = '_article_tts_job_id';
	const META_JOB_STATUS = '_article_tts_job_status';
	const META_JOB_ERROR  = '_article_tts_job_error';
	const META_JOB_START  = '_article_tts_job_requested';
	const META_JOB_CHUNKS = '_article_tts_job_chunks';
	const META_JOB_DONE   = '_article_tts_job_done';

	/**
	 * Which formula produced `_article_tts_audio_hash`.
	 *
	 * 1 = the provider-era md5. 2 = the current sha256, computed identically on
	 * both sides of the API. Without this marker every already-generated article
	 * would compare a v1 hash against a v2 hash, come out different, and be
	 * flagged stale — a paid mass re-rendering nobody asked for.
	 */
	const META_HASH_VERSION = '_article_tts_hash_v';
	const HASH_VERSION      = 2;

	/** Job states that mean "nothing more is coming". */
	const TERMINAL = array( 'completed', 'failed', 'expired' );

	/**
	 * Hand a post to DC-IO.
	 *
	 * @param int $post_id
	 * @return array|WP_Error ['job_id','job_status','chunks','replayed'].
	 */
	public static function submit( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'article_tts_no_post', __( 'Post nicht gefunden.', 'article-tts' ) );
		}

		$options = Article_TTS_Plugin::get_options();
		$client  = self::client( $options );

		if ( ! $client->is_configured() ) {
			return new WP_Error(
				'article_tts_not_configured',
				__( 'Adresse und Token für Heise I/O fehlen. Bitte in den Einstellungen hinterlegen.', 'article-tts' )
			);
		}

		$voice_id = self::resolve_voice_id( $post_id, $options );
		if ( '' === $voice_id ) {
			return new WP_Error( 'article_tts_no_voice', __( 'Keine Standard-Stimme konfiguriert und kein Post-Override gesetzt.', 'article-tts' ) );
		}

		$text = self::normalize( self::build_text( $post, $options ) );
		if ( '' === $text ) {
			return new WP_Error( 'article_tts_empty_text', __( 'Der zusammengesetzte Artikeltext ist leer.', 'article-tts' ) );
		}

		$hash = self::content_hash( $text, $voice_id, $options['model_id'], $options['language'] );

		$response = $client->submit_article(
			array(
				'text'        => $text,
				'language'    => $options['language'],
				'voiceId'     => $voice_id,
				'modelId'     => '' !== $options['model_id'] ? $options['model_id'] : null,
				'siteId'      => self::site_id(),
				'externalId'  => (string) $post_id,
				'contentHash' => $hash,
				'title'       => get_the_title( $post ),
			),
			self::idempotency_key( $post_id, $hash )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$job_id = isset( $response['jobId'] ) ? (string) $response['jobId'] : '';
		if ( '' === $job_id ) {
			return new WP_Error( 'article_tts_no_job', __( 'Heise I/O hat keine Job-ID zurückgegeben.', 'article-tts' ) );
		}

		update_post_meta( $post_id, self::META_JOB_ID, $job_id );
		update_post_meta( $post_id, self::META_JOB_STATUS, isset( $response['jobStatus'] ) ? $response['jobStatus'] : 'queued' );
		update_post_meta( $post_id, self::META_JOB_CHUNKS, isset( $response['chunkCount'] ) ? (int) $response['chunkCount'] : 0 );
		update_post_meta( $post_id, self::META_JOB_START, time() );
		delete_post_meta( $post_id, self::META_JOB_ERROR );

		return array(
			'job_id'     => $job_id,
			'job_status' => get_post_meta( $post_id, self::META_JOB_STATUS, true ),
			'chunks'     => (int) get_post_meta( $post_id, self::META_JOB_CHUNKS, true ),
			'replayed'   => isset( $response['status'] ) && 200 === (int) $response['status'],
		);
	}

	/**
	 * Ask DC-IO how far the rendition is, and store what came back.
	 *
	 * @param int $post_id
	 * @return array|WP_Error ['job_status','done','total','terminal','error'].
	 */
	public static function poll( $post_id ) {
		$post_id = (int) $post_id;
		$job_id  = (string) get_post_meta( $post_id, self::META_JOB_ID, true );

		if ( '' === $job_id ) {
			return new WP_Error( 'article_tts_no_job', __( 'Für diesen Beitrag läuft keine Vertonung.', 'article-tts' ) );
		}

		$options  = Article_TTS_Plugin::get_options();
		$response = self::client( $options )->get_job( $job_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['jobStatus'] ) ? (string) $response['jobStatus'] : '';
		update_post_meta( $post_id, self::META_JOB_STATUS, $status );

		$done  = isset( $response['progress']['done'] ) ? (int) $response['progress']['done'] : 0;
		$total = isset( $response['progress']['total'] ) ? (int) $response['progress']['total'] : 0;
		update_post_meta( $post_id, self::META_JOB_DONE, $done );

		if ( 'failed' === $status || 'expired' === $status ) {
			update_post_meta(
				$post_id,
				self::META_JOB_ERROR,
				isset( $response['errorCategory'] ) ? (string) $response['errorCategory'] : 'unknown'
			);
		}

		return array(
			'job_status' => $status,
			'done'       => $done,
			'total'      => $total,
			'terminal'   => in_array( $status, self::TERMINAL, true ),
			'error'      => (string) get_post_meta( $post_id, self::META_JOB_ERROR, true ),
		);
	}

	/**
	 * Download the finished audio and make it the post's audio version.
	 *
	 * The hash is written LAST, together with the url: it is what the editor's
	 * "up to date" indicator reads, and a half-written state must never look
	 * current.
	 *
	 * @param int $post_id
	 * @return array|WP_Error ['url','size','generated','voice','hash'].
	 */
	public static function ingest( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		$job_id  = (string) get_post_meta( $post_id, self::META_JOB_ID, true );

		if ( ! $post || '' === $job_id ) {
			return new WP_Error( 'article_tts_no_job', __( 'Für diesen Beitrag läuft keine Vertonung.', 'article-tts' ) );
		}

		$options  = Article_TTS_Plugin::get_options();
		$voice_id = self::resolve_voice_id( $post_id, $options );
		$text     = self::normalize( self::build_text( $post, $options ) );
		$hash     = self::content_hash( $text, $voice_id, $options['model_id'], $options['language'] );

		$target = self::audio_target( $post_id, $hash );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$size = self::client( $options )->download_audio( $job_id, $target['path'] );
		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$previous = get_post_meta( $post_id, self::META_PATH, true );
		if ( $previous && $previous !== $target['path'] && file_exists( $previous ) ) {
			@unlink( $previous );
		}

		$now = time();
		update_post_meta( $post_id, self::META_URL, $target['url'] );
		update_post_meta( $post_id, self::META_PATH, $target['path'] );
		update_post_meta( $post_id, self::META_GENERATED, $now );
		update_post_meta( $post_id, self::META_VOICE, $voice_id );
		update_post_meta( $post_id, self::META_SIZE, $size );
		update_post_meta( $post_id, self::META_HASH_VERSION, self::HASH_VERSION );
		update_post_meta( $post_id, self::META_HASH, $hash );

		return array(
			'url'       => $target['url'],
			'size'      => $size,
			'generated' => $now,
			'voice'     => $voice_id,
			'hash'      => $hash,
		);
	}

	/**
	 * Delete audio, job state and meta for a post.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function delete( $post_id ) {
		$post_id = (int) $post_id;
		$path    = get_post_meta( $post_id, self::META_PATH, true );

		if ( $path && file_exists( $path ) ) {
			@unlink( $path );
		}

		$job_id = (string) get_post_meta( $post_id, self::META_JOB_ID, true );
		if ( '' !== $job_id ) {
			// Best effort: a rendition still running answers 409 and is left
			// alone. Its own deadline ends it either way.
			self::client( Article_TTS_Plugin::get_options() )->delete_job( $job_id );
		}

		foreach ( array(
			self::META_URL,
			self::META_PATH,
			self::META_GENERATED,
			self::META_VOICE,
			self::META_HASH,
			self::META_SIZE,
			self::META_HASH_VERSION,
			self::META_JOB_ID,
			self::META_JOB_STATUS,
			self::META_JOB_ERROR,
			self::META_JOB_START,
			self::META_JOB_CHUNKS,
			self::META_JOB_DONE,
		) as $key ) {
			delete_post_meta( $post_id, $key );
		}

		return true;
	}

	/**
	 * Is the stored audio still the one this article would produce?
	 *
	 * Only ever compares within one hash version. An article rendered by the
	 * previous provider is NOT stale — it is simply older, and re-rendering it
	 * costs money for a result nobody asked to change.
	 */
	public static function is_stale( $post_id, $post = null ) {
		$post_id = (int) $post_id;
		$stored  = (string) get_post_meta( $post_id, self::META_HASH, true );

		if ( '' === $stored ) {
			return false;
		}

		if ( (int) get_post_meta( $post_id, self::META_HASH_VERSION, true ) !== self::HASH_VERSION ) {
			return false;
		}

		$post    = $post ? $post : get_post( $post_id );
		$options = Article_TTS_Plugin::get_options();

		$current = self::content_hash(
			self::normalize( self::build_text( $post, $options ) ),
			self::resolve_voice_id( $post_id, $options ),
			$options['model_id'],
			$options['language']
		);

		return $stored !== $current;
	}

	/**
	 * The identity of a rendition — computed identically on both sides.
	 *
	 * DC-IO recomputes this from the text it receives and refuses a mismatch, so
	 * this formula and {@see normalize()} are a contract, not an implementation
	 * detail. Changing either one is a breaking change and needs a new version
	 * prefix rather than an edit in place.
	 *
	 * This used to exist twice — here and in the metabox — which is precisely how
	 * two copies of a formula drift.
	 */
	public static function content_hash( $normalized_text, $voice_id, $model_slug, $language ) {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) self::HASH_VERSION,
					(string) $voice_id,
					(string) $model_slug,
					(string) $language,
					(string) $normalized_text,
				)
			)
		);
	}

	/**
	 * The four normalisation rules DC-IO applies before hashing.
	 *
	 * Ported deliberately verbatim rather than reimplemented: any difference
	 * between the two shows up as `content_hash_mismatch` on every submit, and
	 * the cause would sit in whitespace nobody can see.
	 */
	public static function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = (string) preg_replace( '/\h+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Build the plain text payload from a post according to settings.
	 *
	 * Unchanged from the provider era — it never had anything to do with the
	 * vendor.
	 *
	 * @param WP_Post $post    Post object.
	 * @param array   $options Plugin options.
	 * @return string Plain text.
	 */
	public static function build_text( $post, $options ) {
		$parts = array();

		if ( ! empty( $options['include_title'] ) ) {
			$title = get_the_title( $post );
			if ( '' !== trim( $title ) ) {
				$parts[] = trim( $title ) . '.';
			}
		}

		if ( ! empty( $options['include_excerpt'] ) ) {
			$excerpt = '';
			if ( ! empty( $post->post_excerpt ) ) {
				$excerpt = $post->post_excerpt;
			}
			$excerpt = trim( wp_strip_all_tags( $excerpt ) );
			if ( '' !== $excerpt ) {
				$parts[] = $excerpt;
			}
		}

		$content = $post->post_content;
		$content = strip_shortcodes( $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		// Run through the_content filter to expand blocks, but suppress our own player to avoid recursion.
		remove_filter( 'the_content', array( Article_TTS_Player::get_instance(), 'inject_player' ), 20 );
		$rendered = apply_filters( 'the_content', $content );
		add_filter( 'the_content', array( Article_TTS_Player::get_instance(), 'inject_player' ), 20 );

		$rendered = wp_strip_all_tags( $rendered );
		$rendered = html_entity_decode( $rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$rendered = preg_replace( '/\s+/u', ' ', $rendered );
		$rendered = trim( $rendered );

		if ( '' !== $rendered ) {
			$parts[] = $rendered;
		}

		return trim( implode( "\n\n", $parts ) );
	}

	/**
	 * Resolve which voice ID to use for this post.
	 *
	 * Priority: post override → default voice.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $options Plugin options.
	 * @return string Voice ID (may be empty).
	 */
	public static function resolve_voice_id( $post_id, $options ) {
		$override = get_post_meta( $post_id, self::META_OVERRIDE, true );
		if ( $override ) {
			return (string) $override;
		}
		return isset( $options['default_voice_id'] ) ? (string) $options['default_voice_id'] : '';
	}

	/**
	 * Deterministic, and derived from the CONTENT rather than the post id.
	 *
	 * A key built from the post id alone would make an edited article replay the
	 * previous rendition — the reader would hear the old text. Tied to the hash,
	 * a changed article is a different request, and an unchanged retry is the
	 * same one.
	 *
	 * The format is constrained by the receiving side: at most 128 characters and
	 * `[A-Za-z0-9._:-]` only. A key outside that is IGNORED rather than rejected,
	 * which would silently cost a second rendition per retry.
	 */
	private static function idempotency_key( $post_id, $hash ) {
		return sprintf( 'atts:%s:%d:%s', substr( self::site_id(), 0, 8 ), (int) $post_id, substr( $hash, 0, 32 ) );
	}

	/**
	 * A stable identifier for this installation.
	 *
	 * Derived from the site URL rather than stored, so it survives a database
	 * copy without two installations claiming the same identity — and it changes
	 * when the site moves, which is the honest outcome: the renditions belong to
	 * the old address.
	 */
	public static function site_id() {
		return substr( hash( 'sha256', (string) get_site_url() ), 0, 16 );
	}

	private static function client( $options ) {
		return new Article_TTS_Client( $options['api_base_url'], $options['api_token'] );
	}

	/**
	 * Where the MP3 goes. Unchanged layout, so existing files keep their place.
	 *
	 * @param int    $post_id
	 * @param string $hash
	 * @return array|WP_Error ['path','url']
	 */
	private static function audio_target( $post_id, $hash ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'article_tts_upload_dir', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . ARTICLE_TTS_UPLOAD_SUBDIR;
		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'article_tts_mkdir', __( 'Konnte Upload-Verzeichnis nicht anlegen.', 'article-tts' ) );
			}
		}

		$filename = sprintf( 'post-%d-%s.mp3', (int) $post_id, substr( $hash, 0, 8 ) );

		return array(
			'path' => $dir . '/' . $filename,
			'url'  => trailingslashit( $uploads['baseurl'] ) . ARTICLE_TTS_UPLOAD_SUBDIR . '/' . $filename,
		);
	}
}
