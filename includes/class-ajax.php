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

		// Math captcha.
		if ( Captcha::is_enabled() ) {
			$ans   = $_POST['bqw_captcha_answer'] ?? '';
			$tok   = isset( $_POST['bqw_captcha_token'] ) ? (string) wp_unslash( $_POST['bqw_captcha_token'] ) : '';
			$ts    = isset( $_POST['bqw_captcha_ts'] ) ? absint( $_POST['bqw_captcha_ts'] ) : 0;
			if ( ! Captcha::verify( $ans, $tok, $ts ) ) {
				wp_send_json_error( [ 'message' => __( 'The verification answer is incorrect. Please try again.', 'bomedia-quote-wizard' ) ], 400 );
			}
		}

		$data = $this->collect_and_validate();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [ 'message' => $data->get_error_message() ], 400 );
		}

		$lead_id = Lead_CPT::create_lead( $data, 'pending_retry' );

		[ $ok, $contact_id, $contact_url, $error ] = self::push_to_agile( $data, $lead_id );

		Mailer::send_lead_notification( $data, $contact_url, $ok ? null : $error );

		// Render thanks template snippet.
		$thanks_html = $this->render_thanks_html( $data );

		wp_send_json_success(
			[
				'message' => __( 'Thanks! We received your request.', 'bomedia-quote-wizard' ),
				'html'    => $thanks_html,
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

		if ( ! $first || ! $last ) {
			return new \WP_Error( 'bqw_missing', __( 'Name is required.', 'bomedia-quote-wizard' ) );
		}
		if ( ! $company ) {
			return new \WP_Error( 'bqw_missing', __( 'Company is required.', 'bomedia-quote-wizard' ) );
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
		// At least one machine OR explicit "unsure".
		if ( ! $unsure && empty( $selected_products ) ) {
			return new \WP_Error( 'bqw_no_products', __( 'Please pick at least one machine.', 'bomedia-quote-wizard' ) );
		}
		// At least one application if step is enabled.
		$enable_application = (int) Settings::get( 'enable_application', 0 );
		if ( $enable_application && empty( $applications ) ) {
			return new \WP_Error( 'bqw_no_application', __( 'Please pick at least one application.', 'bomedia-quote-wizard' ) );
		}

		// Aggregate fields used for AgileCRM and notifications.
		$product_names = array_map( static function ( $p ) { return $p['name']; }, $selected_products );
		$product_ids   = array_map( static function ( $p ) { return (int) $p['id']; }, $selected_products );

		// Union of product_cat slugs across all selected products (for tags).
		$category_slugs = [];
		$category_names = [];
		foreach ( $selected_products as $p ) {
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
			'source_url'        => esc_url_raw( wp_unslash( $_POST['source_url'] ?? home_url( add_query_arg( null, null ) ) ) ),
			'source_site'       => wp_parse_url( home_url(), PHP_URL_HOST ),
			'ip'                => self::client_ip(),
			'user_agent'        => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
			'submitted_at'      => current_time( 'mysql' ),
		];
	}

	private static function sanitize_selected_products( array $raw ): array {
		$out = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
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
		if ( ! empty( $data['email_optin'] ) ) {
			$tags[] = 'marketing-optin';
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
			[ 'type' => 'CUSTOM', 'name' => 'Model_Interest',  'value' => $data['product_name'] ],
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

	private static function build_note_text( array $data ): string {
		$lines   = [];
		$lines[] = 'Lead recibido desde ' . ( $data['source_site'] ?? '' );
		$lines[] = 'URL: ' . ( $data['source_url'] ?? '' );
		$lines[] = '';
		$lines[] = 'Máquinas de interés:';
		if ( ! empty( $data['unsure'] ) ) {
			$lines[] = '  - (Cliente no está seguro, pide ayuda para elegir)';
		} elseif ( ! empty( $data['selected_products'] ) ) {
			foreach ( $data['selected_products'] as $p ) {
				$line = '  - ' . $p['name'];
				if ( ! empty( $p['sku'] ) ) {
					$line .= ' [SKU: ' . $p['sku'] . ']';
				}
				if ( ! empty( $p['categoryName'] ) ) {
					$line .= ' (' . $p['categoryName'] . ')';
				}
				$lines[] = $line;
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
