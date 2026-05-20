<?php
/**
 * Generator: extract text from a post, call ElevenLabs API, store audio file.
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

	/**
	 * Generate audio for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error On success: ['url','generated','voice','size','hash','skipped'?].
	 */
	public static function generate( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'article_tts_no_post', __( 'Post nicht gefunden.', 'article-tts' ) );
		}

		$options = Article_TTS_Plugin::get_options();

		if ( empty( $options['api_key'] ) ) {
			return new WP_Error( 'article_tts_no_key', __( 'API-Key fehlt. Bitte in den Einstellungen hinterlegen.', 'article-tts' ) );
		}

		$voice_id = self::resolve_voice_id( $post_id, $options );
		if ( '' === $voice_id ) {
			return new WP_Error( 'article_tts_no_voice', __( 'Keine Standard-Stimme konfiguriert und kein Post-Override gesetzt.', 'article-tts' ) );
		}

		$text = self::build_text( $post, $options );
		if ( '' === $text ) {
			return new WP_Error( 'article_tts_empty_text', __( 'Der zusammengesetzte Artikeltext ist leer.', 'article-tts' ) );
		}

		$hash          = md5( $voice_id . '|' . $options['model_id'] . '|' . $text );
		$existing_hash = get_post_meta( $post_id, self::META_HASH, true );
		$existing_path = get_post_meta( $post_id, self::META_PATH, true );

		if ( $existing_hash === $hash && $existing_path && file_exists( $existing_path ) ) {
			return array(
				'url'       => get_post_meta( $post_id, self::META_URL, true ),
				'voice'     => $voice_id,
				'generated' => (int) get_post_meta( $post_id, self::META_GENERATED, true ),
				'size'      => (int) get_post_meta( $post_id, self::META_SIZE, true ),
				'hash'      => $hash,
				'skipped'   => true,
			);
		}

		$api   = new Article_TTS_API( $options['api_key'] );
		$audio = $api->text_to_speech( $text, $voice_id, $options['model_id'] );

		if ( is_wp_error( $audio ) ) {
			return $audio;
		}

		$saved = self::save_audio_file( $post_id, $audio, $hash );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Remove old file if it differs from the new one.
		if ( $existing_path && $existing_path !== $saved['path'] && file_exists( $existing_path ) ) {
			@unlink( $existing_path );
		}

		$now = time();
		update_post_meta( $post_id, self::META_URL, $saved['url'] );
		update_post_meta( $post_id, self::META_PATH, $saved['path'] );
		update_post_meta( $post_id, self::META_GENERATED, $now );
		update_post_meta( $post_id, self::META_VOICE, $voice_id );
		update_post_meta( $post_id, self::META_HASH, $hash );
		update_post_meta( $post_id, self::META_SIZE, $saved['size'] );

		return array(
			'url'       => $saved['url'],
			'voice'     => $voice_id,
			'generated' => $now,
			'size'      => $saved['size'],
			'hash'      => $hash,
			'skipped'   => false,
		);
	}

	/**
	 * Delete audio file and meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if anything was deleted.
	 */
	public static function delete( $post_id ) {
		$post_id = (int) $post_id;
		$path    = get_post_meta( $post_id, self::META_PATH, true );
		if ( $path && file_exists( $path ) ) {
			@unlink( $path );
		}
		delete_post_meta( $post_id, self::META_URL );
		delete_post_meta( $post_id, self::META_PATH );
		delete_post_meta( $post_id, self::META_GENERATED );
		delete_post_meta( $post_id, self::META_VOICE );
		delete_post_meta( $post_id, self::META_HASH );
		delete_post_meta( $post_id, self::META_SIZE );
		return true;
	}

	/**
	 * Build the plain text payload from a post according to settings.
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
	 * Persist audio bytes to uploads/article-tts/.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $audio   Binary mp3 data.
	 * @param string $hash    Content hash.
	 * @return array|WP_Error ['path','url','size'] or error.
	 */
	private static function save_audio_file( $post_id, $audio, $hash ) {
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
		$path     = $dir . '/' . $filename;

		$bytes = file_put_contents( $path, $audio );
		if ( false === $bytes ) {
			return new WP_Error( 'article_tts_write', __( 'Konnte Audio-Datei nicht schreiben.', 'article-tts' ) );
		}

		$url = trailingslashit( $uploads['baseurl'] ) . ARTICLE_TTS_UPLOAD_SUBDIR . '/' . $filename;

		return array(
			'path' => $path,
			'url'  => $url,
			'size' => $bytes,
		);
	}
}
