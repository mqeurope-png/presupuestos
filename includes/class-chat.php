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
				'message' => __( 'Great. What are you interested in doing? You can pick more than one.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => [
					[ 'label' => __( 'Print on objects (UV-LED)', 'bomedia-quote-wizard' ), 'icon' => 'package', 'value' => 'uv_objects' ],
					[ 'label' => __( 'Print on textile', 'bomedia-quote-wizard' ),         'icon' => 'tshirt',  'value' => 'textile'    ],
					[ 'label' => __( 'Cut/engrave with laser', 'bomedia-quote-wizard' ),   'icon' => 'metal',   'value' => 'laser'      ],
					[ 'label' => __( 'Labels / packaging', 'bomedia-quote-wizard' ),       'icon' => 'package', 'value' => 'packaging'  ],
					[ 'label' => __( "I'm not sure", 'bomedia-quote-wizard' ),             'icon' => 'help',    'value' => 'unsure'     ],
				],
				'allow_free_text' => true,
				'next'            => 'application',
				'saves_to'        => 'task_types',
			],
			'application' => [
				'message' => __( "Got it. What kind of products will you mostly produce?", 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => [
					[ 'label' => __( 'Textile / fashion', 'bomedia-quote-wizard' ),  'icon' => 'tshirt' ],
					[ 'label' => __( 'Packaging', 'bomedia-quote-wizard' ),          'icon' => 'package' ],
					[ 'label' => __( 'Promotional / merch', 'bomedia-quote-wizard' ),'icon' => 'gift' ],
					[ 'label' => __( 'Industrial', 'bomedia-quote-wizard' ),         'icon' => 'factory' ],
					[ 'label' => __( 'Decoration / signage', 'bomedia-quote-wizard' ),'icon' => 'home' ],
					[ 'label' => __( 'Other', 'bomedia-quote-wizard' ),              'icon' => 'help' ],
				],
				'allow_free_text' => true,
				'next'            => 'materials',
				'saves_to'        => 'applications',
			],
			'materials' => [
				'message' => __( 'Which materials will you print on most often?', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => [
					[ 'label' => __( 'Cotton', 'bomedia-quote-wizard' ),    'icon' => 'fabric' ],
					[ 'label' => __( 'Polyester', 'bomedia-quote-wizard' ), 'icon' => 'fabric' ],
					[ 'label' => __( 'PVC / acrylic', 'bomedia-quote-wizard' ), 'icon' => 'plastic' ],
					[ 'label' => __( 'Wood', 'bomedia-quote-wizard' ),      'icon' => 'wood' ],
					[ 'label' => __( 'Metal', 'bomedia-quote-wizard' ),     'icon' => 'metal' ],
					[ 'label' => __( 'Glass', 'bomedia-quote-wizard' ),     'icon' => 'glass' ],
					[ 'label' => __( 'Leather', 'bomedia-quote-wizard' ),   'icon' => 'leather' ],
					[ 'label' => __( 'Cardboard', 'bomedia-quote-wizard' ), 'icon' => 'cardboard' ],
				],
				'allow_free_text' => true,
				'next'            => 'volume',
				'saves_to'        => 'materials',
			],
			'volume' => [
				'message' => __( 'Roughly, what monthly volume do you expect?', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => [
					[ 'label' => __( '< 100 / month', 'bomedia-quote-wizard' ),   'icon' => 'volume-low' ],
					[ 'label' => __( '100 – 500', 'bomedia-quote-wizard' ),      'icon' => 'volume-mid' ],
					[ 'label' => __( '500 – 2000', 'bomedia-quote-wizard' ),     'icon' => 'volume-mid' ],
					[ 'label' => __( '> 2000', 'bomedia-quote-wizard' ),         'icon' => 'volume-high' ],
					[ 'label' => __( "Don't know yet", 'bomedia-quote-wizard' ), 'icon' => 'help' ],
				],
				'allow_free_text' => true,
				'next'            => 'format',
				'saves_to'        => 'volume',
			],
			'format' => [
				'message' => __( 'What max piece size do you need? You can skip this question.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => [
					[ 'label' => __( 'A4 (210×297 mm)', 'bomedia-quote-wizard' ), 'icon' => 'format-small' ],
					[ 'label' => __( 'A3 (297×420 mm)', 'bomedia-quote-wizard' ), 'icon' => 'format-medium' ],
					[ 'label' => __( '60×90 cm', 'bomedia-quote-wizard' ),        'icon' => 'format-large' ],
					[ 'label' => __( 'Larger than 60×90', 'bomedia-quote-wizard' ), 'icon' => 'format-large' ],
				],
				'allow_free_text' => true,
				'allow_skip'      => true,
				'next'            => 'budget',
				'saves_to'        => 'formats',
			],
			'budget' => [
				'message' => __( 'Any rough budget? Optional.', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => [
					[ 'label' => __( 'Up to 5,000 €', 'bomedia-quote-wizard' ),    'icon' => 'budget-low' ],
					[ 'label' => __( '5,000 – 15,000 €', 'bomedia-quote-wizard' ), 'icon' => 'budget-mid' ],
					[ 'label' => __( '15,000 – 30,000 €', 'bomedia-quote-wizard' ),'icon' => 'budget-mid' ],
					[ 'label' => __( 'More than 30,000 €', 'bomedia-quote-wizard' ),'icon' => 'budget-high' ],
					[ 'label' => __( "Rather not say", 'bomedia-quote-wizard' ),    'icon' => 'help' ],
				],
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
		$step   = $script['welcome'];
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
			$reply = self::ai_followup( $session_id, $lang );
			Conversations::append( $session_id, 'assistant', $reply, [ 'step_id' => $step_id ] );
			wp_send_json_success( [ 'step' => [
				'id'              => $step_id,
				'type'            => 'free_chat',
				'message'         => $reply,
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
				$recs = self::generate_recommendations( $session_id, $lang );
				$msg  = empty( $recs )
					? __( "Your case is specific. Drop your details below and our team will reach out with a tailored proposal.", 'bomedia-quote-wizard' )
					: __( "Based on what you told me, here are the machines I'd recommend:", 'bomedia-quote-wizard' );
				Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => $next_id, 'recommendations' => count( $recs ) ] );
				wp_send_json_success( [ 'step' => [
					'id'              => $next_id,
					'type'            => 'recommendations',
					'message'         => $msg,
					'recommendations' => $recs,
					'cta'             => __( 'Continue and request quote →', 'bomedia-quote-wizard' ),
					'cta_to'          => empty( $recs ) ? 'contact' : 'free_chat',
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
		$allowed     = [];
		foreach ( $task_values as $tv ) {
			if ( ! empty( $mapping[ $tv ] ) && is_array( $mapping[ $tv ] ) ) {
				foreach ( $mapping[ $tv ] as $b ) {
					$allowed[] = (string) $b;
				}
			}
		}
		$allowed = array_values( array_unique( array_filter( $allowed ) ) );
		if ( ! empty( $allowed ) ) {
			$before = count( $products );
			$products = array_values( array_filter( $products, static function ( $p ) use ( $allowed ) {
				return in_array( (string) ( $p['brand'] ?? '' ), $allowed, true );
			} ) );
			self::log_recommendation( $session_id, $task_values, $allowed, $before, count( $products ) );
			// If filter blanked the catalog (e.g. mismatched brand ids), fall back to all to avoid empty result.
			if ( empty( $products ) ) {
				$products = self::catalog_for_prompt( $lang );
			}
		} else {
			self::log_recommendation( $session_id, $task_values, [], count( $products ), count( $products ) );
		}

		if ( empty( $products ) ) {
			return [];
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

		$index = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$slug = (string) ( $p['id'] ?? '' );
			if ( '' !== $slug ) {
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

	private static function ai_followup( string $session_id, string $lang ) {
		$enc = (string) Settings::get( 'openai_api_key', '' );
		$key = '' !== $enc ? Settings::decrypt( $enc ) : '';
		if ( '' === $key || OpenAI_Client::over_quota() ) {
			return __( "Sorry, I can't answer that right now. Drop your details below and our team will help you.", 'bomedia-quote-wizard' );
		}
		$model    = (string) Settings::get( 'openai_model', 'gpt-4o-mini' ) ?: 'gpt-4o-mini';
		$products = self::catalog_for_prompt( $lang );
		$system   = 'You are a Bomedia sales assistant. The user just received product recommendations. Answer their follow-up question briefly (<60 words) in language ' . $lang . '. Never invent capabilities not in the catalog. If asked about prices, say a personalised quote will be sent. Catalog (only these are available): ' . wp_json_encode( $products );

		$messages = [ [ 'role' => 'system', 'content' => $system ] ];
		foreach ( Conversations::history( $session_id, 30 ) as $row ) {
			$messages[] = [
				'role'    => ( 'assistant' === $row['role'] ) ? 'assistant' : 'user',
				'content' => (string) $row['content'],
			];
		}

		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.5,
		];
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 18,
			'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return __( "Sorry, I had a glitch. Try again in a moment.", 'bomedia-quote-wizard' );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$msg  = isset( $json['choices'][0]['message']['content'] ) ? trim( (string) $json['choices'][0]['message']['content'] ) : '';
		// Cost accounting.
		$usage = $json['usage'] ?? [];
		OpenAI_Client::accumulate_cost( OpenAI_Client::estimate_cost( $model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) ) );
		return $msg ?: __( 'Could you rephrase that?', 'bomedia-quote-wizard' );
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

	private static function log_recommendation( string $session_id, array $task_values, array $allowed_brands, int $before_count, int $after_count ): void {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$line = sprintf(
			"[%s] session=%s task_types=%s brands=%s products=%d/%d\n",
			gmdate( 'Y-m-d H:i:s' ),
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
