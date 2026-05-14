<?php
/**
 * Front-end AJAX endpoint for wizard submission.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	private const MIN_FILL_TIME = 3; // seconds

	private static ?Ajax $instance = null;

	public static function instance(): Ajax {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'wp_ajax_bqw_submit', [ $this, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_bqw_submit', [ $this, 'handle_submit' ] );
		add_action( 'wp_ajax_bqw_get_products_by_category', [ $this, 'handle_get_products' ] );
		add_action( 'wp_ajax_nopriv_bqw_get_products_by_category', [ $this, 'handle_get_products' ] );
		add_action( 'wp_ajax_bqw_matchmaker', [ $this, 'handle_matchmaker' ] );
		add_action( 'wp_ajax_nopriv_bqw_matchmaker', [ $this, 'handle_matchmaker' ] );
	}

	/**
	 * Asks OpenAI to score products against the user's matchmaker answers.
	 * Returns up to 3 recommendations with reasons (or a fallback message).
	 */
	public function handle_matchmaker(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}

		$ans = wp_unslash( $_POST['answers'] ?? [] );
		if ( ! is_array( $ans ) ) {
			$ans = [];
		}
		$applications = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $ans['application'] ?? [] ) ), 'strlen' ) );
		$materials    = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $ans['materials'] ?? [] ) ), 'strlen' ) );
		$volume       = sanitize_text_field( (string) ( $ans['volume'] ?? '' ) );
		// Format is multi-select since v1.6.1; accept array or scalar for backwards-compat.
		$raw_format = $ans['format'] ?? [];
		$format     = array_values( array_filter( array_map( 'sanitize_text_field', is_array( $raw_format ) ? $raw_format : [ $raw_format ] ), 'strlen' ) );
		$budget       = sanitize_text_field( (string) ( $ans['budget'] ?? '' ) );

		$enable_ai = (int) Settings::get( 'enable_ai_matchmaker', 1 ) === 1;
		if ( ! $enable_ai ) {
			wp_send_json_success( [
				'recommendations' => [],
				'ai_failed'       => true,
				'fallback'        => __( "We've received your answers. We'll get back to you with a personalized recommendation.", 'bomedia-quote-wizard' ),
			] );
		}

		// Quota gate.
		if ( OpenAI_Client::over_quota() ) {
			Logger::error( 'OpenAI daily cap reached', [ 'cap' => OpenAI_Client::quota_limit() ] );
			wp_send_json_success( [
				'recommendations' => [],
				'ai_failed'       => true,
				'fallback'        => __( "We've received your answers. We'll get back to you with a personalized recommendation.", 'bomedia-quote-wizard' ),
			] );
		}

		$lang_code = substr( get_locale(), 0, 2 );

		// Source the catalogue from Supabase (only products + brands, no PII).
		$products = self::build_matchmaker_products_from_catalog( $lang_code );
		if ( empty( $products ) ) {
			wp_send_json_success( [
				'recommendations' => [],
				'ai_failed'       => true,
				'fallback'        => __( "We don't have machines configured for the wizard yet. Please contact us.", 'bomedia-quote-wizard' ),
			] );
		}

		$client = new OpenAI_Client();
		$result = $client->recommend(
			[
				'application' => $applications,
				'materials'   => $materials,
				'volume'      => $volume,
				'format'      => $format, // multi-select array; client joins with comma.
				'budget'      => $budget,
				'lang'        => $lang_code,
			],
			$products
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_success( [
				'recommendations' => [],
				'ai_failed'       => true,
				'fallback'        => __( "We've received your answers. We'll get back to you with a personalized recommendation.", 'bomedia-quote-wizard' ),
				'error'           => $result->get_error_message(),
			] );
		}

		$recs    = isset( $result['recommendations'] ) && is_array( $result['recommendations'] ) ? $result['recommendations'] : [];
		$fallback = isset( $result['fallback_message'] ) ? (string) $result['fallback_message'] : '';

		// Hydrate Supabase recommendations using the cached catalog.
		$catalog_index = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$slug = (string) ( $p['id'] ?? '' );
			if ( '' !== $slug ) {
				$catalog_index[ $slug ] = $p;
			}
		}

		$cards = [];
		foreach ( $recs as $r ) {
			$slug = isset( $r['product_id'] ) ? (string) $r['product_id'] : '';
			if ( '' === $slug || ! isset( $catalog_index[ $slug ] ) ) {
				continue;
			}
			$loc = Catalog_Client::localize_product( $catalog_index[ $slug ], $lang_code );
			$reasons = isset( $r['reasons'] ) && is_array( $r['reasons'] ) ? array_slice( array_map( 'sanitize_text_field', $r['reasons'] ), 0, 2 ) : [];
			$score   = (int) max( 0, min( 100, (int) ( $r['score'] ?? 0 ) ) );
			$cards[] = array_merge( $loc, [
				'score'   => $score,
				'reasons' => $reasons,
				'source'  => 'catalog',
			] );
		}

		usort( $cards, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		$cards = array_slice( $cards, 0, 3 );

		wp_send_json_success( [
			'recommendations' => $cards,
			'ai_failed'       => empty( $cards ) && '' === $fallback,
			'fallback'        => $fallback,
		] );
	}

	/**
	 * Builds the products payload from the Supabase catalog cache, localized
	 * to the user's language. Truncates to 50 if needed, prioritising the
	 * earlier entries in the upstream feed.
	 */
	private static function build_matchmaker_products_from_catalog( string $lang_code ): array {
		$cached = Catalog_Client::get_products();
		$products = [];
		foreach ( $cached as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$loc = Catalog_Client::localize_product( $p, $lang_code );
			if ( '' === $loc['id'] || '' === $loc['name'] ) {
				continue;
			}
			$products[] = [
				'product_id'  => $loc['id'], // slug — what OpenAI returns.
				'name'        => $loc['name'],
				'brand'       => $loc['brand'],
				'area'        => $loc['area'],
				'feat1'       => $loc['feat1'],
				'feat2'       => $loc['feat2'],
				'desc'        => $loc['desc'],
				'price'       => $loc['price'],
			];
		}

		if ( count( $products ) > 50 ) {
			Logger::info( 'Matchmaker: truncating Supabase catalog to 50', [ 'total' => count( $products ) ] );
			$products = array_slice( $products, 0, 50 );
		}
		return $products;
	}

	/**
	 * Returns published products for a given product_cat term_id (no children
	 * by default), each with image and a small set of feature attributes
	 * (Print size / Speed if present).
	 */
	public function handle_get_products(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'bomedia-quote-wizard' ) ], 400 );
		}
		$cat_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		if ( ! $cat_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing category.', 'bomedia-quote-wizard' ) ], 400 );
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			// menu_order respects drag-reorder in WC's product list; title is tiebreaker.
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $cat_id,
					'include_children' => false,
				],
			],
		];

		// Honour per-category manual filter if present.
		$pbc = (array) Settings::get( 'products_by_category', [] );
		if ( isset( $pbc[ $cat_id ]['mode'] ) && 'manual' === $pbc[ $cat_id ]['mode'] ) {
			$ids = array_map( 'absint', (array) ( $pbc[ $cat_id ]['ids'] ?? [] ) );
			$ids = array_values( array_filter( $ids ) );
			if ( empty( $ids ) ) {
				wp_send_json_success( [ 'products' => [] ] );
			}
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		}

		$query    = new \WP_Query( $args );
		$products = [];
		foreach ( $query->posts as $p ) {
			$products[] = [
				'id'         => (int) $p->ID,
				'name'       => $p->post_title,
				'sku'        => self::get_product_sku( (int) $p->ID ),
				'image'      => get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '',
				'attributes' => self::extract_feature_attributes( (int) $p->ID ),
			];
		}

		wp_send_json_success( [ 'products' => $products ] );
	}

	private static function get_product_sku( int $product_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$p = wc_get_product( $product_id );
		return $p ? (string) $p->get_sku() : '';
	}

	/**
	 * Reads up to two "feature" attributes from a WC product. Looks for any
	 * attribute whose label matches Print size / Format / Speed / Velocidad
	 * / Formato máximo. Returns [{label, value}, ...] or empty array.
	 */
	private static function extract_feature_attributes( int $product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}

		$wanted_haystack = [
			'formato máximo' => 1,
			'formato maximo' => 1,
			'print size'     => 1,
			'print format'   => 1,
			'velocidad'      => 1,
			'speed'          => 1,
		];

		$out = [];
		foreach ( $product->get_attributes() as $attr ) {
			$slug  = method_exists( $attr, 'get_name' ) ? $attr->get_name() : '';
			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $slug ) : $slug;
			$norm  = strtolower( trim( (string) $label ) );
			if ( ! isset( $wanted_haystack[ $norm ] ) ) {
				continue;
			}
			$value = $product->get_attribute( $slug );
			if ( '' === $value ) {
				continue;
			}
			$out[] = [
				'label' => $label,
				'value' => $value,
			];
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	public function handle_submit(): void {
		if ( ! check_ajax_referer( 'bqw_submit', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Reload the page.', 'bomedia-quote-wizard' ) ], 400 );
		}

		// Honeypot.
		if ( ! empty( $_POST['bqw_hp'] ) ) {
			wp_send_json_success( [ 'message' => 'OK' ] ); // silently accept
		}

		// Min fill time.
		$started = isset( $_POST['bqw_started'] ) ? absint( $_POST['bqw_started'] ) : 0;
		if ( $started && ( time() - $started ) < self::MIN_FILL_TIME ) {
			wp_send_json_error( [ 'message' => __( 'Please take a moment before submitting.', 'bomedia-quote-wizard' ) ], 400 );
		}

		// Captcha (math, reCAPTCHA v2/v3, Turnstile, hCaptcha).
		if ( Captcha::is_enabled() ) {
			$req = wp_unslash( $_POST );
			$req['remote_ip'] = self::client_ip();
			$result = Captcha::provider()->verify( $req );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
			}
		}

		$data = $this->collect_and_validate();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [ 'message' => $data->get_error_message() ], 400 );
		}

		// If we have a chat session, snapshot the conversation and ask OpenAI
		// for a 5-point summary + best-effort field extraction.
		if ( ! empty( $data['session_id'] ) ) {
			$history = Conversations::history( (string) $data['session_id'], 100 );
			$data['conversation_history'] = $history;

			// Track whether the user actually conversed (>= 1 user message)
			// and whether the recommendation pass produced 0 matches.
			$user_msgs   = 0;
			$no_match    = false;
			foreach ( $history as $h ) {
				if ( 'user' === $h['role'] ) {
					$user_msgs++;
				}
				$meta = is_string( $h['metadata'] ) ? json_decode( (string) $h['metadata'], true ) : [];
				if ( is_array( $meta ) && ! empty( $meta['no_match'] ) ) {
					$no_match = true;
				}
			}
			$data['conversation_user_messages'] = $user_msgs;
			$data['no_match_brand_filter']      = $no_match;

			$summary = Chat::summarise( (string) $data['session_id'], substr( get_locale(), 0, 2 ) );
			if ( ! empty( $summary['summary'] ) ) {
				$data['ai_summary'] = $summary['summary'];
			}
			if ( ! empty( $summary['fields'] ) ) {
				// Backfill any empty extracted fields with the summariser pass.
				foreach ( $summary['fields'] as $k => $v ) {
					if ( empty( $data['extracted_fields'][ $k ] ) ) {
						$data['extracted_fields'][ $k ] = $v;
					}
				}
			}
		}

		// Backfill the lead form values from extracted fields when the user
		// skipped without typing them (chat-only path).
		if ( ! empty( $data['extracted_fields'] ) ) {
			foreach ( [ 'first_name', 'last_name', 'company', 'email', 'phone', 'country' ] as $field ) {
				if ( empty( $data[ $field ] ) && ! empty( $data['extracted_fields'][ $field ] ) ) {
					$data[ $field ] = $data['extracted_fields'][ $field ];
				}
			}
			// Map to the existing application/materials/volume fields too.
			if ( empty( $data['applications'] ) && ! empty( $data['extracted_fields']['applications'] ) ) {
				$data['applications'] = $data['extracted_fields']['applications'];
				$data['application']  = implode( ', ', $data['applications'] );
			}
			if ( empty( $data['materials'] ) && ! empty( $data['extracted_fields']['materials'] ) ) {
				$data['materials'] = $data['extracted_fields']['materials'];
			}
			if ( empty( $data['volume'] ) && ! empty( $data['extracted_fields']['volume'] ) ) {
				$data['volume'] = $data['extracted_fields']['volume'];
			}
		}

		$lead_id = Lead_CPT::create_lead( $data, 'pending_retry' );

		// Persist session id and conversation snapshot on the lead.
		if ( $lead_id && ! empty( $data['session_id'] ) ) {
			update_post_meta( $lead_id, '_bqw_session_id', (string) $data['session_id'] );
			update_post_meta( $lead_id, '_bqw_conversation_full', wp_json_encode( $data['conversation_history'] ?? [] ) );
			if ( ! empty( $data['ai_summary'] ) ) {
				update_post_meta( $lead_id, '_bqw_ai_summary', wp_json_encode( $data['ai_summary'] ) );
			}
		}

		[ $ok, $contact_id, $contact_url, $error ] = self::push_to_agile( $data, $lead_id );

		Mailer::send_lead_notification( $data, $contact_url, $ok ? null : $error );

		// Partial lead is now complete — drop it from the partial table.
		if ( ! empty( $data['session_id'] ) ) {
			Partial_Leads::delete( (string) $data['session_id'] );
		}

		// Render thanks template snippet.
		$thanks_html = $this->render_thanks_html( $data );

		// Optional post-submit redirect (for conversion tracking).
		$redirect = (string) Settings::get( 'redirect_url', '' );
		if ( $redirect && $lead_id ) {
			$redirect = add_query_arg( 'lead_id', $lead_id, $redirect );
		}

		wp_send_json_success(
			[
				'message'  => __( 'Thanks! We received your request.', 'bomedia-quote-wizard' ),
				'html'     => $thanks_html,
				'redirect' => $redirect,
				'lead_id'  => $lead_id,
			]
		);
	}

	/**
	 * Push lead data to AgileCRM. Returns [success_bool, contact_id, contact_url, error_message].
	 */
	public static function push_to_agile( array $data, int $lead_id ): array {
		$client = new AgileCRM_Client();
		if ( ! $client->is_configured() ) {
			$msg = __( 'AgileCRM is not configured.', 'bomedia-quote-wizard' );
			Logger::error( $msg );
			Lead_CPT::update_status( $lead_id, 'failed', null, $msg );
			return [ false, null, null, $msg ];
		}

		$payload  = self::build_contact_payload( $data );
		$response = $client->create_contact( $payload );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			Lead_CPT::update_status( $lead_id, 'failed', null, $msg );
			return [ false, null, null, $msg ];
		}

		$contact_id = $response['id'] ?? null;
		if ( $contact_id ) {
			$note = self::build_note_text( $data );
			$client->add_note( $contact_id, __( 'Solicitud presupuesto web', 'bomedia-quote-wizard' ), $note );
		}

		Lead_CPT::update_status( $lead_id, 'sent', $contact_id, '' );
		Logger::info( 'Lead sent to AgileCRM', [ 'contact_id' => $contact_id, 'lead_id' => $lead_id ] );

		return [ true, $contact_id, $contact_id ? $client->contact_url( $contact_id ) : null, null ];
	}

	private function collect_and_validate() {
		$first    = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last     = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$company  = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$country  = sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) );
		$message      = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$privacy      = ! empty( $_POST['privacy'] );
		$email_optin  = ! empty( $_POST['email_optin'] );
		$flow_origin  = sanitize_text_field( (string) ( $_POST['flow'] ?? 'chat' ) );
		$session_id   = sanitize_text_field( (string) ( $_POST['session_id'] ?? '' ) );

		$extracted = [];
		$ext_raw   = (string) wp_unslash( $_POST['extracted_fields_json'] ?? '' );
		if ( '' !== $ext_raw ) {
			$decoded = json_decode( $ext_raw, true );
			if ( is_array( $decoded ) ) {
				$extracted = Chat::sanitize_extracted_fields( $decoded );
			}
		}

		$unsure  = ! empty( $_POST['unsure'] );
		$json    = wp_unslash( $_POST['selected_products_json'] ?? '[]' );
		$decoded = is_string( $json ) ? json_decode( $json, true ) : [];
		$selected_products = self::sanitize_selected_products( is_array( $decoded ) ? $decoded : [] );

		$applications = array_values(
			array_filter(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['application'] ?? [] ) ),
				'strlen'
			)
		);
		$materials = array_values(
			array_filter(
				array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['materials'] ?? [] ) ),
				'strlen'
			)
		);
		$volume = sanitize_text_field( wp_unslash( $_POST['volume'] ?? '' ) );

		// v1.7.6 — only first name and email are required. Last name and
		// company are optional (company input was removed from the form).
		if ( ! $first ) {
			return new \WP_Error( 'bqw_missing', __( 'First name is required.', 'bomedia-quote-wizard' ) );
		}
		if ( ! $email || ! is_email( $email ) ) {
			return new \WP_Error( 'bqw_email', __( 'A valid email is required.', 'bomedia-quote-wizard' ) );
		}
		if ( ! $phone ) {
			return new \WP_Error( 'bqw_phone', __( 'Phone is required.', 'bomedia-quote-wizard' ) );
		}
		if ( ! $privacy ) {
			return new \WP_Error( 'bqw_privacy', __( 'You must accept the privacy policy.', 'bomedia-quote-wizard' ) );
		}
		// In chat flow the user may submit without explicit picks; the AI
		// summary + extracted_fields tell sales what they want. Only the
		// classic step-based flow requires an explicit machine pick.
		$is_chat_or_skip = in_array( $flow_origin, [ 'chat', 'skip' ], true );
		if ( ! $is_chat_or_skip && ! $unsure && empty( $selected_products ) ) {
			return new \WP_Error( 'bqw_no_products', __( 'Please pick at least one machine.', 'bomedia-quote-wizard' ) );
		}

		// Aggregate fields used for AgileCRM and notifications.
		$product_names = array_map( static function ( $p ) { return $p['name']; }, $selected_products );
		$product_ids   = array_map( static function ( $p ) { return (int) $p['id']; }, $selected_products );

		// Union of product_cat slugs across Woo-sourced selections (for tags).
		// Catalog (Supabase) selections expose their brand instead.
		$category_slugs = [];
		$category_names = [];
		foreach ( $selected_products as $p ) {
			$is_woo = ( ( $p['source'] ?? 'woo' ) === 'woo' ) && ctype_digit( (string) $p['id'] );
			if ( ! $is_woo ) {
				continue;
			}
			$terms = (array) wp_get_post_terms( (int) $p['id'], 'product_cat' );
			foreach ( $terms as $t ) {
				if ( is_object( $t ) ) {
					$category_slugs[ $t->slug ] = true;
					$category_names[ $t->name ] = true;
				}
			}
		}
		$category_slugs = array_keys( $category_slugs );
		$category_names = array_keys( $category_names );

		return [
			'first_name'        => $first,
			'last_name'         => $last,
			'company'           => $company,
			'email'             => $email,
			'phone'             => $phone,
			'country'           => $country,
			'message'           => $message,
			'unsure'            => $unsure,
			'selected_products' => $selected_products,
			'product_ids'       => $product_ids,
			'product_name'      => $unsure
				? __( "I'm not sure", 'bomedia-quote-wizard' )
				: implode( ', ', $product_names ),
			'category_slugs'    => $category_slugs,
			'category_names'    => $category_names,
			'category_name'     => implode( ', ', $category_names ),
			'application'       => implode( ', ', $applications ),
			'applications'      => $applications,
			'materials'         => $materials,
			'volume'            => $volume,
			'email_optin'       => $email_optin,
			'flow_origin'       => $flow_origin,
			'session_id'        => $session_id,
			'extracted_fields'  => $extracted,
			'source_url'        => esc_url_raw( wp_unslash( $_POST['source_url'] ?? home_url( add_query_arg( null, null ) ) ) ),
			'source_site'       => wp_parse_url( home_url(), PHP_URL_HOST ),
			'ip'                => self::client_ip(),
			'user_agent'        => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
			'submitted_at'      => current_time( 'mysql' ),
		];
	}

	private static function sanitize_selected_products( array $raw ): array {
		// Build a Supabase catalog index for slug lookups (used by matchmaker).
		$catalog_index = [];
		foreach ( Catalog_Client::get_products() as $p ) {
			$slug = (string) ( $p['id'] ?? '' );
			if ( '' !== $slug ) {
				$catalog_index[ $slug ] = $p;
			}
		}
		$lang_code = substr( get_locale(), 0, 2 );

		$out = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$source = isset( $entry['source'] ) && 'catalog' === $entry['source'] ? 'catalog' : 'woo';

			if ( 'catalog' === $source ) {
				$slug = sanitize_text_field( (string) ( $entry['id'] ?? '' ) );
				if ( '' === $slug || ! isset( $catalog_index[ $slug ] ) ) {
					continue;
				}
				$loc = Catalog_Client::localize_product( $catalog_index[ $slug ], $lang_code );
				$out[] = [
					'id'           => $slug,
					'name'         => $loc['name'],
					'sku'          => '',
					'brand'        => $loc['brand'],
					'price'        => $loc['price'],
					'area'         => $loc['area'],
					'link'         => $loc['link'],
					'image'        => $loc['img'],
					'source'       => 'catalog',
					'categoryId'   => 0,
					'categorySlug' => sanitize_title( $loc['brand'] ),
					'categoryName' => $loc['brand'],
				];
				continue;
			}

			// WooCommerce-sourced selection (classic flow).
			$id = absint( $entry['id'] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'product' !== $post->post_type ) {
				continue;
			}
			$out[] = [
				'id'           => $id,
				'name'         => $post->post_title,
				'sku'          => self::get_product_sku( $id ),
				'source'       => 'woo',
				'categoryId'   => absint( $entry['categoryId'] ?? 0 ),
				'categorySlug' => sanitize_title( (string) ( $entry['categorySlug'] ?? '' ) ),
				'categoryName' => sanitize_text_field( (string) ( $entry['categoryName'] ?? '' ) ),
			];
		}
		return $out;
	}

	private static function build_contact_payload( array $data ): array {
		$tags_csv = (string) Settings::get( 'agile_default_tags', 'web-lead' );
		$tags     = array_filter( array_map( 'trim', explode( ',', $tags_csv ) ) );
		foreach ( (array) ( $data['category_slugs'] ?? [] ) as $slug ) {
			if ( $slug ) {
				$tags[] = $slug;
			}
		}
		// Brand tags coming from Supabase-sourced selections.
		$brands_seen = [];
		foreach ( (array) ( $data['selected_products'] ?? [] ) as $sp ) {
			if ( ! empty( $sp['brand'] ) ) {
				$brand_slug = sanitize_title( (string) $sp['brand'] );
				if ( '' !== $brand_slug ) {
					$tags[] = $brand_slug;
					$brands_seen[ $brand_slug ] = true;
				}
			}
		}
		if ( count( $brands_seen ) > 1 ) {
			$tags[] = 'multi-brand-lead';
		}
		if ( ! empty( $data['email_optin'] ) ) {
			$tags[] = 'marketing-optin';
		}
		// Tag origin so sales sees the path the user took.
		$origin = (string) ( $data['flow_origin'] ?? '' );
		if ( 'skip' === $origin ) {
			$tags[] = 'direct-send';
			if ( ! empty( $data['conversation_user_messages'] ) ) {
				$tags[] = 'partial-conversation';
			}
		} elseif ( 'direct-catalog' === $origin || 'knows-machine' === $origin ) {
			// v1.7.6 renamed from knows-machine. Both legacy and new origins
			// land on the same tag.
			$tags[] = 'direct-catalog';
		} elseif ( 'not-convinced' === $origin ) {
			$tags[] = 'not-convinced';
		} elseif ( 'callme' === $origin ) {
			// v1.7.14 — visitor pressed "Prefiero que me llamen".
			$tags[] = 'prefiere-llamada';
		}
		// No matching machine in the brand filter — sales should reach out manually.
		if ( ! empty( $data['no_match_brand_filter'] ) ) {
			$tags[] = 'no-match-found';
		}
		$tags = array_values( array_unique( $tags ) );

		$properties = [
			[ 'type' => 'SYSTEM', 'name' => 'first_name', 'value' => $data['first_name'] ],
			[ 'type' => 'SYSTEM', 'name' => 'last_name',  'value' => $data['last_name'] ],
			[ 'type' => 'SYSTEM', 'name' => 'email',      'value' => $data['email'] ],
			[ 'type' => 'SYSTEM', 'name' => 'company',    'value' => $data['company'] ],
			[ 'type' => 'SYSTEM', 'name' => 'phone',      'subtype' => 'work', 'value' => $data['phone'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Country',         'value' => $data['country'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Application',     'value' => $data['application'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Materials',       'value' => implode( ', ', (array) $data['materials'] ) ],
			[ 'type' => 'CUSTOM', 'name' => 'Monthly_Volume',  'value' => $data['volume'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Model_Interest',  'value' => self::format_model_interest( $data ) ],
			[ 'type' => 'CUSTOM', 'name' => 'Source_URL',      'value' => $data['source_url'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Source_Site',     'value' => $data['source_site'] ],
			[ 'type' => 'CUSTOM', 'name' => 'Marketing_Optin', 'value' => ! empty( $data['email_optin'] ) ? 'yes' : 'no' ],
		];

		return [
			'type'       => 'PERSON',
			'tags'       => $tags,
			'properties' => $properties,
		];
	}

	private static function format_model_interest( array $data ): string {
		if ( empty( $data['selected_products'] ) ) {
			return $data['product_name'] ?? '';
		}
		$parts = [];
		foreach ( $data['selected_products'] as $p ) {
			$label = (string) $p['name'];
			if ( ! empty( $p['price'] ) ) {
				$label .= ' (' . $p['price'] . ')';
			}
			$parts[] = $label;
		}
		return implode( ', ', $parts );
	}

	private static function build_note_text( array $data ): string {
		$lines   = [];
		$lines[] = 'Lead recibido desde ' . ( $data['source_site'] ?? '' );
		$lines[] = 'URL: ' . ( $data['source_url'] ?? '' );
		// v1.7.13 — origin trail so sales can spot which site/branding the
		// lead came from without parsing the URL.
		$bot_name = trim( (string) Settings::get( 'bot_name', '' ) );
		$site_dn  = trim( (string) Settings::get( 'site_display_name', '' ) );
		if ( '' !== $bot_name || '' !== $site_dn ) {
			$lines[] = sprintf(
				'Generado desde el asistente de %s en %s',
				$bot_name !== '' ? $bot_name : 'Bomedia',
				$site_dn  !== '' ? $site_dn  : ( $data['source_site'] ?? '' )
			);
		}
		$lines[] = '';
		$lines[] = 'Máquinas de interés:';
		if ( ! empty( $data['unsure'] ) ) {
			$lines[] = '  - (Cliente no está seguro, pide ayuda para elegir)';
		} elseif ( ! empty( $data['selected_products'] ) ) {
			foreach ( $data['selected_products'] as $p ) {
				$bits = [ '  - ' . $p['name'] ];
				if ( ! empty( $p['brand'] ) )  $bits[] = 'brand: ' . $p['brand'];
				if ( ! empty( $p['area'] ) )   $bits[] = 'area: ' . $p['area'];
				if ( ! empty( $p['price'] ) )  $bits[] = 'price: ' . $p['price'];
				if ( ! empty( $p['sku'] ) )    $bits[] = 'SKU: ' . $p['sku'];
				if ( ! empty( $p['categoryName'] ) && empty( $p['brand'] ) ) $bits[] = 'cat: ' . $p['categoryName'];
				if ( ! empty( $p['link'] ) )   $bits[] = $p['link'];
				$lines[] = implode( ' | ', $bits );
			}
		}
		$lines[] = '';
		$lines[] = 'Aplicaciones: ' . ( $data['application'] ?? '' );
		$lines[] = 'Materiales: ' . implode( ', ', (array) ( $data['materials'] ?? [] ) );
		$lines[] = 'Volumen mensual: ' . ( $data['volume'] ?? '' );
		$lines[] = 'País: ' . ( $data['country'] ?? '' );
		$lines[] = '';
		$lines[] = 'Mensaje:';
		$lines[] = $data['message'] ?? '';

		if ( ! empty( $data['ai_summary'] ) && is_array( $data['ai_summary'] ) ) {
			$lines[] = '';
			$lines[] = 'Resumen IA de la conversación:';
			foreach ( $data['ai_summary'] as $bullet ) {
				$lines[] = '  • ' . $bullet;
			}
		}
		return implode( "\n", $lines );
	}

	private function render_thanks_html( array $data ): string {
		$tpl = locate_template( 'bomedia-quote-wizard/thanks.php' );
		if ( ! $tpl ) {
			$tpl = BQW_PLUGIN_DIR . 'templates/thanks.php';
		}
		$bqw_data = $data;
		ob_start();
		include $tpl;
		return (string) ob_get_clean();
	}

	private static function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0];
				return trim( $ip );
			}
		}
		return '';
	}
}
