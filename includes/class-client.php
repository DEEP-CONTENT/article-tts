<?php
/**
 * HTTP client for the DC-IO article API.
 *
 * Replaces the direct provider call. The plugin no longer knows a synthesis
 * vendor at all: it hands an article to DC-IO and later collects an MP3.
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_Client {

	/**
	 * Deliberately short.
	 *
	 * The previous provider call blocked for up to 120 seconds because it waited
	 * for the whole synthesis — which is exactly what ran into 60-second gateway
	 * timeouts. Nothing waits here: the submit returns a job id, everything else
	 * is a small read. A request that cannot answer in 20 s is broken, not busy.
	 */
	const TIMEOUT = 20;

	/** Downloading the finished audio is the one call that moves real bytes. */
	const DOWNLOAD_TIMEOUT = 60;

	private $base_url;
	private $token;

	public function __construct( $base_url, $token ) {
		$this->base_url = untrailingslashit( trim( (string) $base_url ) );
		$this->token    = trim( (string) $token );
	}

	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->token;
	}

	/**
	 * Hand an article to DC-IO.
	 *
	 * @param array $args text, language, voice_id, model_id, site_id, external_id,
	 *                    content_hash, title.
	 * @param string $idempotency_key Deterministic key; see Article_TTS_Generator.
	 * @return array|WP_Error Decoded body on 200/202.
	 */
	public function submit_article( $args, $idempotency_key = '' ) {
		$headers = array();
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		return $this->request( 'POST', '/api/v1/tts/articles', $args, $headers );
	}

	public function get_job( $job_id ) {
		return $this->request( 'GET', '/api/v1/tts/articles/' . rawurlencode( $job_id ) );
	}

	/**
	 * Recover a job id that was lost locally — the only way back to a rendition
	 * whose id never made it into post meta.
	 */
	public function list_jobs( $site_id, $external_id ) {
		return $this->request(
			'GET',
			'/api/v1/tts/articles?' . http_build_query(
				array(
					'siteId'     => $site_id,
					'externalId' => $external_id,
				)
			)
		);
	}

	public function delete_job( $job_id ) {
		return $this->request( 'DELETE', '/api/v1/tts/articles/' . rawurlencode( $job_id ) );
	}

	public function list_voices() {
		return $this->request( 'GET', '/api/v1/tts/voices' );
	}

	public function list_models() {
		return $this->request( 'GET', '/api/v1/tts/models' );
	}

	/**
	 * Stream the finished audio to a local file.
	 *
	 * Written to a temporary name and only then moved into place: a download that
	 * dies halfway must not leave a truncated MP3 where the player expects a
	 * whole one.
	 *
	 * @param string $job_id
	 * @param string $destination Absolute target path.
	 * @return int|WP_Error Bytes written.
	 */
	public function download_audio( $job_id, $destination ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'article_tts_not_configured', __( 'Verbindung zu DC-IO ist nicht konfiguriert.', 'article-tts' ) );
		}

		// Deliberately NOT download_url(). Two reasons, both found the hard way:
		//
		// 1. It takes three parameters — url, timeout, signature check. A fourth
		//    argument with headers is silently ignored, so the request would go
		//    out WITHOUT the bearer token and come back 403.
		// 2. It fetches through wp_safe_remote_get(), which refuses any host
		//    resolving to a private address. Every other call in this class uses
		//    the unrestricted client, so an instance inside a company network
		//    would submit fine and then fail only on the download — the most
		//    confusing shape that failure could take.
		$temp = wp_tempnam( 'article-tts' );

		if ( ! $temp ) {
			return new WP_Error( 'article_tts_write', __( 'Konnte keine temporäre Datei anlegen.', 'article-tts' ) );
		}

		$response = wp_remote_get(
			$this->base_url . '/api/v1/tts/articles/' . rawurlencode( $job_id ) . '/audio',
			array(
				'timeout'  => self::DOWNLOAD_TIMEOUT,
				'stream'   => true,
				'filename' => $temp,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'audio/mpeg',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $temp );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// With stream => true the body is written to the file whatever the
		// status is — an error page would otherwise be stored as an MP3.
		if ( $code < 200 || $code >= 300 ) {
			$decoded = json_decode( (string) @file_get_contents( $temp ), true );
			@unlink( $temp );

			return $this->error_from( $code, is_array( $decoded ) ? $decoded : array() );
		}

		$size = (int) @filesize( $temp );
		if ( $size <= 0 ) {
			@unlink( $temp );
			return new WP_Error( 'article_tts_empty_audio', __( 'Die heruntergeladene Audiodatei ist leer.', 'article-tts' ) );
		}

		// Move only once the file is complete: a download that dies halfway must
		// not leave a truncated MP3 where the player expects a whole one.
		if ( ! @rename( $temp, $destination ) ) {
			@unlink( $temp );
			return new WP_Error( 'article_tts_write', __( 'Konnte Audio-Datei nicht schreiben.', 'article-tts' ) );
		}

		return $size;
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 * @param array  $headers
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = array(), $headers = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'article_tts_not_configured', __( 'Verbindung zu DC-IO ist nicht konfiguriert.', 'article-tts' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					// Lets a support case be followed across three systems from
					// one identifier instead of three timestamps.
					'X-Correlation-ID' => wp_generate_uuid4(),
				),
				$headers
			),
		);

		if ( ! empty( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		// 204 (delete) carries no body; 202 is the normal answer to a submit.
		if ( 204 === $code ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		// EVERY 2xx is success. The previous client accepted only 200, which
		// would have turned the submit's 202 into an error.
		if ( $code >= 200 && $code < 300 ) {
			return $decoded;
		}

		return $this->error_from( $code, $decoded );
	}

	/**
	 * DC-IO answers `{error, message, code, status}` on every failure — one shape
	 * for the whole API, so one parser is enough.
	 */
	private function error_from( $code, $decoded ) {
		$api_code = isset( $decoded['code'] ) ? (string) $decoded['code'] : '';
		$message  = isset( $decoded['message'] ) ? (string) $decoded['message'] : '';

		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Unerwartete Antwort von DC-IO (HTTP %d).', 'article-tts' ),
				$code
			);
		}

		// The few codes worth a sentence of their own, because the fix is not in
		// the article but in the setup.
		switch ( $api_code ) {
			case 'tts_not_entitled':
				$message = __( 'Text-to-Speech ist für diesen Zugang nicht freigeschaltet.', 'article-tts' );
				break;
			case 'content_hash_mismatch':
				$message = __( 'Der Artikeltext stimmt nicht mit der Prüfsumme überein — bitte das Plugin aktualisieren.', 'article-tts' );
				break;
		}

		if ( 401 === $code || 403 === $code ) {
			$message = '' !== $api_code && 'tts_not_entitled' === $api_code
				? $message
				: __( 'Zugang verweigert — bitte Token und Adresse in den Einstellungen prüfen.', 'article-tts' );
		}

		error_log( '[Heise I/O Article TTS] request failed: HTTP ' . $code . ' ' . $api_code );

		return new WP_Error(
			'' !== $api_code ? 'article_tts_' . $api_code : 'article_tts_http_' . $code,
			$message,
			array( 'status' => $code )
		);
	}
}
