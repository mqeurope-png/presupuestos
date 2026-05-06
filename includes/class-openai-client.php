<?php
/**
 * OpenAI HTTP client for matchmaker recommendations.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OpenAI_Client {

	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
	private const TIMEOUT  = 20;

	/**
	 * Approximate USD cost per 1k tokens (input, output).
	 * Source: OpenAI public pricing as of 2025.
	 */
	private const PRICES = [
		'gpt-4o-mini'    => [ 0.000150 / 1, 0.000600 / 1 ], // per 1k tokens.
		'gpt-4o'         => [ 0.00250 / 1, 0.01000 / 1 ],
		'gpt-4-turbo'    => [ 0.01000 / 1, 0.03000 / 1 ],
		'gpt-3.5-turbo'  => [ 0.000500 / 1, 0.001500 / 1 ],
	];

	private string $api_key;
	private string $model;

	public function __construct() {
		$enc            = (string) Settings::get( 'openai_api_key', '' );
		$this->api_key  = Settings::decrypt( $enc );
		$this->model    = (string) Settings::get( 'openai_model', 'gpt-4o-mini' );
		if ( '' === $this->model ) {
			$this->model = 'gpt-4o-mini';
		}
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Minimal connectivity check using a 1-token reply.
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'bqw_openai_not_configured', __( 'OpenAI API key is missing.', 'bomedia-quote-wizard' ) );
		}
		$response = $this->raw_post( [
			'model'        => $this->model,
			'messages'     => [
				[ 'role' => 'system', 'content' => 'You are a tester. Reply with "ok".' ],
				[ 'role' => 'user',   'content' => 'ping' ],
			],
			'max_tokens'   => 5,
			'temperature'  => 0.0,
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return [
			'ok'    => true,
			'usage' => $response['usage'] ?? [],
			'model' => $response['model'] ?? $this->model,
		];
	}

	/**
	 * Asks OpenAI for top recommendations.
	 *
	 * @param array $client_answers application/materials/volume/format/budget + lang.
	 * @param array $products       product payload (id/name/slug/categories/short_desc/internal_notes/image).
	 * @return array|WP_Error  { recommendations: [...], fallback_message: string, _meta: {...} }
	 */
	public function recommend( array $client_answers, array $products ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'bqw_openai_not_configured', __( 'OpenAI API key is missing.', 'bomedia-quote-wizard' ) );
		}

		$system_prompt = 'You are an expert sales advisor for Bomedia SL, a European distributor of UV-LED industrial printers and laser cutting/engraving machines (artisJet, MBO, Flux brands).' . "\n\n"
			. 'A potential customer has filled out a needs assessment. Your job is to recommend the 3 machines from our catalog that best fit their needs, based on:' . "\n"
			. '- Their stated requirements (production type, materials, monthly volume, max format, budget)' . "\n"
			. "- Each machine's description and internal sales notes" . "\n\n"
			. 'Respond ONLY in valid JSON with this exact structure:' . "\n"
			. '{' . "\n"
			. '  "recommendations": [' . "\n"
			. '    {' . "\n"
			. '      "product_id": "<product id from input as string>",' . "\n"
			. '      "score": <integer 0-100>,' . "\n"
			. '      "reasons": ["<short reason 1>", "<short reason 2>"]' . "\n"
			. '    }' . "\n"
			. '  ],' . "\n"
			. '  "fallback_message": "<string, only if no products score above 50, otherwise empty>"' . "\n"
			. '}' . "\n\n"
			. 'Rules:' . "\n"
			. '- Return up to 3 products, ordered by score descending' . "\n"
			. '- Only include products with score >= 50' . "\n"
			. '- If fewer than 3 score >= 50, return fewer' . "\n"
			. '- If no product scores >= 50, return empty recommendations array and a polite fallback_message in the customer\'s language explaining their case is specific' . "\n"
			. '- "reasons" must be in the customer\'s language (detected from their answers)' . "\n"
			. '- Each reason: max 12 words, concrete, mention specific machine capability matching their need' . "\n"
			. '- Do not invent capabilities not present in the product data' . "\n"
			. '- Internal notes ARE PRIVATE — never quote them verbatim, just use them to inform your reasoning';

		$apps      = self::join_list( $client_answers['application'] ?? [] );
		$mats      = self::join_list( $client_answers['materials'] ?? [] );
		$volume    = (string) ( $client_answers['volume'] ?? '' );
		$format    = (string) ( $client_answers['format'] ?? '' );
		$budget    = (string) ( $client_answers['budget'] ?? '' );
		$lang      = (string) ( $client_answers['lang'] ?? 'en' );

		$user_message = "Customer answers:\n"
			. '- Production type: ' . ( $apps ?: '(not specified)' ) . "\n"
			. '- Materials: ' . ( $mats ?: '(not specified)' ) . "\n"
			. '- Monthly volume: ' . ( $volume ?: '(not specified)' ) . "\n"
			. '- Max piece size: ' . ( $format ?: '(not specified)' ) . "\n"
			. '- Budget range: ' . ( $budget ?: 'not specified' ) . "\n\n"
			. 'Available products (JSON):' . "\n"
			. wp_json_encode( $products ) . "\n\n"
			. 'Customer language: ' . $lang;

		$payload = [
			'model'           => $this->model,
			'messages'        => [
				[ 'role' => 'system', 'content' => $system_prompt ],
				[ 'role' => 'user',   'content' => $user_message ],
			],
			'temperature'     => 0.3,
			'response_format' => [ 'type' => 'json_object' ],
		];

		$start    = microtime( true );
		$response = $this->raw_post( $payload );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			self::log_call( [
				'model'   => $this->model,
				'error'   => $response->get_error_message(),
				'elapsed' => $elapsed,
			] );
			return $response;
		}

		$content = $response['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) {
			self::log_call( [
				'model'   => $this->model,
				'error'   => 'malformed JSON',
				'raw'     => substr( (string) $content, 0, 200 ),
				'elapsed' => $elapsed,
			] );
			return new WP_Error( 'bqw_openai_bad_json', __( 'OpenAI returned malformed data.', 'bomedia-quote-wizard' ) );
		}

		$usage = $response['usage'] ?? [];
		$cost  = self::estimate_cost( $this->model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) );

		self::log_call( [
			'model'    => $this->model,
			'prompt'   => (int) ( $usage['prompt_tokens'] ?? 0 ),
			'output'   => (int) ( $usage['completion_tokens'] ?? 0 ),
			'cost_usd' => $cost,
			'elapsed'  => $elapsed,
		] );

		// Persist running cost.
		self::accumulate_cost( $cost );

		$parsed['_meta'] = [
			'usage' => $usage,
			'cost'  => $cost,
			'model' => $this->model,
		];
		return $parsed;
	}

	/* ------------------------------------------------------------------ */

	private function raw_post( array $body ) {
		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bqw_openai_transport', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( 401 === $code ) {
			return new WP_Error( 'bqw_openai_auth', __( 'OpenAI rejected the API key (401).', 'bomedia-quote-wizard' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'bqw_openai_rate_limit', __( 'OpenAI rate limit reached (429). Try again later.', 'bomedia-quote-wizard' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = $json['error']['message'] ?? sprintf( 'HTTP %d', $code );
			return new WP_Error( 'bqw_openai_http_' . $code, (string) $msg );
		}
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'bqw_openai_bad_response', __( 'OpenAI returned an invalid response.', 'bomedia-quote-wizard' ) );
		}
		return $json;
	}

	private static function join_list( $val ): string {
		if ( is_array( $val ) ) {
			return implode( ', ', array_filter( array_map( 'strval', $val ), 'strlen' ) );
		}
		return (string) $val;
	}

	public static function estimate_cost( string $model, int $prompt_tokens, int $output_tokens ): float {
		$prices = self::PRICES[ $model ] ?? self::PRICES['gpt-4o-mini'];
		return round( ( $prompt_tokens * $prices[0] / 1000 ) + ( $output_tokens * $prices[1] / 1000 ), 6 );
	}

	public static function models_list(): array {
		return [
			'gpt-4o-mini'   => 'gpt-4o-mini (~$0.001/call)',
			'gpt-4o'        => 'gpt-4o',
			'gpt-4-turbo'   => 'gpt-4-turbo',
			'gpt-3.5-turbo' => 'gpt-3.5-turbo',
		];
	}

	/* ------------------------------------------------------------------ */
	/* Quota + accounting                                                  */
	/* ------------------------------------------------------------------ */

	public static function quota_key(): string {
		return 'bqw_openai_daily_count_' . gmdate( 'Y-m-d' );
	}

	public static function quota_limit(): int {
		return max( 0, (int) Settings::get( 'openai_daily_cap', 200 ) );
	}

	public static function calls_today(): int {
		return (int) get_transient( self::quota_key() );
	}

	public static function bump_calls_today(): void {
		$key = self::quota_key();
		$cur = (int) get_transient( $key );
		// 36h TTL is enough; the date in the key effectively rotates the counter.
		set_transient( $key, $cur + 1, 36 * HOUR_IN_SECONDS );
	}

	public static function quota_remaining(): int {
		return max( 0, self::quota_limit() - self::calls_today() );
	}

	public static function over_quota(): bool {
		return self::quota_limit() > 0 && self::calls_today() >= self::quota_limit();
	}

	private static function month_key(): string {
		return 'bqw_openai_month_' . gmdate( 'Y-m' );
	}

	public static function accumulate_cost( float $cost ): void {
		$key = self::month_key();
		$cur = (float) get_option( $key, 0.0 );
		update_option( $key, round( $cur + $cost, 6 ), false );
	}

	public static function month_cost(): float {
		return (float) get_option( self::month_key(), 0.0 );
	}

	public static function last_call_meta(): array {
		return (array) get_option( 'bqw_openai_last_call', [] );
	}

	private static function log_call( array $entry ): void {
		$entry['ts'] = gmdate( 'Y-m-d H:i:s' );
		update_option( 'bqw_openai_last_call', $entry, false );
		self::bump_calls_today();

		Logger::info( 'OpenAI call', $entry );

		// Dedicated openai.log file.
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		$line = sprintf( "[%s] %s\n", $entry['ts'], wp_json_encode( $entry ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . '/openai.log', $line, FILE_APPEND | LOCK_EX );
	}
}
