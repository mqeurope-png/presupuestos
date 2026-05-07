<?php
/**
 * Chatbot template (v1.7.0). Override by copying to:
 *   wp-content/themes/<your-theme>/bomedia-quote-wizard/chat.php
 *
 * Available variable: $bqw_config (array).
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
<div class="bqw-chat-wrap" id="bqw-chat-wrap"
	data-redirect="<?php echo esc_attr( (string) ( $bqw_config['redirect_url'] ?? '' ) ); ?>"
	data-language="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>"
	role="region" aria-label="<?php esc_attr_e( 'Quote chatbot', 'bomedia-quote-wizard' ); ?>">

	<?php if ( $hero_on ) :
		$hero_bg = $bqw_config['hero_image_url'] ?? '';
		$hero_classes = 'bqw-chat-hero is-permanent' . ( $hero_bg ? ' has-bg-image' : '' );
		$hero_style   = $hero_bg ? '--bqw-hero-bg: url(' . esc_url( $hero_bg ) . ');' : '';
		?>
		<header class="<?php echo esc_attr( $hero_classes ); ?>" id="bqw-chat-hero" style="<?php echo esc_attr( $hero_style ); ?>">
			<div class="bqw-chat-hero-content">
				<h2 class="bqw-chat-hero-title"><?php echo esc_html( $bqw_config['hero_title'] ?? '' ); ?></h2>
				<p class="bqw-chat-hero-sub"><?php echo esc_html( $bqw_config['hero_subtitle'] ?? '' ); ?></p>
			</div>
		</header>
	<?php endif; ?>

	<div class="bqw-chat-layout" id="bqw-chat-layout">
		<main class="bqw-chat" id="bqw-chat">

			<div class="bqw-chat-bar">
				<div class="bqw-chat-title">
					<span class="bqw-chat-avatar" aria-hidden="true">B</span>
					<span><?php esc_html_e( 'Bomedia assistant', 'bomedia-quote-wizard' ); ?></span>
				</div>
				<button type="button" class="bqw-chat-skip" id="bqw-skip-to-send" title="<?php esc_attr_e( 'Skip the conversation and just send your details', 'bomedia-quote-wizard' ); ?>">
					<span class="bqw-skip-text"><?php esc_html_e( 'Skip to send', 'bomedia-quote-wizard' ); ?></span>
					<span class="bqw-skip-icon" aria-hidden="true">→</span>
				</button>
			</div>

			<div class="bqw-chat-stream" id="bqw-chat-stream" aria-live="polite"></div>

			<div class="bqw-chat-options" id="bqw-chat-options"></div>

			<!-- Bomedia full catalog browse view (revealed when the welcome
			     card "Browse the full Bomedia catalog" is picked). -->
			<div class="bqw-browse-view" id="bqw-browse-view" hidden>
				<div class="bqw-browse-filters">
					<input type="search" id="bqw-browse-search" placeholder="<?php esc_attr_e( 'Search machines…', 'bomedia-quote-wizard' ); ?>" />
					<select id="bqw-browse-brand" multiple aria-label="<?php esc_attr_e( 'Filter by brand', 'bomedia-quote-wizard' ); ?>"></select>
				</div>
				<div class="bqw-browse-grid" id="bqw-browse-grid"></div>
				<div class="bqw-browse-foot">
					<span class="bqw-rec-counter" id="bqw-browse-counter">0</span>
					<button type="button" class="bqw-btn bqw-btn-primary" id="bqw-browse-cta" disabled><?php esc_html_e( 'Continue', 'bomedia-quote-wizard' ); ?> →</button>
				</div>
			</div>
		</main>

		<aside class="bqw-rec-panel" id="bqw-rec-panel" aria-label="<?php esc_attr_e( 'Recommended machines', 'bomedia-quote-wizard' ); ?>">
			<div class="bqw-rec-panel-head">
				<h3><?php esc_html_e( 'Your recommendations', 'bomedia-quote-wizard' ); ?></h3>
				<button type="button" class="bqw-rec-panel-close" id="bqw-rec-panel-close" aria-label="<?php esc_attr_e( 'Close', 'bomedia-quote-wizard' ); ?>">×</button>
			</div>
			<div class="bqw-rec-panel-body" id="bqw-rec-panel-body">
				<p class="bqw-rec-placeholder"><?php esc_html_e( 'Your recommended machines will appear here as we chat.', 'bomedia-quote-wizard' ); ?></p>
			</div>
			<div class="bqw-rec-panel-foot" id="bqw-rec-panel-foot" hidden>
				<span class="bqw-rec-counter" id="bqw-rec-counter">0 / 3</span>
				<button type="button" class="bqw-btn bqw-btn-primary" id="bqw-rec-cta" disabled><?php esc_html_e( 'Request quote →', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</aside>
	</div>

	<button type="button" class="bqw-rec-fab" id="bqw-rec-fab" hidden aria-label="<?php esc_attr_e( 'Show recommended machines', 'bomedia-quote-wizard' ); ?>">
		<span class="bqw-rec-fab-count" id="bqw-rec-fab-count"></span>
		<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/></svg>
	</button>

	<!-- Contact form (revealed when ready_for_contact OR skip-to-send). -->
	<div class="bqw-contact-panel" id="bqw-contact-panel" hidden>
		<p class="bqw-contact-hint" id="bqw-contact-hint" hidden>
			<?php esc_html_e( 'You chose to skip the conversation. Want to use the assistant instead?', 'bomedia-quote-wizard' ); ?>
			<a href="#" id="bqw-back-to-chat"><?php esc_html_e( 'Open chat again →', 'bomedia-quote-wizard' ); ?></a>
		</p>

		<form class="bqw-form" id="bqw-form" novalidate>
			<input type="hidden" name="action" value="bqw_submit" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'bqw_submit' ) ); ?>" />
			<input type="hidden" name="bqw_started" value="<?php echo esc_attr( (string) $started_ts ); ?>" />
			<input type="hidden" name="source_url" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>" />
			<input type="hidden" name="flow" id="bqw-flow" value="chat" />
			<input type="hidden" name="session_id" id="bqw-session-id" value="" />
			<input type="hidden" name="selected_products_json" id="bqw-selected-products-json" value="[]" />
			<input type="hidden" name="unsure" id="bqw-unsure" value="0" />
			<input type="hidden" name="matchmaker_answers_json" id="bqw-mm-answers-json" value="" />
			<input type="hidden" name="extracted_fields_json" id="bqw-extracted-json" value="" />

			<div class="bqw-honeypot" aria-hidden="true">
				<label>Leave this empty: <input type="text" name="bqw_hp" tabindex="-1" autocomplete="off" /></label>
			</div>

			<h3 class="bqw-step-title"><?php esc_html_e( 'Your details', 'bomedia-quote-wizard' ); ?></h3>

			<div class="bqw-row">
				<div class="bqw-field">
					<label for="bqw-first-name"><?php esc_html_e( 'First name', 'bomedia-quote-wizard' ); ?> *</label>
					<input type="text" id="bqw-first-name" name="first_name" required autocomplete="given-name" />
				</div>
				<div class="bqw-field">
					<label for="bqw-last-name"><?php esc_html_e( 'Last name', 'bomedia-quote-wizard' ); ?></label>
					<input type="text" id="bqw-last-name" name="last_name" autocomplete="family-name" />
				</div>
			</div>

			<!-- Hidden empty company so legacy AgileCRM mapping doesn't break. -->
			<input type="hidden" id="bqw-company" name="company" value="" />

			<div class="bqw-row">
				<div class="bqw-field">
					<label for="bqw-email"><?php esc_html_e( 'Email', 'bomedia-quote-wizard' ); ?> *</label>
					<input type="email" id="bqw-email" name="email" required autocomplete="email" />
				</div>
				<div class="bqw-field">
					<label for="bqw-country"><?php esc_html_e( 'Country', 'bomedia-quote-wizard' ); ?> *</label>
					<select id="bqw-country" name="country" required></select>
				</div>
			</div>

			<div class="bqw-field">
				<label for="bqw-phone"><?php esc_html_e( 'Phone', 'bomedia-quote-wizard' ); ?> *</label>
				<div class="bqw-phone-wrap">
					<select id="bqw-dial-prefix" class="bqw-dial-select" aria-label="<?php esc_attr_e( 'Country code', 'bomedia-quote-wizard' ); ?>"></select>
					<input type="tel" id="bqw-phone" name="phone" required autocomplete="tel" />
				</div>
				<input type="hidden" id="bqw-dial" value="+34" />
			</div>

			<div class="bqw-field">
				<label for="bqw-message"><?php esc_html_e( 'Message (optional)', 'bomedia-quote-wizard' ); ?></label>
				<textarea id="bqw-message" name="message" rows="3" placeholder="<?php esc_attr_e( 'Tell us what you need…', 'bomedia-quote-wizard' ); ?>"></textarea>
			</div>

			<?php if ( $captcha_on && $captcha ) : ?>
				<div class="bqw-captcha bqw-field" id="bqw-captcha" data-provider="<?php echo esc_attr( $captcha_provider ); ?>">
					<?php if ( 'math' === $captcha_provider ) : ?>
						<label for="bqw-captcha-answer">
							<?php esc_html_e( 'Quick check:', 'bomedia-quote-wizard' ); ?>
							<strong><?php echo esc_html( $captcha['question'] ); ?> = ?</strong>
						</label>
						<input type="number" id="bqw-captcha-answer" name="bqw_captcha_answer" inputmode="numeric" required />
						<input type="hidden" name="bqw_captcha_token" value="<?php echo esc_attr( $captcha['token'] ); ?>" />
						<input type="hidden" name="bqw_captcha_ts" value="<?php echo esc_attr( (string) $captcha['ts'] ); ?>" />
					<?php elseif ( 'recaptcha_v2' === $captcha_provider ) : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
					<?php elseif ( 'recaptcha_v3' === $captcha_provider ) : ?>
						<input type="hidden" name="g-recaptcha-response" id="bqw-recaptcha-v3" value="" />
						<p class="bqw-captcha-note"><?php esc_html_e( 'Protected by Google reCAPTCHA.', 'bomedia-quote-wizard' ); ?></p>
					<?php elseif ( 'turnstile' === $captcha_provider ) : ?>
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
					<?php elseif ( 'hcaptcha' === $captcha_provider ) : ?>
						<div class="h-captcha" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
					<?php endif; ?>
					<p class="bqw-captcha-note"><?php esc_html_e( 'Helps us avoid spam.', 'bomedia-quote-wizard' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="bqw-field bqw-field-check">
				<label>
					<input type="checkbox" id="bqw-privacy" name="privacy" value="1" required />
					<?php
					$privacy_url = $bqw_config['privacy_url'] ?? '';
					if ( $privacy_url ) {
						printf(
							/* translators: %s: privacy policy URL */
							wp_kses(
								__( 'I have read and accept the <a href="%s" target="_blank" rel="noopener">privacy policy</a>', 'bomedia-quote-wizard' ),
								[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
							),
							esc_url( $privacy_url )
						);
					} else {
						esc_html_e( 'I have read and accept the privacy policy', 'bomedia-quote-wizard' );
					}
					?>
				</label>
			</div>

			<?php if ( $optin_on ) : ?>
				<div class="bqw-field bqw-field-check bqw-field-optin">
					<label>
						<input type="checkbox" id="bqw-email-optin" name="email_optin" value="1" />
						<?php echo esc_html( $optin_label ); ?>
					</label>
				</div>
			<?php endif; ?>

			<div class="bqw-actions">
				<button type="submit" class="bqw-btn bqw-btn-primary" id="bqw-submit">
					<span class="bqw-submit-label"><?php esc_html_e( 'Send my request', 'bomedia-quote-wizard' ); ?></span>
					<span class="bqw-spinner" hidden></span>
				</button>
			</div>
			<p class="bqw-error" id="bqw-error" role="alert" aria-live="polite" hidden></p>
		</form>
	</div>

	<div class="bqw-thanks" id="bqw-thanks" hidden></div>
</div>
