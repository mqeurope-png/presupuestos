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
		wp_register_style( 'bqw-chat',   BQW_PLUGIN_URL . 'assets/css/chat.css',   [ 'bqw-wizard' ], BQW_VERSION );
		wp_register_script( 'bqw-wizard', BQW_PLUGIN_URL . 'assets/js/wizard.js', [], BQW_VERSION, true );
		wp_register_script( 'bqw-chat',   BQW_PLUGIN_URL . 'assets/js/chat.js',   [], BQW_VERSION, true );
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

		$wizard_cfg = [
			'forced_category_id'  => $forced_category_id,
			'forced_product'      => $forced_product,
			'categories'          => $categories,
			'enable_application'  => ! empty( $settings['enable_application'] ),
			'application_options' => $this->lines_to_array( $settings['application_options'] ?? '' ),
			'enable_materials'    => ! empty( $settings['enable_materials'] ),
			'materials_options'   => $this->lines_to_array( $settings['materials_options'] ?? '' ),
			'enable_volume'       => ! empty( $settings['enable_volume'] ),
			'volume_options'      => $this->lines_to_array( $settings['volume_options'] ?? '' ),
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

			// Microcopy.

			// Matchmaker.
			'enable_matchmaker'   => ! empty( $settings['enable_matchmaker'] ),
			'matchmaker_format_options' => $this->lines_to_array( $settings['matchmaker_format_options'] ?? '' ),
			'matchmaker_budget_options' => $this->lines_to_array( $settings['matchmaker_budget_options'] ?? '' ),

			// Redirect.
			'redirect_url'        => $notif['redirect_url'] ?? '',

			// v1.7.11 — exposed for JS interpolation.
			'site_display_name'   => (string) Settings::get( 'site_display_name', '' ),

			// v1.7.13 — bot branding.
			'bot_name'            => (string) Settings::get( 'bot_name', 'Asistente de Bomedia' ),
			'bot_avatar_url'      => ! empty( $bot_avatar_id = (int) Settings::get( 'bot_avatar_id', 0 ) )
				? (string) wp_get_attachment_image_url( $bot_avatar_id, 'thumbnail' )
				: '',
			'bot_avatar_color'    => (string) Settings::get( 'bot_avatar_color', '#0066cc' ),
			'bot_initial'         => (string) Settings::get( 'bot_initial', 'B' ),
		];

		$this->assets_needed = true;
		wp_enqueue_style( 'bqw-wizard' );
		wp_enqueue_style( 'bqw-chat' );
		wp_enqueue_script( 'bqw-chat' );
		wp_localize_script(
			'bqw-chat',
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

		$tpl = locate_template( 'bomedia-quote-wizard/chat.php' );
		if ( ! $tpl ) {
			$tpl = BQW_PLUGIN_DIR . 'templates/chat.php';
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
		$site = (string) Settings::get( 'site_display_name', 'this site' );
		$bot  = (string) Settings::get( 'bot_name', 'Asistente de Bomedia' );
		$cp   = static function ( string $key ) use ( $site, $bot ): string {
			return str_replace(
				[ '{site_display_name}', '{bot_name}' ],
				[ $site, $bot ],
				Settings::copy( $key )
			);
		};
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
			'viewProduct'   => __( 'View product →', 'bomedia-quote-wizard' ),
			'pickThis'      => __( 'Pick', 'bomedia-quote-wizard' ),
			/* translators: %s: external domain name */
			'availableOn'   => __( 'Available on %s', 'bomedia-quote-wizard' ),
			// Chatbot strings.
			'greeting'      => __( "Hi! I'm the Bomedia assistant. I'll help you find the right printing or laser machine.", 'bomedia-quote-wizard' ),
			'taskUVLED'     => __( 'Print on objects (UV-LED)', 'bomedia-quote-wizard' ),
			'taskTextile'   => __( 'Print on textile', 'bomedia-quote-wizard' ),
			'taskLaser'     => __( 'Cut/engrave with laser', 'bomedia-quote-wizard' ),
			'taskPack'      => __( 'Labels / packaging', 'bomedia-quote-wizard' ),
			'taskUnsure'    => __( "I'm not sure", 'bomedia-quote-wizard' ),
			'typeHere'      => __( 'Or type your answer freely…', 'bomedia-quote-wizard' ),
			'optionsOnly'   => __( 'Pick an option above…', 'bomedia-quote-wizard' ),
			'askAnything'   => __( 'Ask anything about these machines…', 'bomedia-quote-wizard' ),
			'continueToQuote' => __( 'Continue and request quote →', 'bomedia-quote-wizard' ),
			'skipQuestion'  => __( 'Skip this question', 'bomedia-quote-wizard' ),
			'send'          => __( 'Send', 'bomedia-quote-wizard' ),
			'recPlaceholder' => __( 'Your recommended machines will appear here as we chat.', 'bomedia-quote-wizard' ),
			'requestQuote'  => __( 'Request quote', 'bomedia-quote-wizard' ),
			'startOver'     => __( '↺ Start over', 'bomedia-quote-wizard' ),
			'notConvinced'  => __( "Not convinced — contact me", 'bomedia-quote-wizard' ),
			'viewOn'        => __( 'View on', 'bomedia-quote-wizard' ),
			'selectedSuffix'=> __( 'selected', 'bomedia-quote-wizard' ),
			'allTypes'      => __( 'All types', 'bomedia-quote-wizard' ),
			'typeLabel'     => __( 'Type', 'bomedia-quote-wizard' ),
			'brandLabel'    => __( 'Brand', 'bomedia-quote-wizard' ),
			'categoryLabel' => __( 'Category', 'bomedia-quote-wizard' ),
			'moreSuffix'    => __( 'more', 'bomedia-quote-wizard' ),
			'picked'        => __( 'Selected', 'bomedia-quote-wizard' ),
			// v1.7.9 — wizard screens.
			'introTitle'    => __( 'Before we start, what should we call you?', 'bomedia-quote-wizard' ),
			'introSub'      => __( "It takes 2 minutes. No spam — we only reply to your enquiry.", 'bomedia-quote-wizard' ),
			'continue'      => __( 'Continue', 'bomedia-quote-wizard' ),
			'errName'       => __( 'First name is required.', 'bomedia-quote-wizard' ),
			'errEmail'      => __( 'Please enter a valid email.', 'bomedia-quote-wizard' ),
			'errPhone'      => __( 'Phone is required.', 'bomedia-quote-wizard' ),
			'errCountry'    => __( 'Country is required.', 'bomedia-quote-wizard' ),
			'errPrivacy'    => __( 'Please accept the privacy policy.', 'bomedia-quote-wizard' ),
			'stepCounter'   => __( 'Step %1$d of %2$d', 'bomedia-quote-wizard' ),
			'recsTitle'     => __( 'Your top matches', 'bomedia-quote-wizard' ),
			/* translators: %d: number of recommendations */
			'recsSub'       => __( 'We found %d machines for you', 'bomedia-quote-wizard' ),
			'addToRequest'  => __( 'Add to request', 'bomedia-quote-wizard' ),
			'added'         => __( 'Added', 'bomedia-quote-wizard' ),
			'tray1'         => __( '1 machine in your request:', 'bomedia-quote-wizard' ),
			/* translators: %d: number of machines */
			'trayN'         => __( '%d machines in your request:', 'bomedia-quote-wizard' ),
			'trayEmpty'     => __( 'Add machines to request a quote', 'bomedia-quote-wizard' ),
			'continueWith'  => __( 'Continue with selected', 'bomedia-quote-wizard' ),
			'almostDone'    => __( 'Almost done', 'bomedia-quote-wizard' ),
			'justTwoMore'   => __( 'We just need a couple more details.', 'bomedia-quote-wizard' ),
			'yourRequest'   => __( 'Your request:', 'bomedia-quote-wizard' ),
			/* translators: %s: visitor name */
			'greetingTpl'   => __( 'Hi %s, thanks for your interest.', 'bomedia-quote-wizard' ),
			'tellUs'        => __( 'Tell us what you need…', 'bomedia-quote-wizard' ),
			'privacyPolicy' => __( 'privacy policy', 'bomedia-quote-wizard' ),
			'acceptPrivacyPrefix' => __( 'I accept the ', 'bomedia-quote-wizard' ),
			'acceptPrivacySuffix' => __( ' and the processing of my data to receive a quote.', 'bomedia-quote-wizard' ),
			'optinDefault'  => __( 'I want to receive product updates from Bomedia.', 'bomedia-quote-wizard' ),
			'searchMachines' => __( 'Search machines…', 'bomedia-quote-wizard' ),
			'countryCode'   => __( 'Country code', 'bomedia-quote-wizard' ),

			// v1.7.10 — admin-editable copy overrides (last wins). {nombre}/{n}
			// substitution happens in JS at render time.
			'introTitle'         => $cp( 'intro_helper' ),
			'introSub'           => $cp( 'intro_below' ),
			'welcomeGreetingTpl' => $cp( 'welcome_title' ),
			'recsTitle'          => $cp( 'recs_title' ),
			'recsSub'            => $cp( 'recs_sub' ),
			'addToRequest'       => $cp( 'add_btn' ),
			'added'              => $cp( 'added_state' ),
			'trayN'              => $cp( 'tray_label' ),
			'requestQuote'       => $cp( 'request_quote_btn' ),
			'finalTitleTpl'      => $cp( 'final_title' ),
			'justTwoMore'        => $cp( 'final_sub' ),
			'sectionData'        => $cp( 'section_data' ),
			'sectionRequest'     => $cp( 'section_request' ),
			'emptyRequest'       => $cp( 'empty_request' ),
			'optinDefault'       => $cp( 'optin_label' ),
			'send'               => $cp( 'send_btn' ),
			'searchMachinesCopy' => $cp( 'search_placeholder' ),
			'noResults'          => $cp( 'no_results' ),
			'removeHint'         => $cp( 'remove_hint' ),

			// v1.7.14 — screen 1 phone + opt-in helpers.
			'introPhoneLabel' => $cp( 'intro_phone_label' ),
			'introOptinLabel' => $cp( 'intro_optin_label' ),
			'errPhoneFormat'  => __( 'Phone looks too short.', 'bomedia-quote-wizard' ),

			// v1.7.14 — "Prefiero que me llamen".
			'callmeButton'      => $cp( 'callme_button' ),
			'callmeTitle'       => $cp( 'callme_title' ),
			'callmeIntro'       => $cp( 'callme_intro' ),
			'callmePhoneLabel'  => $cp( 'callme_phone_label' ),
			'callmeWhenLabel'   => $cp( 'callme_when_label' ),
			'callmeWhenPh'      => $cp( 'callme_when_ph' ),
			'callmePrivacy'     => $cp( 'callme_privacy' ),
			'callmeBackBtn'     => $cp( 'callme_back_btn' ),
			'callmeSendBtn'     => $cp( 'callme_send_btn' ),
			'callmeThanks'      => $cp( 'callme_thanks' ),

			// v1.7.12 — captcha labels.
			'captchaQuick'   => __( 'Quick check', 'bomedia-quote-wizard' ),
			'captchaHelp'    => __( 'Helps us avoid spam.', 'bomedia-quote-wizard' ),
			'captchaV3Note'  => __( 'Protected by Google reCAPTCHA.', 'bomedia-quote-wizard' ),
			'errCaptcha'     => __( 'Please complete the verification.', 'bomedia-quote-wizard' ),
			'viewMoreRecs'   => __( 'View other options', 'bomedia-quote-wizard' ),
			'anotherSpin'    => __( 'Another spin', 'bomedia-quote-wizard' ),
			'noMoreRecs'     => __( "You've seen all the available options.", 'bomedia-quote-wizard' ),
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
