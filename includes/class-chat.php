<?php
/**
 * Chatbot orchestrator: builds the OpenAI prompt with conversation history,
 * persists every turn, parses the structured assistant reply.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Chat {

	private const TEMP        = 0.4;
	private const HISTORY_MAX = 30;

	public static function register(): void {
		add_action( 'wp_ajax_bqw_chat_message',        [ self::class, 'handle_message' ] );
		add_action( 'wp_ajax_nopriv_bqw_chat_message', [ self::class, 'handle_message' ] );
	}

	/* ============================================================
	 * AJAX entry
	 * ============================================================ */

	public static function handle_message(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$session_id = (string) ( $_POST['session_id'] ?? '' );
		$content    = sanitize_textarea_field( wp_unslash( (string) ( $_POST['content'] ?? '' ) ) );
		$buttons    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['button_clicks'] ?? [] ) );
		$language   = sanitize_text_field( (string) ( $_POST['language'] ?? '' ) );

		if ( '' === $session_id || ( '' === $content && empty( $buttons ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Empty message.', 'bomedia-quote-wizard' ) ], 400 );
		}

		// Build the user-visible content from clicked options + free text.
		$visible = trim( implode( ' · ', array_filter( $buttons ) ) );
		if ( '' !== $content ) {
			$visible = '' === $visible ? $content : $visible . ' — ' . $content;
		}

		Conversations::append( $session_id, 'user', $visible, [
			'buttons'  => $buttons,
			'language' => $language,
		] );

		// Quota gate (hard cap).
		if ( OpenAI_Client::over_quota() ) {
			$fallback = __( "We've received your answers. We'll get back to you soon.", 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $fallback, [ 'fallback' => true ] );
			wp_send_json_success( [ 'reply' => self::reply_envelope( $fallback ) ] );
		}

		$reply = self::ask_openai( $session_id, $language );
		if ( is_wp_error( $reply ) ) {
			$msg = __( "Sorry, I'm having trouble responding right now. Try again in a moment, or skip to send your details.", 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $msg, [ 'error' => $reply->get_error_message() ] );
			wp_send_json_success( [ 'reply' => self::reply_envelope( $msg ) ] );
		}

		$payload = self::reply_envelope_from( $reply );
		Conversations::append( $session_id, 'assistant', (string) $payload['message'], $payload );
		wp_send_json_success( [ 'reply' => $payload ] );
	}

	/* ============================================================
	 * OpenAI call
	 * ============================================================ */

	public static function ask_openai( string $session_id, string $language ) {
		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key ) {
			return new WP_Error( 'bqw_openai_not_configured', 'Missing OpenAI key' );
		}

		$model    = (string) Settings::get( 'openai_model', 'gpt-4o-mini' ) ?: 'gpt-4o-mini';
		$lang     = '' !== $language ? $language : substr( get_locale(), 0, 2 );
		$products = self::catalog_for_prompt( $lang );

		$system = self::system_prompt( $lang, $products );

		$messages   = [ [ 'role' => 'system', 'content' => $system ] ];
		$history    = Conversations::history( $session_id, self::HISTORY_MAX );
		foreach ( $history as $row ) {
			$role = ( 'assistant' === $row['role'] ) ? 'assistant' : 'user';
			$messages[] = [ 'role' => $role, 'content' => (string) $row['content'] ];
		}

		$body = [
			'model'           => $model,
			'messages'        => $messages,
			'temperature'     => self::TEMP,
			'response_format' => [ 'type' => 'json_object' ],
		];

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			return new WP_Error( 'bqw_openai_http_' . $code, $json['error']['message'] ?? sprintf( 'HTTP %d', $code ) );
		}
		$content = $json['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'bqw_openai_bad_json', 'malformed JSON from assistant' );
		}

		// Cost accounting (best-effort).
		$usage = $json['usage'] ?? [];
		$cost  = OpenAI_Client::estimate_cost( $model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) );
		OpenAI_Client::accumulate_cost( $cost );
		update_option( 'bqw_openai_last_call', [
			'ts'       => gmdate( 'Y-m-d H:i:s' ),
			'model'    => $model,
			'prompt'   => (int) ( $usage['prompt_tokens'] ?? 0 ),
			'output'   => (int) ( $usage['completion_tokens'] ?? 0 ),
			'cost_usd' => $cost,
		], false );

		return $parsed;
	}

	private static function reply_envelope( string $msg ): array {
		return [
			'message'  => $msg,
			'options'  => [],
			'free_text_hint' => '',
			'recommendations' => [],
			'ready_for_contact' => false,
			'extracted_fields'  => [],
		];
	}

	private static function reply_envelope_from( array $parsed ): array {
		$envelope = self::reply_envelope( (string) ( $parsed['message'] ?? '' ) );

		if ( isset( $parsed['options'] ) && is_array( $parsed['options'] ) ) {
			foreach ( $parsed['options'] as $opt ) {
				if ( is_array( $opt ) && isset( $opt['label'] ) ) {
					$envelope['options'][] = [
						'label'     => sanitize_text_field( (string) $opt['label'] ),
						'icon_hint' => sanitize_text_field( (string) ( $opt['icon_hint'] ?? '' ) ),
					];
				} elseif ( is_string( $opt ) ) {
					$envelope['options'][] = [ 'label' => sanitize_text_field( $opt ), 'icon_hint' => '' ];
				}
			}
		}
		$envelope['free_text_hint']    = isset( $parsed['free_text_hint'] ) ? sanitize_text_field( (string) $parsed['free_text_hint'] ) : '';
		$envelope['ready_for_contact'] = ! empty( $parsed['ready_for_contact'] );

		// Hydrate recommendations from the catalog when the assistant signals.
		if ( ! empty( $parsed['recommendations'] ) && is_array( $parsed['recommendations'] ) ) {
			$lang  = substr( get_locale(), 0, 2 );
			$index = [];
			foreach ( Catalog_Client::get_products() as $p ) {
				$slug = (string) ( $p['id'] ?? '' );
				if ( '' !== $slug ) {
					$index[ $slug ] = $p;
				}
			}
			foreach ( $parsed['recommendations'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$slug = (string) ( $r['product_id'] ?? '' );
				if ( '' === $slug || ! isset( $index[ $slug ] ) ) {
					continue;
				}
				$loc = Catalog_Client::localize_product( $index[ $slug ], $lang );
				$envelope['recommendations'][] = array_merge( $loc, [
					'score'   => (int) max( 0, min( 100, (int) ( $r['score'] ?? 0 ) ) ),
					'reasons' => array_slice( array_map( 'sanitize_text_field', (array) ( $r['reasons'] ?? [] ) ), 0, 2 ),
					'source'  => 'catalog',
				] );
			}
		}
		if ( isset( $parsed['extracted_fields'] ) && is_array( $parsed['extracted_fields'] ) ) {
			$envelope['extracted_fields'] = self::sanitize_extracted_fields( $parsed['extracted_fields'] );
		}
		return $envelope;
	}

	public static function sanitize_extracted_fields( array $f ): array {
		return [
			'applications' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $f['applications'] ?? [] ) ), 'strlen' ) ),
			'materials'    => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $f['materials'] ?? [] ) ), 'strlen' ) ),
			'volume'       => sanitize_text_field( (string) ( $f['volume'] ?? '' ) ),
			'format'       => array_values( array_filter( array_map( 'sanitize_text_field', is_array( $f['format'] ?? null ) ? $f['format'] : [ (string) ( $f['format'] ?? '' ) ] ), 'strlen' ) ),
			'budget'       => sanitize_text_field( (string) ( $f['budget'] ?? '' ) ),
			'first_name'   => sanitize_text_field( (string) ( $f['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $f['last_name'] ?? '' ) ),
			'company'      => sanitize_text_field( (string) ( $f['company'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $f['email'] ?? '' ) ),
			'phone'        => sanitize_text_field( (string) ( $f['phone'] ?? '' ) ),
			'country'      => sanitize_text_field( (string) ( $f['country'] ?? '' ) ),
		];
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private static function catalog_for_prompt( string $lang ): array {
		$out = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$loc   = Catalog_Client::localize_product( $p, $lang );
			$out[] = [
				'product_id' => $loc['id'],
				'name'       => $loc['name'],
				'brand'      => $loc['brand'],
				'area'       => $loc['area'],
				'feat1'      => $loc['feat1'],
				'feat2'      => $loc['feat2'],
				'desc'       => $loc['desc'],
			];
		}
		return array_slice( $out, 0, 50 );
	}

	private static function system_prompt( string $lang, array $products ): string {
		return 'You are a helpful sales assistant for Bomedia SL, a European distributor of UV-LED industrial printers, DTG/DTF textile printers, and laser cutting/engraving machines (artisJet, MBO, Flux, smartJet, pimpam brands).' . "\n\n"
			. 'You are conducting a conversational needs-assessment with a website visitor in their language. Be warm, brief and curious. Avoid corporate jargon.' . "\n\n"
			. 'CONVERSATION GOAL: understand the visitor\'s production task, materials, monthly volume, max format, budget; then propose up to 3 machines that match. After they pick or accept the recommendations, ask for first name, company, email, phone, country, in any natural order. When you have enough, set ready_for_contact=true to invite them to submit. Do not actually submit — the frontend will show a contact form.' . "\n\n"
			. 'STRICT RULES:' . "\n"
			. '- Always reply in language: "' . $lang . '". If the user writes in another language, switch to it.' . "\n"
			. '- Never invent products outside the catalog provided below.' . "\n"
			. '- Never quote prices. If asked, say the price is confirmed with a personalised quote.' . "\n"
			. '- The cards on the frontend already render the product name, image, area, feat1, feat2 and link. Do NOT repeat them in the message text.' . "\n"
			. '- Keep "message" under 60 words.' . "\n"
			. '- "options" are short clickable answers that move the conversation forward (multi-select friendly). Use empty array when free-text is more appropriate.' . "\n"
			. '- Show recommendations only once you have at least task type + one of (materials, volume, format).' . "\n\n"
			. 'OUTPUT JSON SCHEMA (always exactly this structure):' . "\n"
			. '{' . "\n"
			. '  "message": "<your message in user\'s language>",' . "\n"
			. '  "options": [{"label":"<short text>", "icon_hint":"tshirt|package|gift|factory|home|sign|fabric|plastic|wood|metal|glass|ceramic|leather|cardboard|paper|volume-low|volume-mid|volume-high|format-small|format-medium|format-large|budget-low|budget-mid|budget-high|help"}, ...] | [],' . "\n"
			. '  "free_text_hint": "<short placeholder for the free-text input, optional>",' . "\n"
			. '  "recommendations": [{"product_id":"<slug from catalog>", "score":<0-100>, "reasons":["<short reason>", "<short reason>"]}] | [],' . "\n"
			. '  "ready_for_contact": <bool>,' . "\n"
			. '  "extracted_fields": {' . "\n"
			. '    "applications":[],"materials":[],"volume":"","format":[],"budget":"",' . "\n"
			. '    "first_name":"","last_name":"","company":"","email":"","phone":"","country":""' . "\n"
			. '  }' . "\n"
			. '}' . "\n\n"
			. 'CATALOG (JSON, only use these product_ids):' . "\n"
			. wp_json_encode( $products );
	}

	/* ============================================================
	 * Lead-time helpers used by Ajax::handle_submit
	 * ============================================================ */

	/**
	 * Calls OpenAI a second time at submit-time to summarise the conversation
	 * and pull structured fields. Best-effort: returns [] on any failure.
	 */
	public static function summarise( string $session_id, string $language = '' ): array {
		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key ) {
			return [];
		}
		$model = (string) Settings::get( 'openai_model', 'gpt-4o-mini' ) ?: 'gpt-4o-mini';
		$lang  = '' !== $language ? $language : substr( get_locale(), 0, 2 );
		$rows  = Conversations::history( $session_id, 60 );
		if ( empty( $rows ) ) {
			return [];
		}
		$convo = '';
		foreach ( $rows as $r ) {
			$prefix = ( 'assistant' === $r['role'] ) ? 'BOT:' : 'USER:';
			$convo .= $prefix . ' ' . $r['content'] . "\n";
		}

		$system = 'Summarise the conversation between a Bomedia sales bot and a website visitor. Return JSON with two fields: "summary" (5 short bullet points in language ' . $lang . ', the lead\'s key points so a human salesperson can pick it up) and "fields" (a flat object with applications[], materials[], volume, format[], budget, first_name, last_name, company, email, phone, country, all best-effort, empty when unknown). JSON only.';

		$body = [
			'model'           => $model,
			'messages'        => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $convo ],
			],
			'temperature'     => 0.1,
			'response_format' => [ 'type' => 'json_object' ],
		];
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [];
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = $json['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) {
			return [];
		}
		$summary = isset( $parsed['summary'] ) && is_array( $parsed['summary'] ) ? array_map( 'sanitize_text_field', $parsed['summary'] ) : [];
		$fields  = isset( $parsed['fields'] ) && is_array( $parsed['fields'] ) ? self::sanitize_extracted_fields( $parsed['fields'] ) : [];

		// Cost accounting.
		$usage = $json['usage'] ?? [];
		OpenAI_Client::accumulate_cost( OpenAI_Client::estimate_cost( $model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) ) );

		return [ 'summary' => $summary, 'fields' => $fields ];
	}
}
