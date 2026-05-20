<?php
/**
 * ElevenLabs HTTP client.
 *
 * Uses only WordPress core HTTP API (wp_remote_*).
 *
 * @package Article_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Article_TTS_API {

	const BASE_URL = 'https://api.elevenlabs.io';

	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	public function has_key() {
		return '' !== trim( $this->api_key );
	}

	/**
	 * Hand-picked recommended voices that are always shown in the voice
	 * dropdowns even before the user fetches their personal voice list.
	 *
	 * Voice IDs are the publicly documented ElevenLabs IDs that have been
	 * stable for years; german native voices come from the public Voice Library.
	 *
	 * @return array List of [id, name, lang, group] entries.
	 */
	public static function get_recommended_voices() {
		return array(
			// Männlich
			array(
				'id'    => 'aDYxt2YzboRX5QmntZNE',
				'name'  => 'Marcus — Journalist/Diplomat',
				'lang'  => 'de',
				'group' => 'male',
			),
			array(
				'id'    => 'pNInz6obpgDQGcFmaJgB',
				'name'  => 'Adam — tief, ruhig, News',
				'lang'  => 'en',
				'group' => 'male',
			),
			array(
				'id'    => 'ErXwobaYiN019PkySvjV',
				'name'  => 'Antoni — warm, vielseitig, Allrounder',
				'lang'  => 'en',
				'group' => 'male',
			),
			array(
				'id'    => 'onwK4e9ZLuTAKqWW03F9',
				'name'  => 'Daniel — autoritär, Nachrichtensprecher',
				'lang'  => 'en',
				'group' => 'male',
			),
			array(
				'id'    => 'IKne3meq5aSn9XLyUdCD',
				'name'  => 'Charlie — locker, Podcast/Conversational',
				'lang'  => 'en',
				'group' => 'male',
			),
			array(
				'id'    => 'N2lVS1w4EtoT3dr4eOWO',
				'name'  => 'Callum — markant, Erzählungen',
				'lang'  => 'en',
				'group' => 'male',
			),
			// Weiblich
			array(
				'id'    => 'VGPs8uAVxETgmG3lNnZD',
				'name'  => 'Cornelia — News/Erklärvideo',
				'lang'  => 'de',
				'group' => 'female',
			),
			array(
				'id'    => '21m00Tcm4TlvDq8ikWAM',
				'name'  => 'Rachel — neutral, klar, Doku-Stil',
				'lang'  => 'en',
				'group' => 'female',
			),
			array(
				'id'    => 'EXAVITQu4vr4xnSDxMaL',
				'name'  => 'Bella — weich, freundlich',
				'lang'  => 'en',
				'group' => 'female',
			),
			array(
				'id'    => 'AZnzlk1XvdvUeBnXmlld',
				'name'  => 'Domi — energetisch, emotional',
				'lang'  => 'en',
				'group' => 'female',
			),
		);
	}

	/**
	 * Group labels for the optgroups in the voice select dropdowns.
	 *
	 * @return array group key => label
	 */
	public static function get_recommended_voice_group_labels() {
		return array(
			'male'   => __( 'Männliche Stimmen', 'article-tts' ),
			'female' => __( 'Weibliche Stimmen', 'article-tts' ),
		);
	}

	/**
	 * Synthesize speech for the given text.
	 *
	 * @param string $text     Plain text to synthesize.
	 * @param string $voice_id ElevenLabs voice ID.
	 * @param string $model_id Model ID (e.g. eleven_multilingual_v2).
	 * @return string|WP_Error Binary audio (mp3) on success, WP_Error on failure.
	 */
	public function text_to_speech( $text, $voice_id, $model_id ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'article_tts_no_key', __( 'No API key configured.', 'article-tts' ) );
		}
		if ( '' === trim( $voice_id ) ) {
			return new WP_Error( 'article_tts_no_voice', __( 'No voice ID configured.', 'article-tts' ) );
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'article_tts_empty_text', __( 'Article text is empty.', 'article-tts' ) );
		}

		$url = self::BASE_URL . '/v1/text-to-speech/' . rawurlencode( $voice_id );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'xi-api-key'   => $this->api_key,
					'Accept'       => 'audio/mpeg',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'text'           => $text,
						'model_id'       => $model_id ? $model_id : 'eleven_multilingual_v2',
						'voice_settings' => array(
							'stability'        => 0.5,
							'similarity_boost' => 0.75,
						),
					)
				),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Heise I/O Article TTS] text_to_speech request failed: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $code ) {
			$decoded = json_decode( $body, true );
			$message = isset( $decoded['detail']['message'] ) ? $decoded['detail']['message'] : 'HTTP ' . $code;
			error_log( '[Heise I/O Article TTS] text_to_speech failed: ' . $message );
			return new WP_Error( 'article_tts_failed', $message );
		}

		if ( '' === $body ) {
			return new WP_Error( 'article_tts_empty_audio', __( 'API returned an empty response.', 'article-tts' ) );
		}

		return $body;
	}
}
