<?php
/**
 * [bomedia_quote_wizard] shortcode.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	private static ?Shortcode $instance = null;

	private bool $assets_needed = false;

	public static function instance(): Shortcode {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_shortcode( 'bomedia_quote_wizard', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_assets(): void {
		wp_register_style( 'bqw-wizard', BQW_PLUGIN_URL . 'assets/css/wizard.css', [], BQW_VERSION );
		wp_register_script( 'bqw-wizard', BQW_PLUGIN_URL . 'assets/js/wizard.js', [], BQW_VERSION, true );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'category'   => '',
				'product_id' => 0,
			],
			$atts,
			'bomedia_quote_wizard'
		);

		$switched_locale = $this->maybe_switch_locale();

		$forced_category_id = 0;
		if ( ! empty( $atts['category'] ) ) {
			$term = get_term_by( 'slug', sanitize_title( $atts['category'] ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$forced_category_id = (int) $term->term_id;
			}
		}

		$forced_product_id = absint( $atts['product_id'] );

		// Order from settings is preserved (drag&drop in admin → DOM order → submit order).
		$selected_cat_ids = array_map( 'intval', (array) Settings::get( 'selected_categories', [] ) );
		if ( $forced_category_id && ! in_array( $forced_category_id, $selected_cat_ids, true ) ) {
			array_unshift( $selected_cat_ids, $forced_category_id );
		}

		$categories = [];
		foreach ( $selected_cat_ids as $cid ) {
			$term = get_term( $cid, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$thumb_id  = (int) get_term_meta( $cid, 'thumbnail_id', true );
				$image_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
				$categories[] = [
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'image' => $image_url,
				];
			}
		}

		$forced_product = null;
		if ( $forced_product_id ) {
			$p = get_post( $forced_product_id );
			if ( $p && 'product' === $p->post_type ) {
				$forced_product = [
					'id'    => $p->ID,
					'name'  => $p->post_title,
					'image' => get_the_post_thumbnail_url( $p->ID, 'medium' ),
				];
			}
		}

		$settings = Settings::get_wizard();
		$notif    = Settings::get_notifications();

		// Captcha context for the active provider.
		$captcha_ctx = null;
		if ( ! empty( $settings['enable_captcha'] ) ) {
			$provider    = Captcha::provider();
			$captcha_ctx = $provider->build_context();
			$script_url  = $provider->script_url();
			if ( $script_url ) {
				wp_enqueue_script( 'bqw-captcha-provider', $script_url, [], null, true );
			}
		}

		$option_images = (array) ( $settings['option_images'] ?? [] );

		$wizard_cfg = [
			'forced_category_id'  => $forced_category_id,
			'forced_product'      => $forced_product,
			'categories'          => $categories,
			'enable_application'  => ! empty( $settings['enable_application'] ),
			'application_options' => $this->options_with_images( $this->lines_to_array( $settings['application_options'] ?? '' ), 'application', $option_images ),
			'enable_materials'    => ! empty( $settings['enable_materials'] ),
			'materials_options'   => $this->options_with_images( $this->lines_to_array( $settings['materials_options'] ?? '' ), 'materials', $option_images ),
			'enable_volume'       => ! empty( $settings['enable_volume'] ),
			'volume_options'      => $this->options_with_images( $this->lines_to_array( $settings['volume_options'] ?? '' ), 'volume', $option_images ),
			'privacy_url'         => $settings['privacy_url'] ?? '',
			'fallback_email'      => $this->fallback_contact_email(),
			'enable_captcha'      => ! empty( $settings['enable_captcha'] ),
			'captcha'             => $captcha_ctx,
			'enable_email_optin'  => ! empty( $settings['enable_email_optin'] ),
			'email_optin_label'   => $settings['email_optin_label'] ?? '',

			// Hero.
			'enable_hero'         => ! empty( $settings['enable_hero'] ),
			'hero_title'          => $settings['hero_title'] ?? '',
			'hero_subtitle'       => $settings['hero_subtitle'] ?? '',
			'hero_image_url'      => ! empty( $settings['hero_image_id'] ) ? wp_get_attachment_image_url( (int) $settings['hero_image_id'], 'large' ) : '',
			'hero_trust'          => $this->parse_trust_lines( $settings['hero_trust'] ?? '' ),

			// Microcopy.
			'enable_microcopy'    => ! empty( $settings['enable_microcopy'] ),
			'microcopy_messages'  => $this->lines_to_array( $settings['microcopy_messages'] ?? '' ),

			// Matchmaker.
			'enable_matchmaker'   => ! empty( $settings['enable_matchmaker'] ),
			'matchmaker_format_options' => $this->options_with_images( $this->lines_to_array( $settings['matchmaker_format_options'] ?? '' ), 'format', $option_images ),
			'matchmaker_budget_options' => $this->options_with_images( $this->lines_to_array( $settings['matchmaker_budget_options'] ?? '' ), 'budget', $option_images ),

			// Redirect.
			'redirect_url'        => $notif['redirect_url'] ?? '',
		];

		$this->assets_needed = true;
		wp_enqueue_style( 'bqw-wizard' );
		wp_enqueue_script( 'bqw-wizard' );
		wp_localize_script(
			'bqw-wizard',
			'BQW',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bqw_submit' ),
				'config'  => $wizard_cfg,
				'i18n'    => $this->i18n_strings(),
				'countries' => $this->countries_list(),
				'detectedCountry' => $this->detect_country_code(),
			]
		);

		$tpl = locate_template( 'bomedia-quote-wizard/wizard.php' );
		if ( ! $tpl ) {
			$tpl = BQW_PLUGIN_DIR . 'templates/wizard.php';
		}

		ob_start();
		$bqw_config = $wizard_cfg;
		include $tpl;
		$out = (string) ob_get_clean();

		if ( $switched_locale ) {
			restore_previous_locale();
		}

		return $out;
	}

	private function maybe_switch_locale(): bool {
		$forced = (string) Settings::get( 'wizard_language', '' );
		if ( '' === $forced ) {
			return false;
		}
		if ( ! function_exists( 'switch_to_locale' ) ) {
			return false;
		}
		if ( $forced === get_locale() ) {
			return false;
		}
		switch_to_locale( $forced );
		// Reload our textdomain for the new locale.
		unload_textdomain( 'bomedia-quote-wizard' );
		load_plugin_textdomain( 'bomedia-quote-wizard', false, dirname( plugin_basename( BQW_PLUGIN_FILE ) ) . '/languages' );
		return true;
	}

	private function options_with_images( array $labels, string $context, array $option_images ): array {
		$out = [];
		foreach ( $labels as $label ) {
			$key = $context . '|' . $label;
			$aid = isset( $option_images[ $key ] ) ? (int) $option_images[ $key ] : 0;
			$out[] = [
				'label' => $label,
				'image' => $aid ? (string) wp_get_attachment_image_url( $aid, 'medium' ) : '',
			];
		}
		return $out;
	}

	private function parse_trust_lines( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
		$out   = [];
		foreach ( $lines as $ln ) {
			$ln = trim( $ln );
			if ( '' === $ln ) {
				continue;
			}
			if ( false !== strpos( $ln, '|' ) ) {
				[ $icon, $text ] = array_map( 'trim', explode( '|', $ln, 2 ) );
			} else {
				$icon = 'yes';
				$text = $ln;
			}
			$out[] = [ 'icon' => sanitize_html_class( $icon ), 'text' => $text ];
		}
		return $out;
	}

	private function fallback_contact_email(): string {
		$emails_raw = (string) Settings::get( 'notify_emails', '' );
		$first      = trim( explode( ',', $emails_raw )[0] ?? '' );
		return is_email( $first ) ? $first : (string) get_option( 'admin_email' );
	}

	private function lines_to_array( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		return array_values( array_filter( array_map( 'trim', $lines ?: [] ), 'strlen' ) );
	}

	private function detect_country_code(): string {
		$locale = get_locale();
		if ( strlen( $locale ) >= 5 ) {
			return strtoupper( substr( $locale, 3, 2 ) );
		}
		return 'ES';
	}

	private function i18n_strings(): array {
		return [
			'step1Title'    => __( 'Product of interest', 'bomedia-quote-wizard' ),
			'step2Title'    => __( 'Your application', 'bomedia-quote-wizard' ),
			'step3Title'    => __( 'Your details', 'bomedia-quote-wizard' ),
			'step4Title'    => __( 'Confirmation', 'bomedia-quote-wizard' ),
			'next'          => __( 'Next', 'bomedia-quote-wizard' ),
			'back'          => __( 'Back', 'bomedia-quote-wizard' ),
			'submit'        => __( 'Send request', 'bomedia-quote-wizard' ),
			'sending'       => __( 'Sending…', 'bomedia-quote-wizard' ),
			'firstName'     => __( 'First name', 'bomedia-quote-wizard' ),
			'lastName'      => __( 'Last name', 'bomedia-quote-wizard' ),
			'company'       => __( 'Company', 'bomedia-quote-wizard' ),
			'email'         => __( 'Email', 'bomedia-quote-wizard' ),
			'phone'         => __( 'Phone', 'bomedia-quote-wizard' ),
			'country'       => __( 'Country', 'bomedia-quote-wizard' ),
			'message'       => __( 'Message (optional)', 'bomedia-quote-wizard' ),
			'unsure'        => __( "I'm not sure, help me choose", 'bomedia-quote-wizard' ),
			'application'   => __( 'Application', 'bomedia-quote-wizard' ),
			'materials'     => __( 'Materials', 'bomedia-quote-wizard' ),
			'volume'        => __( 'Monthly volume', 'bomedia-quote-wizard' ),
			'pickCategory'  => __( 'Choose a category', 'bomedia-quote-wizard' ),
			'pickProduct'   => __( 'Choose a model', 'bomedia-quote-wizard' ),
			'requiredField' => __( 'This field is required.', 'bomedia-quote-wizard' ),
			'invalidEmail'  => __( 'Please enter a valid email.', 'bomedia-quote-wizard' ),
			'acceptPrivacy' => __( 'I have read and accept the privacy policy', 'bomedia-quote-wizard' ),
			'summary'       => __( 'Summary', 'bomedia-quote-wizard' ),
			'genericError'  => __( 'Something went wrong. Please try again.', 'bomedia-quote-wizard' ),
			'modelOf'       => __( 'Model of', 'bomedia-quote-wizard' ),
			'backToCats'    => __( '← Back to categories', 'bomedia-quote-wizard' ),
			'loading'       => __( 'Loading models…', 'bomedia-quote-wizard' ),
			'noProducts'    => __( 'No machines available in this category. Please contact us directly.', 'bomedia-quote-wizard' ),
			'contactUs'     => __( 'Contact us', 'bomedia-quote-wizard' ),
			/* translators: %d: number of selected machines */
			'selectedMany'  => __( 'You picked %d machines', 'bomedia-quote-wizard' ),
			'selectedOne'   => __( 'You picked 1 machine', 'bomedia-quote-wizard' ),
			'machinesOfInterest' => __( 'Machines of interest', 'bomedia-quote-wizard' ),
			'noSelection'   => __( 'No machines selected.', 'bomedia-quote-wizard' ),
			'remove'        => __( 'Remove', 'bomedia-quote-wizard' ),
			'matchScore'    => __( 'Matches at', 'bomedia-quote-wizard' ),
			'noMatches'     => __( "Your case is specific. Let's talk directly.", 'bomedia-quote-wizard' ),
			'format'        => __( 'Format', 'bomedia-quote-wizard' ),
			'budget'        => __( 'Budget', 'bomedia-quote-wizard' ),
			'aiFailed'      => __( "We've received your answers. We'll get back to you with a personalized recommendation.", 'bomedia-quote-wizard' ),
		];
	}

	private function countries_list(): array {
		// Subset; expand as needed. ISO 3166-1 alpha-2 → label + dial code.
		return [
			[ 'code' => 'ES', 'name' => 'España',          'dial' => '+34' ],
			[ 'code' => 'PT', 'name' => 'Portugal',        'dial' => '+351' ],
			[ 'code' => 'FR', 'name' => 'France',          'dial' => '+33' ],
			[ 'code' => 'IT', 'name' => 'Italia',          'dial' => '+39' ],
			[ 'code' => 'DE', 'name' => 'Deutschland',     'dial' => '+49' ],
			[ 'code' => 'GB', 'name' => 'United Kingdom',  'dial' => '+44' ],
			[ 'code' => 'IE', 'name' => 'Ireland',         'dial' => '+353' ],
			[ 'code' => 'NL', 'name' => 'Nederland',       'dial' => '+31' ],
			[ 'code' => 'BE', 'name' => 'België',          'dial' => '+32' ],
			[ 'code' => 'CH', 'name' => 'Schweiz',         'dial' => '+41' ],
			[ 'code' => 'AT', 'name' => 'Österreich',      'dial' => '+43' ],
			[ 'code' => 'PL', 'name' => 'Polska',          'dial' => '+48' ],
			[ 'code' => 'SE', 'name' => 'Sverige',         'dial' => '+46' ],
			[ 'code' => 'NO', 'name' => 'Norge',           'dial' => '+47' ],
			[ 'code' => 'DK', 'name' => 'Danmark',         'dial' => '+45' ],
			[ 'code' => 'FI', 'name' => 'Suomi',           'dial' => '+358' ],
			[ 'code' => 'GR', 'name' => 'Greece',          'dial' => '+30' ],
			[ 'code' => 'TR', 'name' => 'Türkiye',         'dial' => '+90' ],
			[ 'code' => 'US', 'name' => 'United States',   'dial' => '+1' ],
			[ 'code' => 'CA', 'name' => 'Canada',          'dial' => '+1' ],
			[ 'code' => 'MX', 'name' => 'México',          'dial' => '+52' ],
			[ 'code' => 'BR', 'name' => 'Brasil',          'dial' => '+55' ],
			[ 'code' => 'AR', 'name' => 'Argentina',       'dial' => '+54' ],
			[ 'code' => 'CL', 'name' => 'Chile',           'dial' => '+56' ],
			[ 'code' => 'CO', 'name' => 'Colombia',        'dial' => '+57' ],
			[ 'code' => 'PE', 'name' => 'Perú',            'dial' => '+51' ],
			[ 'code' => 'MA', 'name' => 'Maroc',           'dial' => '+212' ],
			[ 'code' => 'AE', 'name' => 'UAE',             'dial' => '+971' ],
			[ 'code' => 'SA', 'name' => 'Saudi Arabia',    'dial' => '+966' ],
			[ 'code' => 'IL', 'name' => 'Israel',          'dial' => '+972' ],
			[ 'code' => 'IN', 'name' => 'India',           'dial' => '+91' ],
			[ 'code' => 'CN', 'name' => 'China',           'dial' => '+86' ],
			[ 'code' => 'JP', 'name' => 'Japan',           'dial' => '+81' ],
			[ 'code' => 'KR', 'name' => 'Korea',           'dial' => '+82' ],
			[ 'code' => 'AU', 'name' => 'Australia',       'dial' => '+61' ],
			[ 'code' => 'NZ', 'name' => 'New Zealand',     'dial' => '+64' ],
			[ 'code' => 'ZA', 'name' => 'South Africa',    'dial' => '+27' ],
		];
	}
}
