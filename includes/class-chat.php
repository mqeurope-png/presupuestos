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

	public static function register(): void {
		add_action( 'wp_ajax_bqw_chat_init',           [ self::class, 'handle_init' ] );
		add_action( 'wp_ajax_nopriv_bqw_chat_init',    [ self::class, 'handle_init' ] );
		add_action( 'wp_ajax_bqw_chat_advance',        [ self::class, 'handle_advance' ] );
		add_action( 'wp_ajax_nopriv_bqw_chat_advance', [ self::class, 'handle_advance' ] );
	}

	/* ============================================================
	 * Scripted backbone — the conversation is a fixed graph; OpenAI
	 * is only invoked for free-text mapping, recommendations, and the
	 * post-recommendation Q&A. This guarantees the bot always opens
	 * with a real first question instead of waiting for the user.
	 * ============================================================ */

	public static function script(): array {
		$w = Settings::get_wizard();

		// Parse user-configurable lines into option arrays.
		$task_opts = self::parse_task_type_options( (string) ( $w['task_type_options'] ?? '' ) );
		// Pad to icons we know per stable value.
		$task_icons = [
			'uv_objects' => 'package',
			'textile'    => 'tshirt',
			'laser'      => 'metal',
			'packaging'  => 'package',
			'unsure'     => 'help',
		];
		$task_options = [];
		foreach ( $task_opts as $t ) {
			$task_options[] = [
				'label' => $t['label'],
				'value' => $t['value'],
				'icon'  => $task_icons[ $t['value'] ] ?? '',
			];
		}

		$application_options = self::lines_to_options( (string) ( $w['application_options'] ?? '' ) );
		$materials_options   = self::lines_to_options( (string) ( $w['materials_options'] ?? '' ) );
		$volume_options      = self::lines_to_options( (string) ( $w['volume_options'] ?? '' ) );
		$format_options      = self::lines_to_options( (string) ( $w['matchmaker_format_options'] ?? '' ) );
		$budget_options      = self::lines_to_options( (string) ( $w['matchmaker_budget_options'] ?? '' ) );

		return [
			'welcome' => [
				'message' => __( "Hi! I'm the Bomedia assistant. I'll help you find the right printing or laser machine in 2 minutes. Ready?", 'bomedia-quote-wizard' ),
				'type'    => 'options',
				'options' => [
					[ 'label' => __( "Yes, let's go", 'bomedia-quote-wizard' ),    'next' => 'task_type' ],
					[ 'label' => __( 'I already know which machine', 'bomedia-quote-wizard' ), 'next' => '__woo_classic' ],
				],
			],
			'task_type' => [
				'enabled' => ! empty( $w['enable_task_type'] ),
				'message' => __( 'Great. What are you interested in doing? You can pick more than one.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $task_options,
				'allow_free_text' => true,
				'next'            => 'application',
				'saves_to'        => 'task_types',
			],
			'application' => [
				'enabled' => ! empty( $w['enable_application'] ),
				'message' => __( "Got it. What kind of products will you mostly produce?", 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $application_options,
				'allow_free_text' => true,
				'next'            => 'materials',
				'saves_to'        => 'applications',
			],
			'materials' => [
				'enabled' => ! empty( $w['enable_materials'] ),
				'message' => __( 'Which materials will you print on most often?', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $materials_options,
				'allow_free_text' => true,
				'next'            => 'volume',
				'saves_to'        => 'materials',
			],
			'volume' => [
				'enabled' => ! empty( $w['enable_volume'] ),
				'message' => __( 'Roughly, what monthly volume do you expect?', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => $volume_options,
				'allow_free_text' => true,
				'next'            => 'format',
				'saves_to'        => 'volume',
			],
			'format' => [
				'enabled' => ! empty( $w['enable_format'] ),
				'message' => __( 'What max piece size do you need? You can skip this question.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $format_options,
				'allow_free_text' => true,
				'allow_skip'      => true,
				'next'            => 'budget',
				'saves_to'        => 'formats',
			],
			'budget' => [
				'enabled' => ! empty( $w['enable_budget'] ),
				'message' => __( 'Any rough budget? Optional.', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => $budget_options,
				'allow_free_text' => true,
				'allow_skip'      => true,
				'next'            => 'recommendations',
				'saves_to'        => 'budget',
			],
			'recommendations' => [
				'type' => 'ai_recommendations',
				'next' => 'free_chat',
			],
			'free_chat' => [
				'type' => 'ai_open',
			],
			'contact' => [
				'message' => __( "Perfect. To send your personalised quote, I just need your details.", 'bomedia-quote-wizard' ),
				'type'    => 'form',
			],
		];
	}

	/**
	 * Parses a textarea of "value|Label" lines (one per line). Falls back
	 * gracefully when no `|` is provided.
	 */
	public static function parse_task_type_options( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		$out   = [];
		foreach ( $lines as $ln ) {
			$ln = trim( $ln );
			if ( '' === $ln ) {
				continue;
			}
			$parts = explode( '|', $ln, 2 );
			$value = sanitize_key( trim( (string) $parts[0] ) );
			$label = isset( $parts[1] ) ? trim( (string) $parts[1] ) : trim( (string) $parts[0] );
			if ( '' === $value ) {
				continue;
			}
			$out[] = [ 'value' => $value, 'label' => $label ];
		}
		return $out;
	}

	private static function lines_to_options( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		$out   = [];
		foreach ( $lines as $ln ) {
			$ln = trim( $ln );
			if ( '' === $ln ) {
				continue;
			}
			$out[] = [ 'label' => $ln ];
		}
		return $out;
	}

	/**
	 * Walks the `next` chain skipping any step whose `enabled` flag is false.
	 * Returns the first enabled target, or null on dead end / cycle.
	 */
	private static function resolve_next( array $script, ?string $start ): ?string {
		$cursor = $start;
		$guard  = 0;
		while ( $cursor && isset( $script[ $cursor ] ) && $guard < 20 ) {
			$guard++;
			$step = $script[ $cursor ];
			// Steps without explicit 'enabled' (welcome, task_type fallback, recommendations, free_chat, contact)
			// are always treated as enabled.
			$enabled = ! array_key_exists( 'enabled', $step ) || ! empty( $step['enabled'] );
			$has_options = ! empty( $step['options'] );
			$is_text_only = empty( $has_options ) && in_array( $step['type'] ?? '', [ 'options', 'multi_options', 'single_option' ], true );
			if ( $enabled && ! $is_text_only ) {
				return $cursor;
			}
			$cursor = $step['next'] ?? null;
		}
		return null;
	}

	/* ============================================================
	 * AJAX entrypoints
	 * ============================================================ */

	public static function handle_init(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$session_id = sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) );
		if ( '' === $session_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing session.', 'bomedia-quote-wizard' ) ], 400 );
		}

		$script = self::script();

		// If task_type is disabled, the welcome step's "Yes, let's go" should
		// jump directly to the next enabled step.
		if ( isset( $script['welcome']['options'] ) ) {
			foreach ( $script['welcome']['options'] as $idx => $opt ) {
				if ( ( $opt['next'] ?? '' ) === 'task_type' ) {
					$resolved = self::resolve_next( $script, 'task_type' );
					if ( $resolved && 'task_type' !== $resolved ) {
						$script['welcome']['options'][ $idx ]['next'] = $resolved;
					}
				}
			}
		}

		$step     = $script['welcome'];
		$envelope = self::step_to_envelope( 'welcome', $step );

		// Persist the welcome message only once per session.
		$existing = Conversations::history( $session_id, 1 );
		if ( empty( $existing ) ) {
			Conversations::append( $session_id, 'assistant', (string) $step['message'], [ 'step_id' => 'welcome' ] );
		}

		wp_send_json_success( [ 'step' => $envelope ] );
	}

	public static function handle_advance(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$session_id = sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) );
		$step_id    = sanitize_text_field( (string) ( $_POST['step_id'] ?? '' ) );
		$selected   = array_values( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['selected'] ?? [] ) ), 'strlen' ) );
		$free_text  = sanitize_textarea_field( wp_unslash( (string) ( $_POST['free_text'] ?? '' ) ) );
		$skip       = ! empty( $_POST['skip'] );
		$language   = sanitize_text_field( (string) ( $_POST['language'] ?? '' ) );
		$lang       = '' !== $language ? $language : substr( get_locale(), 0, 2 );

		if ( '' === $session_id || '' === $step_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing context.', 'bomedia-quote-wizard' ) ], 400 );
		}

		$script = self::script();
		if ( ! isset( $script[ $step_id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown step.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$current = $script[ $step_id ];

		// Persist the user's reply (visible string).
		$visible = trim( implode( ' · ', $selected ) );
		if ( '' !== $free_text ) {
			$visible = '' === $visible ? $free_text : $visible . ' — ' . $free_text;
		}
		if ( '' === $visible && $skip ) {
			$visible = __( '(skipped)', 'bomedia-quote-wizard' );
		}
		// Map labels to stable option values (used for the task→brand filter).
		$values = [];
		foreach ( $selected as $label ) {
			foreach ( (array) ( $current['options'] ?? [] ) as $opt ) {
				if ( ( $opt['label'] ?? '' ) === $label && isset( $opt['value'] ) ) {
					$values[] = (string) $opt['value'];
				}
			}
		}

		if ( '' !== $visible ) {
			Conversations::append( $session_id, 'user', $visible, [
				'step_id' => $step_id,
				'selected' => $selected,
				'values'  => $values,
				'free_text' => $free_text,
				'skipped' => $skip,
			] );
		}

		// Free-chat step → route to OpenAI Q&A and stay on this step.
		if ( 'ai_open' === ( $current['type'] ?? '' ) ) {
			$result  = self::ai_followup( $session_id, $lang );
			$reply   = (string) ( $result['reply'] ?? '' );
			$actions = (array) ( $result['actions'] ?? [] );
			Conversations::append( $session_id, 'assistant', $reply, [ 'step_id' => $step_id, 'actions' => count( $actions ) ] );
			wp_send_json_success( [ 'step' => [
				'id'              => $step_id,
				'type'            => 'free_chat',
				'message'         => $reply,
				'actions'         => $actions,
				'allow_free_text' => true,
				'cta'             => __( 'I have enough info — request quote →', 'bomedia-quote-wizard' ),
				'cta_to'          => 'contact',
			] ] );
		}

		// Free-text mapping when the step has predefined options but the user typed.
		if ( '' !== $free_text && empty( $selected ) && ! empty( $current['allow_free_text'] ) && ! empty( $current['options'] ) ) {
			$mapped = self::map_free_text( $current, $free_text, $lang );
			if ( ! empty( $mapped['matched'] ) ) {
				$selected = [ $mapped['matched'] ];
			} elseif ( ! empty( $mapped['clarification'] ) ) {
				Conversations::append( $session_id, 'assistant', $mapped['clarification'], [ 'step_id' => $step_id, 'clarification' => true ] );
				$envelope = self::step_to_envelope( $step_id, $current );
				$envelope['message'] = $mapped['clarification'];
				wp_send_json_success( [ 'step' => $envelope ] );
			}
		}

		// Determine next step.
		$next_id = null;
		if ( $skip && ! empty( $current['allow_skip'] ) ) {
			$next_id = $current['next'] ?? null;
		} elseif ( ! empty( $selected ) ) {
			// Per-option `next` (e.g. welcome → __woo_classic).
			foreach ( $selected as $val ) {
				foreach ( (array) ( $current['options'] ?? [] ) as $o ) {
					$opt_label = (string) ( $o['label'] ?? '' );
					if ( isset( $o['next'] ) && $opt_label === $val ) {
						$next_id = $o['next'];
						break 2;
					}
				}
			}
			if ( ! $next_id ) {
				$next_id = $current['next'] ?? null;
			}
		} elseif ( '' !== $free_text ) {
			$next_id = $current['next'] ?? null;
		}

		// Skip any disabled steps in the chain (admin disabled "format", etc.).
		if ( $next_id && '__woo_classic' !== $next_id ) {
			$resolved = self::resolve_next( $script, $next_id );
			if ( $resolved ) {
				$next_id = $resolved;
			}
		}

		// Special meta target — direct shortcut to the contact form.
		if ( '__woo_classic' === $next_id ) {
			$msg = __( 'Got it — drop your details below and our team will reach out.', 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => 'knows_machine' ] );
			wp_send_json_success( [ 'step' => [
				'id'      => 'knows_machine',
				'type'    => 'form',
				'message' => $msg,
				'origin_tag' => 'knows-machine',
			] ] );
		}

		if ( ! $next_id || ! isset( $script[ $next_id ] ) ) {
			$msg = __( 'Thanks!', 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => 'end' ] );
			wp_send_json_success( [ 'step' => [ 'id' => 'end', 'type' => 'end', 'message' => $msg ] ] );
		}
		$next = $script[ $next_id ];

		switch ( $next['type'] ?? '' ) {
			case 'ai_recommendations':
				$recs       = self::generate_recommendations( $session_id, $lang );
				$no_match   = empty( $recs );
				$msg        = $no_match
					? __( "Your case is specific. Drop your details below and our team will reach out with a tailored proposal.", 'bomedia-quote-wizard' )
					: __( "Based on what you told me, here are the machines I'd recommend:", 'bomedia-quote-wizard' );
				Conversations::append( $session_id, 'assistant', $msg, [
					'step_id'         => $next_id,
					'recommendations' => count( $recs ),
					'no_match'        => $no_match,
				] );
				wp_send_json_success( [ 'step' => [
					'id'              => $next_id,
					'type'            => 'recommendations',
					'message'         => $msg,
					'recommendations' => $recs,
					'no_match'        => $no_match,
					'cta'             => $no_match ? __( 'Send my details →', 'bomedia-quote-wizard' ) : __( 'Continue and request quote →', 'bomedia-quote-wizard' ),
					'cta_to'          => $no_match ? 'contact' : 'free_chat',
					'free_chat_hint'  => __( 'Ask me anything about these machines, or skip to send.', 'bomedia-quote-wizard' ),
				] ] );

			case 'ai_open':
				$msg = __( 'Anything you want to ask about these machines? Or skip straight to sending your details.', 'bomedia-quote-wizard' );
				Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => $next_id ] );
				wp_send_json_success( [ 'step' => [
					'id'              => $next_id,
					'type'            => 'free_chat',
					'message'         => $msg,
					'allow_free_text' => true,
					'cta'             => __( 'I have enough info — request quote →', 'bomedia-quote-wizard' ),
					'cta_to'          => 'contact',
				] ] );

			case 'form':
				$msg = (string) $next['message'];
				Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => $next_id ] );
				wp_send_json_success( [ 'step' => [
					'id'      => $next_id,
					'type'    => 'form',
					'message' => $msg,
				] ] );

			default:
				$envelope = self::step_to_envelope( $next_id, $next );
				Conversations::append( $session_id, 'assistant', (string) $next['message'], [ 'step_id' => $next_id ] );
				wp_send_json_success( [ 'step' => $envelope ] );
		}
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private static function step_to_envelope( string $id, array $step ): array {
		$opts = [];
		foreach ( (array) ( $step['options'] ?? [] ) as $o ) {
			$opts[] = [
				'label' => sanitize_text_field( (string) ( $o['label'] ?? '' ) ),
				'icon'  => sanitize_text_field( (string) ( $o['icon'] ?? '' ) ),
			];
		}
		$type = $step['type'] ?? 'options';
		return [
			'id'              => $id,
			'type'            => $type,
			'message'         => (string) ( $step['message'] ?? '' ),
			'options'         => $opts,
			'allow_free_text' => ! empty( $step['allow_free_text'] ),
			'allow_skip'      => ! empty( $step['allow_skip'] ),
			'multi_select'    => 'multi_options' === $type || 'options' === $type,
		];
	}

	/**
	 * Aggregates the user's answers per saves_to slot from the conversation
	 * history (so we never need server-side state).
	 */
	private static function collect_answers( string $session_id ): array {
		$script = self::script();
		$rows   = Conversations::history( $session_id, 100 );
		$out    = [
			'task_types'        => [],
			'task_types_values' => [],
			'applications'      => [],
			'materials'         => [],
			'volume'            => '',
			'formats'           => [],
			'budget'            => '',
		];
		foreach ( $rows as $r ) {
			if ( 'user' !== $r['role'] ) {
				continue;
			}
			$meta = is_string( $r['metadata'] ) ? json_decode( (string) $r['metadata'], true ) : [];
			$step = isset( $meta['step_id'] ) ? (string) $meta['step_id'] : '';
			if ( '' === $step || ! isset( $script[ $step ] ) ) {
				continue;
			}
			$saves_to = $script[ $step ]['saves_to'] ?? '';
			if ( '' === $saves_to ) {
				continue;
			}
			$selected = (array) ( $meta['selected'] ?? [] );
			$values   = array_values( array_filter( array_map( 'strval', (array) ( $meta['values'] ?? [] ) ) ) );
			$free     = trim( (string) ( $meta['free_text'] ?? '' ) );
			$picks    = $selected;
			if ( '' !== $free && empty( $picks ) ) {
				$picks = [ $free ];
			}
			if ( is_array( $out[ $saves_to ] ) ) {
				$out[ $saves_to ] = array_values( array_unique( array_merge( $out[ $saves_to ], $picks ) ) );
			} else {
				$out[ $saves_to ] = $picks[0] ?? $out[ $saves_to ];
			}
			// Track stable values for task_type so we can map to brands.
			if ( 'task_types' === $saves_to && ! empty( $values ) ) {
				$out['task_types_values'] = array_values( array_unique( array_merge( $out['task_types_values'], $values ) ) );
			}
		}
		return $out;
	}

	private static function generate_recommendations( string $session_id, string $lang ): array {
		$answers = self::collect_answers( $session_id );

		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key ) {
			return [];
		}
		if ( OpenAI_Client::over_quota() ) {
			return [];
		}

		// Filter catalog by task_type → brand mapping (configurable from admin).
		$products    = self::catalog_for_prompt( $lang );
		$task_values = (array) ( $answers['task_types_values'] ?? [] );
		$mapping     = (array) Settings::get( 'task_brand_map', [] );
		$allowed_raw = [];
		foreach ( $task_values as $tv ) {
			if ( ! empty( $mapping[ $tv ] ) && is_array( $mapping[ $tv ] ) ) {
				foreach ( $mapping[ $tv ] as $b ) {
					$allowed_raw[] = (string) $b;
				}
			}
		}
		$allowed_raw = array_values( array_unique( array_filter( $allowed_raw ) ) );
		// Case-insensitive set for comparing against product['brand'].
		$allowed_norm = array_map( 'strtolower', $allowed_raw );

		$before = count( $products );
		if ( ! empty( $allowed_norm ) ) {
			$products = array_values( array_filter( $products, static function ( $p ) use ( $allowed_norm ) {
				$pb = strtolower( (string) ( $p['brand'] ?? '' ) );
				return in_array( $pb, $allowed_norm, true );
			} ) );
			$after = count( $products );
			self::log_recommendation( $session_id, $task_values, $allowed_raw, $before, $after, $after === 0 );
			// CHANGED in v1.7.5: no silent fallback. Return empty so the
			// chat says "your case is specific" and gets a no-match-found tag.
			if ( empty( $products ) ) {
				return [];
			}
		} else {
			self::log_recommendation( $session_id, $task_values, [], $before, $before, false );
		}

		$client = new OpenAI_Client();
		$result = $client->recommend( [
			'application' => array_merge( $answers['task_types'], $answers['applications'] ),
			'materials'   => $answers['materials'],
			'volume'      => $answers['volume'],
			'format'      => $answers['formats'],
			'budget'      => $answers['budget'],
			'lang'        => $lang,
		], $products );

		if ( is_wp_error( $result ) || empty( $result['recommendations'] ) ) {
			return [];
		}

		// CRITICAL: index only the filtered set so an LLM hallucination of a
		// product slug from outside the brand whitelist gets dropped.
		$allowed_ids = array_flip( array_map( static function ( $p ) { return (string) ( $p['product_id'] ?? '' ); }, $products ) );
		unset( $allowed_ids[''] );
		$index = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$slug = (string) ( $p['id'] ?? '' );
			if ( '' !== $slug && isset( $allowed_ids[ $slug ] ) ) {
				$index[ $slug ] = $p;
			}
		}
		$cards = [];
		foreach ( (array) $result['recommendations'] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$slug = (string) ( $r['product_id'] ?? '' );
			if ( '' === $slug || ! isset( $index[ $slug ] ) ) {
				continue;
			}
			$loc = Catalog_Client::localize_product( $index[ $slug ], $lang );
			$cards[] = array_merge( $loc, [
				'score'   => (int) max( 0, min( 100, (int) ( $r['score'] ?? 0 ) ) ),
				'reasons' => array_slice( array_map( 'sanitize_text_field', (array) ( $r['reasons'] ?? [] ) ), 0, 2 ),
				'source'  => 'catalog',
			] );
		}
		usort( $cards, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return array_slice( $cards, 0, 3 );
	}

	private static function map_free_text( array $step, string $free_text, string $lang ): array {
		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key || OpenAI_Client::over_quota() ) {
			return [ 'matched' => null, 'clarification' => null ];
		}
		$labels = array_map( static function ( $o ) { return (string) ( $o['label'] ?? '' ); }, (array) ( $step['options'] ?? [] ) );

		$system = 'Map the user\'s free-text answer to one of the predefined options (case-insensitive, intent-based) for a sales chat. If unambiguous, return matched (verbatim from options). If ambiguous or off-topic, return clarification: a short polite reply in language ' . $lang . ' asking the user to clarify (under 25 words). JSON only.';
		$user   = 'Predefined options: ' . wp_json_encode( $labels ) . "\n"
			. 'User reply: "' . $free_text . '"' . "\n"
			. 'Question: "' . ( $step['message'] ?? '' ) . '"';

		$body = [
			'model'           => (string) Settings::get( 'openai_model', 'gpt-4o-mini' ) ?: 'gpt-4o-mini',
			'messages'        => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $user ],
			],
			'temperature'     => 0.0,
			'response_format' => [ 'type' => 'json_object' ],
		];
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 12,
			'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'matched' => null, 'clarification' => null ];
		}
		$json    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = $json['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( (string) $content, true );
		if ( ! is_array( $parsed ) ) {
			return [ 'matched' => null, 'clarification' => null ];
		}
		$matched = isset( $parsed['matched'] ) ? sanitize_text_field( (string) $parsed['matched'] ) : '';
		// Verify matched is one of the actual labels.
		if ( '' !== $matched && ! in_array( $matched, $labels, true ) ) {
			$matched = '';
		}
		$clarif = isset( $parsed['clarification'] ) ? sanitize_text_field( (string) $parsed['clarification'] ) : '';
		return [ 'matched' => $matched ?: null, 'clarification' => $clarif ?: null ];
	}

	/**
	 * Free-chat handler. Returns ['reply' => string, 'actions' => array].
	 * Actions can be: update_recommendations, select_product, go_to_contact.
	 */
	private static function ai_followup( string $session_id, string $lang ): array {
		$fallback_reply = __( "Sorry, I can't answer that right now. Drop your details below and our team will help you.", 'bomedia-quote-wizard' );

		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key || OpenAI_Client::over_quota() ) {
			return [ 'reply' => $fallback_reply, 'actions' => [] ];
		}
		$model    = (string) Settings::get( 'openai_model', 'gpt-4o-mini' ) ?: 'gpt-4o-mini';
		$products = self::catalog_for_prompt( $lang );

		$system = 'You are the Bomedia sales assistant in free-chat mode after the customer received recommendations. ' . "\n"
			. 'Reply ONLY in valid JSON of the shape: {"reply": "<text in ' . $lang . ', under 60 words>", "actions": [<zero or more actions>]}. "actions" can be omitted (treat as empty).' . "\n"
			. 'AVAILABLE ACTIONS:' . "\n"
			. '- {"type":"update_recommendations","product_ids":["slug1","slug2","slug3"]} — when the user asks to see machines from another brand/category. Up to 3 product_ids, all from the catalog provided. The frontend will replace the side-panel cards.' . "\n"
			. '- {"type":"select_product","product_id":"slug"} — when the user explicitly says they want to pick a single product by name.' . "\n"
			. '- {"type":"go_to_contact"} — when the user clearly asks to send their details / get a quote.' . "\n"
			. 'STRICT RULES:' . "\n"
			. '- Never recommend product_ids that are not in the catalog.' . "\n"
			. '- Never quote concrete prices. If asked, say a personalised quote will be sent.' . "\n"
			. '- Do NOT include actions when the user is just asking a Q&A question (e.g. "does the X print on leather?"). Return only "reply".' . "\n"
			. 'CATALOG (use only these product_ids): ' . wp_json_encode( $products );

		$messages = [ [ 'role' => 'system', 'content' => $system ] ];
		foreach ( Conversations::history( $session_id, 30 ) as $row ) {
			$messages[] = [
				'role'    => ( 'assistant' === $row['role'] ) ? 'assistant' : 'user',
				'content' => (string) $row['content'],
			];
		}

		$body = [
			'model'           => $model,
			'messages'        => $messages,
			'temperature'     => 0.4,
			'response_format' => [ 'type' => 'json_object' ],
		];
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'reply' => __( "Sorry, I had a glitch. Try again in a moment.", 'bomedia-quote-wizard' ), 'actions' => [] ];
		}
		$json    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = $json['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( (string) $content, true );
		// Cost accounting.
		$usage = $json['usage'] ?? [];
		OpenAI_Client::accumulate_cost( OpenAI_Client::estimate_cost( $model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) ) );

		$reply = is_array( $parsed ) && isset( $parsed['reply'] ) ? trim( (string) $parsed['reply'] ) : '';
		if ( '' === $reply ) {
			$reply = is_string( $content ) ? trim( $content ) : '';
		}
		if ( '' === $reply ) {
			$reply = __( 'Could you rephrase that?', 'bomedia-quote-wizard' );
		}

		// Hydrate actions.
		$actions     = [];
		$catalog_idx = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$slug = (string) ( $p['id'] ?? '' );
			if ( '' !== $slug ) {
				$catalog_idx[ $slug ] = $p;
			}
		}
		$raw_actions = ( is_array( $parsed ) && isset( $parsed['actions'] ) && is_array( $parsed['actions'] ) ) ? $parsed['actions'] : [];
		foreach ( $raw_actions as $a ) {
			if ( ! is_array( $a ) || empty( $a['type'] ) ) {
				continue;
			}
			$type = sanitize_key( (string) $a['type'] );
			if ( 'update_recommendations' === $type ) {
				$ids = array_filter( array_map( 'sanitize_text_field', (array) ( $a['product_ids'] ?? [] ) ), 'strlen' );
				$cards = [];
				foreach ( $ids as $slug ) {
					if ( ! isset( $catalog_idx[ $slug ] ) ) {
						continue;
					}
					$loc = Catalog_Client::localize_product( $catalog_idx[ $slug ], $lang );
					$cards[] = array_merge( $loc, [ 'score' => 0, 'reasons' => [], 'source' => 'catalog' ] );
					if ( count( $cards ) >= 3 ) {
						break;
					}
				}
				if ( ! empty( $cards ) ) {
					$actions[] = [ 'type' => 'update_recommendations', 'products' => $cards ];
				}
			} elseif ( 'select_product' === $type ) {
				$slug = sanitize_text_field( (string) ( $a['product_id'] ?? '' ) );
				if ( '' !== $slug && isset( $catalog_idx[ $slug ] ) ) {
					$loc = Catalog_Client::localize_product( $catalog_idx[ $slug ], $lang );
					$actions[] = [
						'type'    => 'select_product',
						'product' => array_merge( $loc, [ 'source' => 'catalog' ] ),
					];
				}
			} elseif ( 'go_to_contact' === $type ) {
				$actions[] = [ 'type' => 'go_to_contact' ];
			}
		}

		return [ 'reply' => $reply, 'actions' => $actions ];
	}

	/* ============================================================
	 * Field sanitiser used by submit + summariser
	 * ============================================================ */

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
	 * Catalog helpers
	 * ============================================================ */

	private static function log_recommendation( string $session_id, array $task_values, array $allowed_brands, int $before_count, int $after_count, bool $no_match = false ): void {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$line = sprintf(
			"[%s]%s session=%s task_types=%s brands=%s products=%d/%d\n",
			gmdate( 'Y-m-d H:i:s' ),
			$no_match ? ' [NO MATCH]' : '',
			substr( $session_id, 0, 8 ),
			implode( ',', $task_values ),
			implode( ',', $allowed_brands ),
			$after_count,
			$before_count
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . '/recommendations.log', $line, FILE_APPEND | LOCK_EX );
	}

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
