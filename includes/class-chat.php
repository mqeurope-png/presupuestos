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
		add_action( 'wp_ajax_bqw_chat_init',            [ self::class, 'handle_init' ] );
		add_action( 'wp_ajax_nopriv_bqw_chat_init',     [ self::class, 'handle_init' ] );
		add_action( 'wp_ajax_bqw_chat_advance',         [ self::class, 'handle_advance' ] );
		add_action( 'wp_ajax_nopriv_bqw_chat_advance',  [ self::class, 'handle_advance' ] );
		add_action( 'wp_ajax_bqw_partial_save',         [ self::class, 'handle_partial_save' ] );
		add_action( 'wp_ajax_nopriv_bqw_partial_save',  [ self::class, 'handle_partial_save' ] );
		add_action( 'wp_ajax_bqw_partial_step',         [ self::class, 'handle_partial_step' ] );
		add_action( 'wp_ajax_nopriv_bqw_partial_step',  [ self::class, 'handle_partial_step' ] );
	}

	public static function handle_partial_save(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$session_id = sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) );
		$name       = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
		$email      = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
		if ( '' === $session_id || '' === $name || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid name and email.', 'bomedia-quote-wizard' ) ], 400 );
		}
		Partial_Leads::upsert( $session_id, $name, $email );
		wp_send_json_success( [ 'ok' => true ] );
	}

	public static function handle_partial_step(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$session_id = sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) );
		$step       = sanitize_text_field( (string) ( $_POST['step'] ?? '' ) );
		if ( '' === $session_id || '' === $step ) {
			wp_send_json_error( [ 'message' => 'Missing context' ], 400 );
		}
		Partial_Leads::update_step( $session_id, $step );
		wp_send_json_success( [ 'ok' => true ] );
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
				'message' => __( "Hi! How would you like to choose your machine?", 'bomedia-quote-wizard' ),
				'type'    => 'welcome_cards',
				'options' => [
					[
						'label'    => __( 'Help me choose', 'bomedia-quote-wizard' ),
						'subtitle' => __( 'AI recommends the ideal machine in 5 questions', 'bomedia-quote-wizard' ),
						'icon'     => 'help',
						'next'     => 'task_type',
						'value'    => 'guided',
					],
					[
						'label'    => sprintf(
							/* translators: %s: site display name */
							__( 'Browse %s catalog', 'bomedia-quote-wizard' ),
							(string) Settings::get( 'site_display_name', 'this site' )
						),
						'subtitle' => __( 'Products available on this store', 'bomedia-quote-wizard' ),
						'icon'     => 'package',
						'next'     => '__site_catalog',
						'value'    => 'site_catalog',
					],
					[
						'label'    => __( 'Browse the full Bomedia catalog', 'bomedia-quote-wizard' ),
						'subtitle' => __( 'All machines from the group (artisJet, MBO, Flux, PimPam, SmartJet)', 'bomedia-quote-wizard' ),
						'icon'     => 'factory',
						'next'     => '__bomedia_catalog',
						'value'    => 'bomedia_catalog',
					],
				],
			],
			'task_type' => [
				'enabled' => ! empty( $w['enable_task_type'] ),
				'message' => __( 'Great. What are you interested in doing? You can pick more than one.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $task_options,
				'allow_free_text' => false,
				'next'            => 'application',
				'saves_to'        => 'task_types',
			],
			'application' => [
				'enabled' => ! empty( $w['enable_application'] ),
				'message' => __( "Got it. What kind of products will you mostly produce?", 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $application_options,
				'allow_free_text' => false,
				'next'            => 'materials',
				'saves_to'        => 'applications',
			],
			'materials' => [
				'enabled' => ! empty( $w['enable_materials'] ),
				'message' => __( 'Which materials will you print on most often?', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $materials_options,
				'allow_free_text' => false,
				'next'            => 'volume',
				'saves_to'        => 'materials',
			],
			'volume' => [
				'enabled' => ! empty( $w['enable_volume'] ),
				'message' => __( 'Roughly, what monthly volume do you plan to produce?', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => $volume_options,
				'allow_free_text' => false,
				'next'            => 'format',
				'saves_to'        => 'volume',
			],
			'format' => [
				'enabled' => ! empty( $w['enable_format'] ),
				'message' => __( 'What max piece size do you need? You can skip this question.', 'bomedia-quote-wizard' ),
				'type'    => 'multi_options',
				'options' => $format_options,
				'allow_free_text' => false,
				'allow_skip'      => true,
				'next'            => 'budget',
				'saves_to'        => 'formats',
			],
			'budget' => [
				'enabled' => ! empty( $w['enable_budget'] ),
				'message' => __( 'Any rough budget? Optional.', 'bomedia-quote-wizard' ),
				'type'    => 'single_option',
				'options' => $budget_options,
				'allow_free_text' => false,
				'allow_skip'      => true,
				'next'            => 'recommendations',
				'saves_to'        => 'budget',
			],
			'recommendations' => [
				'type' => 'ai_recommendations',
				'next' => 'contact',
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

		// v1.7.6 — free-text input is no longer accepted on option steps,
		// and the post-recommendation free_chat step has been removed. The
		// dialog is fully closed (button-driven). Free text only lives in
		// the contact form's "Message (optional)" field.

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
		$is_meta = in_array( $next_id, [ '__site_catalog', '__bomedia_catalog' ], true );
		if ( $next_id && ! $is_meta ) {
			$resolved = self::resolve_next( $script, $next_id );
			if ( $resolved ) {
				$next_id = $resolved;
			}
		}

		// Welcome card "Browse {site} catalog" → real WC products from the
		// admin's selected_categories. Brand-mapping does not apply here
		// (the user explicitly asked for the local store catalog).
		if ( '__site_catalog' === $next_id ) {
			$msg = __( "Here's our store catalog. Pick the machines you're interested in.", 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => 'site_catalog' ] );
			wp_send_json_success( [ 'step' => [
				'id'         => 'site_catalog',
				'type'       => 'site_catalog',
				'message'    => $msg,
				'products'   => self::woo_catalog_for_browse(),
				'categories' => self::woo_categories_for_browse(),
			] ] );
		}

		// Welcome card "Browse Bomedia catalog" → grouped view of all
		// Supabase products, grouped by brand.
		if ( '__bomedia_catalog' === $next_id ) {
			$msg = __( "Here's the full Bomedia catalog. Pick the machines you'd like a quote for.", 'bomedia-quote-wizard' );
			Conversations::append( $session_id, 'assistant', $msg, [ 'step_id' => 'bomedia_catalog' ] );
			wp_send_json_success( [ 'step' => [
				'id'      => 'bomedia_catalog',
				'type'    => 'bomedia_catalog',
				'message' => $msg,
				'products' => self::bomedia_catalog_for_browse( $lang ),
				'brands'   => array_values( array_filter( array_map( static function ( $b ) {
					return [ 'id' => (string) ( $b['id'] ?? '' ), 'label' => (string) ( $b['label'] ?? $b['id'] ?? '' ) ];
				}, Catalog_Client::get_brands() ), static function ( $b ) { return '' !== $b['id']; } ) ),
				'tasks'    => self::task_filter_options(),
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
					'allow_free_text' => false,
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

		// Build a brand → task_value reverse index (one brand may belong to
		// several tasks; first hit wins for the per-card mini-tag).
		$brand_to_task = [];
		foreach ( $mapping as $task_value => $brand_list ) {
			foreach ( (array) $brand_list as $b ) {
				$bn = strtolower( (string) $b );
				if ( '' !== $bn && ! isset( $brand_to_task[ $bn ] ) ) {
					$brand_to_task[ $bn ] = (string) $task_value;
				}
			}
		}

		$base_input = [
			'task_types'  => $task_values,
			'application' => $answers['applications'],
			'materials'   => $answers['materials'],
			'volume'      => $answers['volume'],
			'format'      => $answers['formats'],
			'budget'      => $answers['budget'],
			'lang'        => $lang,
		];

		$result = $client->recommend( $base_input, $products );

		if ( is_wp_error( $result ) || empty( $result['recommendations'] ) ) {
			return [];
		}

		// Multi-task balance verification + 1 retry.
		if ( count( $task_values ) >= 2 ) {
			$coverage_first = self::coverage_for( (array) $result['recommendations'], $products, $brand_to_task );
			$missing        = array_diff( $task_values, array_keys( array_filter( $coverage_first ) ) );
			self::log_balance( $session_id, 1, $coverage_first, $missing );
			if ( ! empty( $missing ) ) {
				$followup = sprintf(
					'Your previous reply covered task_types %s but the customer also asked for %s. Return a balanced top 3 that includes at least one product per requested task type.',
					wp_json_encode( array_keys( array_filter( $coverage_first ) ) ),
					wp_json_encode( array_values( $missing ) )
				);
				$retry = $client->recommend( array_merge( $base_input, [ '_followup' => $followup ] ), $products );
				if ( ! is_wp_error( $retry ) && ! empty( $retry['recommendations'] ) ) {
					$coverage_second = self::coverage_for( (array) $retry['recommendations'], $products, $brand_to_task );
					$still_missing   = array_diff( $task_values, array_keys( array_filter( $coverage_second ) ) );
					self::log_balance( $session_id, 2, $coverage_second, $still_missing );
					if ( count( $still_missing ) < count( $missing ) ) {
						$result = $retry;
					}
				}
			}
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
			$loc       = Catalog_Client::localize_product( $index[ $slug ], $lang );
			$brand_n   = strtolower( (string) ( $loc['brand'] ?? '' ) );
			$task_type = $brand_to_task[ $brand_n ] ?? '';
			$cards[] = array_merge( $loc, [
				'score'      => (int) max( 0, min( 100, (int) ( $r['score'] ?? 0 ) ) ),
				'reasons'    => array_slice( array_map( 'sanitize_text_field', (array) ( $r['reasons'] ?? [] ) ), 0, 2 ),
				'source'     => 'catalog',
				'task_type'  => $task_type,
				'task_label' => self::task_label_for( $task_type ),
			] );
		}
		usort( $cards, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return array_slice( $cards, 0, 6 );
	}

	/**
	 * Counts how many of the LLM-returned recommendations belong to each
	 * task_value via the brand→task index.
	 */
	private static function coverage_for( array $recommendations, array $products_for_lookup, array $brand_to_task ): array {
		$by_id = [];
		foreach ( $products_for_lookup as $p ) {
			$by_id[ (string) ( $p['product_id'] ?? '' ) ] = $p;
		}
		$coverage = [];
		foreach ( $recommendations as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$slug = (string) ( $r['product_id'] ?? '' );
			if ( '' === $slug || ! isset( $by_id[ $slug ] ) ) {
				continue;
			}
			$brand = strtolower( (string) ( $by_id[ $slug ]['brand'] ?? '' ) );
			$task  = $brand_to_task[ $brand ] ?? '';
			if ( '' !== $task ) {
				$coverage[ $task ] = ( $coverage[ $task ] ?? 0 ) + 1;
			}
		}
		return $coverage;
	}

	private static function log_balance( string $session_id, int $intent, array $coverage, array $missing ): void {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$pairs = [];
		foreach ( $coverage as $k => $n ) {
			$pairs[] = $k . ':' . $n;
		}
		$line = sprintf(
			"[%s] session=%s intent=%d coverage=[%s] missing=[%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			substr( $session_id, 0, 8 ),
			$intent,
			implode( ',', $pairs ),
			implode( ',', $missing ),
			empty( $missing ) ? 'OK' : 'BIASED'
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . '/recommendations.log', $line, FILE_APPEND | LOCK_EX );
	}

	private static function task_label_for( string $task_value ): string {
		switch ( $task_value ) {
			case 'uv_objects': return '🖨️ ' . __( 'UV-LED', 'bomedia-quote-wizard' );
			case 'textile':    return '👕 ' . __( 'Textile', 'bomedia-quote-wizard' );
			case 'laser':      return '✂️ ' . __( 'Laser', 'bomedia-quote-wizard' );
			case 'packaging':  return '📦 ' . __( 'Packaging', 'bomedia-quote-wizard' );
			default:           return '';
		}
	}

	public static function task_filter_options(): array {
		$mapping = (array) Settings::get( 'task_brand_map', [] );
		$out     = [];
		foreach ( [ 'uv_objects', 'textile', 'laser', 'packaging' ] as $tv ) {
			$brands = isset( $mapping[ $tv ] ) ? array_values( array_filter( array_map( 'strval', (array) $mapping[ $tv ] ) ) ) : [];
			$out[]  = [
				'value'  => $tv,
				'label'  => self::task_label_for( $tv ),
				'brands' => array_map( 'strtolower', $brands ),
			];
		}
		return $out;
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

	/**
	 * Builds the Supabase catalog payload for the "Browse Bomedia catalog"
	 * welcome card: the full visible product list, localized, with brand,
	 * image, area, feat1/feat2, link. No price (per UX policy).
	 */
	public static function bomedia_catalog_for_browse( string $lang ): array {
		$out = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$loc = Catalog_Client::localize_product( $p, $lang );
			if ( '' === $loc['id'] ) {
				continue;
			}
			$out[] = [
				'id'    => $loc['id'],
				'name'  => $loc['name'],
				'brand' => $loc['brand'],
				'badge' => $loc['badge'],
				'area'  => $loc['area'],
				'feat1' => $loc['feat1'],
				'feat2' => $loc['feat2'],
				'desc'  => $loc['desc'],
				'img'   => $loc['img'],
				'link'  => $loc['link'],
			];
		}
		return $out;
	}

	/**
	 * Lists the WooCommerce categories the admin selected in
	 * Settings → Wizard (dual list).
	 */
	public static function woo_categories_for_browse(): array {
		$ids = array_map( 'absint', (array) Settings::get( 'selected_categories', [] ) );
		$out = [];
		foreach ( $ids as $cid ) {
			$term = get_term( $cid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = [
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => (int) $term->count,
				];
			}
		}
		return $out;
	}

	/**
	 * Returns published WooCommerce products from EVERY admin-selected
	 * category, respecting per-category mode ("all" vs. manual IDs) and
	 * preserving the admin's drag-and-drop order. No price exposed.
	 */
	public static function woo_catalog_for_browse(): array {
		$cat_ids = array_map( 'absint', (array) Settings::get( 'selected_categories', [] ) );
		$pbc     = (array) Settings::get( 'products_by_category', [] );
		if ( empty( $cat_ids ) ) {
			self::log_site_catalog( [], [], 0 );
			return [];
		}

		$out         = [];
		$seen        = [];
		$mode_per    = [];
		$cat_counts  = [];

		foreach ( $cat_ids as $cid ) {
			$cfg  = isset( $pbc[ $cid ] ) && is_array( $pbc[ $cid ] ) ? $pbc[ $cid ] : [];
			$mode = ( ( $cfg['mode'] ?? '' ) === 'manual' ) ? 'manual' : 'all';
			$ids  = array_values( array_unique( array_map( 'absint', (array) ( $cfg['ids'] ?? [] ) ) ) );

			$cat_term = get_term( $cid, 'product_cat' );
			$slug     = ( $cat_term && ! is_wp_error( $cat_term ) ) ? $cat_term->slug : (string) $cid;
			$mode_per[ $slug ] = $mode;

			$args = [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			];
			if ( 'manual' === $mode && ! empty( $ids ) ) {
				$args['post__in'] = $ids;
				$args['orderby']  = 'post__in';
			} else {
				$args['tax_query'] = [
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => [ $cid ],
						'include_children' => false,
					],
				];
			}

			$query = new \WP_Query( $args );
			$loaded = 0;
			foreach ( $query->posts as $p ) {
				$pid = (int) $p->ID;
				if ( isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ] = true;
				$loaded++;

				$terms     = wp_get_post_terms( $pid, 'product_cat' );
				$cat_names = [];
				$cat_slugs = [];
				if ( $terms && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $t ) {
						$cat_names[] = $t->name;
						$cat_slugs[] = $t->slug;
					}
				}
				$out[] = [
					'id'            => $pid,
					'name'          => $p->post_title,
					'image'         => get_the_post_thumbnail_url( $pid, 'medium' ) ?: '',
					'category_name' => $cat_term && ! is_wp_error( $cat_term ) ? $cat_term->name : implode( ', ', $cat_names ),
					'category_slug' => $slug,
					'category_slugs' => $cat_slugs,
					'permalink'     => get_permalink( $pid ),
					'source'        => 'woo',
				];
			}
			$cat_counts[ $slug ] = $loaded;
		}

		self::log_site_catalog( $mode_per, $cat_counts, count( $out ) );
		return $out;
	}

	private static function log_site_catalog( array $mode_per, array $cat_counts, int $total ): void {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		$mode_pairs = [];
		foreach ( $mode_per as $slug => $mode ) {
			$cnt = $cat_counts[ $slug ] ?? 0;
			$mode_pairs[] = sprintf( '%s:%s/%d', $slug, $mode, $cnt );
		}
		$line = sprintf(
			"[%s] categories=[%s] mode_per_cat={%s} products_loaded=%d\n",
			gmdate( 'Y-m-d H:i:s' ),
			implode( ',', array_keys( $mode_per ) ),
			implode( ',', $mode_pairs ),
			$total
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . '/site-catalog.log', $line, FILE_APPEND | LOCK_EX );
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
