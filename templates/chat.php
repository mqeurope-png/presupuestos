<?php
/**
 * Bomedia Quote Wizard — multi-screen wizard template (v1.7.9).
 * Override by copying to wp-content/themes/<your-theme>/bomedia-quote-wizard/chat.php
 *
 * @package Bomedia\QuoteWizard
 */

defined( 'ABSPATH' ) || exit;

/** @var array $bqw_config */
$started_ts       = time();
$captcha_on       = ! empty( $bqw_config['enable_captcha'] );
$captcha          = $captcha_on ? ( $bqw_config['captcha'] ?? null ) : null;
$captcha_provider = $captcha ? ( $captcha['provider'] ?? 'math' ) : 'math';
$optin_on         = ! empty( $bqw_config['enable_email_optin'] );
$optin_label      = (string) ( $bqw_config['email_optin_label'] ?? '' );
$hero_on          = ! empty( $bqw_config['enable_hero'] );
?>
<div class="bqw-wiz-wrap" id="bqw-chat-wrap"
	data-redirect="<?php echo esc_attr( (string) ( $bqw_config['redirect_url'] ?? '' ) ); ?>"
	data-language="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>"
	role="region" aria-label="<?php esc_attr_e( 'Quote wizard', 'bomedia-quote-wizard' ); ?>">

	<?php if ( $hero_on ) :
		$hero_bg      = $bqw_config['hero_image_url'] ?? '';
		$hero_classes = 'bqw-wiz-hero is-permanent' . ( $hero_bg ? ' has-bg-image' : '' );
		$hero_style   = $hero_bg ? '--bqw-hero-bg: url(' . esc_url( $hero_bg ) . ');' : '';
		?>
		<header class="<?php echo esc_attr( $hero_classes ); ?>" id="bqw-wiz-hero" style="<?php echo esc_attr( $hero_style ); ?>">
			<div class="bqw-wiz-hero-content">
				<h2 class="bqw-wiz-hero-title"><?php echo esc_html( $bqw_config['hero_title'] ?? '' ); ?></h2>
				<p class="bqw-wiz-hero-sub"><?php echo esc_html( $bqw_config['hero_subtitle'] ?? '' ); ?></p>
			</div>
		</header>
	<?php endif; ?>

	<nav class="bqw-wiz-progress" id="bqw-wiz-progress" hidden>
		<button type="button" class="bqw-wiz-back" id="bqw-wiz-back" hidden aria-label="<?php esc_attr_e( 'Back', 'bomedia-quote-wizard' ); ?>">← <?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
		<div class="bqw-wiz-dots" id="bqw-wiz-dots" aria-hidden="true"></div>
		<span class="bqw-wiz-step-label" id="bqw-wiz-step-label"></span>
	</nav>

	<main class="bqw-wiz-screen" id="bqw-wiz-screen" aria-live="polite"></main>

	<form class="bqw-form" id="bqw-form" novalidate hidden>
		<input type="hidden" name="action" value="bqw_submit" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'bqw_submit' ) ); ?>" />
		<input type="hidden" name="bqw_started" value="<?php echo esc_attr( (string) $started_ts ); ?>" />
		<input type="hidden" name="source_url" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>" />
		<input type="hidden" name="flow" id="bqw-flow" value="wizard" />
		<input type="hidden" name="session_id" id="bqw-session-id" value="" />
		<input type="hidden" name="selected_products_json" id="bqw-selected-products-json" value="[]" />
		<input type="hidden" name="unsure" id="bqw-unsure" value="0" />
		<input type="hidden" name="matchmaker_answers_json" id="bqw-mm-answers-json" value="" />
		<input type="hidden" name="extracted_fields_json" id="bqw-extracted-json" value="" />
		<input type="hidden" name="first_name" id="bqw-first-name" value="" />
		<input type="hidden" name="last_name" id="bqw-last-name" value="" />
		<input type="hidden" name="company" id="bqw-company" value="" />
		<input type="hidden" name="email" id="bqw-email" value="" />

		<div class="bqw-honeypot" aria-hidden="true">
			<label>Leave this empty: <input type="text" name="bqw_hp" tabindex="-1" autocomplete="off" /></label>
		</div>

		<?php if ( $captcha_on && $captcha ) : ?>
			<div class="bqw-captcha" id="bqw-captcha" data-provider="<?php echo esc_attr( $captcha_provider ); ?>">
				<?php if ( 'math' === $captcha_provider ) : ?>
					<label for="bqw-captcha-answer">
						<?php esc_html_e( 'Quick check:', 'bomedia-quote-wizard' ); ?>
						<strong><?php echo esc_html( $captcha['question'] ); ?> = ?</strong>
					</label>
					<input type="number" id="bqw-captcha-answer" name="bqw_captcha_answer" inputmode="numeric" />
					<input type="hidden" name="bqw_captcha_token" value="<?php echo esc_attr( $captcha['token'] ); ?>" />
					<input type="hidden" name="bqw_captcha_ts" value="<?php echo esc_attr( (string) $captcha['ts'] ); ?>" />
				<?php elseif ( 'recaptcha_v2' === $captcha_provider ) : ?>
					<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
				<?php elseif ( 'recaptcha_v3' === $captcha_provider ) : ?>
					<input type="hidden" name="g-recaptcha-response" id="bqw-recaptcha-v3" value="" />
				<?php elseif ( 'turnstile' === $captcha_provider ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
				<?php elseif ( 'hcaptcha' === $captcha_provider ) : ?>
					<div class="h-captcha" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</form>

	<div class="bqw-thanks" id="bqw-thanks" hidden></div>
</div>
