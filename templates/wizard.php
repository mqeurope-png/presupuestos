<?php
/**
 * Wizard template. Override by copying to your theme:
 *   wp-content/themes/<your-theme>/bomedia-quote-wizard/wizard.php
 *
 * Available variable: $bqw_config (array).
 *
 * @package Bomedia\QuoteWizard
 */

defined( 'ABSPATH' ) || exit;

/** @var array $bqw_config */
$forced_product = $bqw_config['forced_product'] ?? null;
$started_ts     = time();
$captcha_on     = ! empty( $bqw_config['enable_captcha'] );
$captcha        = $captcha_on ? ( $bqw_config['captcha'] ?? null ) : null;
$captcha_provider = $captcha ? ( $captcha['provider'] ?? 'math' ) : 'math';
$optin_on       = ! empty( $bqw_config['enable_email_optin'] );
$optin_label    = (string) ( $bqw_config['email_optin_label'] ?? '' );
$hero_on        = ! empty( $bqw_config['enable_hero'] );
$matchmaker_on  = ! empty( $bqw_config['enable_matchmaker'] );

/**
 * Render an option card with an inline SVG icon picked from the label.
 *
 * @param string $label Display label.
 * @param string $name  Form field name.
 * @param string $type  'checkbox' or 'radio'.
 */
$render_option_card = static function ( string $label, string $name, string $type ): void {
	$svg = \Bomedia\QuoteWizard\Icons::for_label( $label );
	?>
	<label class="bqw-opt-card">
		<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $label ); ?>" />
		<span class="bqw-opt-img bqw-opt-img-fallback" aria-hidden="true"><?php
			// Icon SVG is built from a curated whitelist (see Icons::SVGS) so it's safe to print.
			echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?></span>
		<span class="bqw-opt-label"><?php echo esc_html( $label ); ?></span>
		<span class="bqw-opt-check" aria-hidden="true">✓</span>
	</label>
	<?php
};
?>
<div class="bqw-wizard" id="bqw-wizard"
	data-started="<?php echo esc_attr( (string) $started_ts ); ?>"
	data-redirect="<?php echo esc_attr( (string) ( $bqw_config['redirect_url'] ?? '' ) ); ?>"
	data-microcopy='<?php echo esc_attr( wp_json_encode( $bqw_config['microcopy_messages'] ?? [] ) ); ?>'
	data-microcopy-on="<?php echo ! empty( $bqw_config['enable_microcopy'] ) ? '1' : '0'; ?>"
	data-matchmaker-on="<?php echo $matchmaker_on ? '1' : '0'; ?>"
	role="region" aria-label="<?php esc_attr_e( 'Quote request wizard', 'bomedia-quote-wizard' ); ?>">

	<?php if ( $hero_on ) : ?>
		<section class="bqw-hero" id="bqw-hero" <?php if ( ! empty( $bqw_config['hero_image_url'] ) ) : ?> style="background-image:url('<?php echo esc_url( $bqw_config['hero_image_url'] ); ?>');"<?php endif; ?>>
			<div class="bqw-hero-overlay">
				<h2 class="bqw-hero-title"><?php echo esc_html( $bqw_config['hero_title'] ); ?></h2>
				<p class="bqw-hero-sub"><?php echo esc_html( $bqw_config['hero_subtitle'] ); ?></p>
				<div class="bqw-hero-cta">
					<?php if ( $matchmaker_on ) : ?>
						<button type="button" class="bqw-btn bqw-btn-primary" data-flow="classic"><?php esc_html_e( 'I know which machine I want', 'bomedia-quote-wizard' ); ?></button>
						<button type="button" class="bqw-btn bqw-btn-secondary bqw-btn-light" data-flow="matchmaker"><?php esc_html_e( 'Help me choose', 'bomedia-quote-wizard' ); ?></button>
					<?php else : ?>
						<button type="button" class="bqw-btn bqw-btn-primary" data-flow="classic"><?php esc_html_e( 'Start', 'bomedia-quote-wizard' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<div class="bqw-progress" id="bqw-progress" <?php if ( $hero_on ) : ?>hidden<?php endif; ?>>
		<div class="bqw-progress-bar"><span class="bqw-progress-fill" id="bqw-progress-fill"></span></div>
		<span class="bqw-progress-pct" id="bqw-progress-pct">0%</span>
	</div>

	<div class="bqw-microcopy" id="bqw-microcopy" hidden></div>

	<form class="bqw-form" id="bqw-form" novalidate <?php if ( $hero_on ) : ?>hidden<?php endif; ?>>
		<input type="hidden" name="action" value="bqw_submit" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'bqw_submit' ) ); ?>" />
		<input type="hidden" name="bqw_started" value="<?php echo esc_attr( (string) $started_ts ); ?>" />
		<input type="hidden" name="source_url" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>" />
		<input type="hidden" name="flow" id="bqw-flow" value="classic" />
		<input type="hidden" name="selected_products_json" id="bqw-selected-products-json" value="[]" />
		<input type="hidden" name="unsure" id="bqw-unsure" value="0" />
		<input type="hidden" name="matchmaker_answers_json" id="bqw-mm-answers-json" value="" />

		<div class="bqw-honeypot" aria-hidden="true">
			<label>Leave this empty: <input type="text" name="bqw_hp" tabindex="-1" autocomplete="off" /></label>
		</div>

		<!-- ============================================================
		 *  CLASSIC FLOW: Steps 1 → 4
		 * ============================================================ -->

		<!-- STEP 1 — Products of interest -->
		<section class="bqw-step" data-step="1" data-flow="classic" aria-labelledby="bqw-step1-title" hidden>
			<h3 id="bqw-step1-title" class="bqw-step-title"><?php esc_html_e( 'Products of interest', 'bomedia-quote-wizard' ); ?></h3>

			<div class="bqw-selection-strip" id="bqw-selection-strip" hidden>
				<span class="bqw-selection-count" id="bqw-selection-count"></span>
				<div class="bqw-selection-avatars" id="bqw-selection-avatars"></div>
				<button type="button" class="bqw-selection-edit" id="bqw-selection-edit"><?php esc_html_e( 'Edit selection', 'bomedia-quote-wizard' ); ?></button>
			</div>

			<input type="hidden" name="category_id" id="bqw-category-id" />
			<input type="hidden" name="category_slug" id="bqw-category-slug" />
			<input type="hidden" name="category_name" id="bqw-category-name" />
			<input type="hidden" name="product_id" id="bqw-product-id" />
			<input type="hidden" name="product_name" id="bqw-product-name" />

			<div class="bqw-screen bqw-screen-categories" id="bqw-step1-categories"></div>

			<div class="bqw-screen bqw-screen-products" id="bqw-step1-products" hidden>
				<div class="bqw-products-header">
					<button type="button" class="bqw-back-link" id="bqw-back-to-categories"><?php esc_html_e( '← Back to categories', 'bomedia-quote-wizard' ); ?></button>
					<h4 class="bqw-products-title" id="bqw-products-title"></h4>
				</div>
				<div class="bqw-products-body" id="bqw-products-body"></div>
				<div class="bqw-products-footer">
					<button type="button" class="bqw-btn bqw-btn-secondary" id="bqw-add-from-other"><?php esc_html_e( 'Add machines from another category', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</div>

			<div class="bqw-actions">
				<span></span>
				<button type="button" class="bqw-btn bqw-btn-primary" id="bqw-next-1" disabled><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 2 — Application / Materials / Volume -->
		<section class="bqw-step" data-step="2" data-flow="classic" aria-labelledby="bqw-step2-title" hidden>
			<h3 id="bqw-step2-title" class="bqw-step-title"><?php esc_html_e( 'Your application', 'bomedia-quote-wizard' ); ?></h3>

			<?php if ( ! empty( $bqw_config['enable_application'] ) ) : ?>
				<fieldset class="bqw-fieldset" id="bqw-application-fieldset">
					<legend><?php esc_html_e( 'Application (one or more)', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-opt-grid">
						<?php foreach ( $bqw_config['application_options'] as $i => $opt ) {
							$render_option_card( $opt, 'application[]', 'checkbox' );
						} ?>
					</div>
					<p class="bqw-field-error" id="bqw-application-error" hidden><?php esc_html_e( 'Please pick at least one application.', 'bomedia-quote-wizard' ); ?></p>
				</fieldset>
			<?php endif; ?>

			<?php if ( ! empty( $bqw_config['enable_materials'] ) ) : ?>
				<fieldset class="bqw-fieldset">
					<legend><?php esc_html_e( 'Materials (multi-select)', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-opt-grid">
						<?php foreach ( $bqw_config['materials_options'] as $i => $opt ) {
							$render_option_card( $opt, 'materials[]', 'checkbox' );
						} ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<?php if ( ! empty( $bqw_config['enable_volume'] ) ) : ?>
				<fieldset class="bqw-fieldset">
					<legend><?php esc_html_e( 'Estimated monthly volume', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-opt-grid bqw-opt-grid-narrow">
						<?php foreach ( $bqw_config['volume_options'] as $i => $opt ) {
							$render_option_card( $opt, 'volume', 'radio' );
						} ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<div class="bqw-actions">
				<button type="button" class="bqw-btn bqw-btn-secondary" data-prev="1"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
				<button type="button" class="bqw-btn bqw-btn-primary" data-next="3"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 3 — Contact details (shared between flows) -->
		<section class="bqw-step" data-step="3" aria-labelledby="bqw-step3-title" hidden>
			<h3 id="bqw-step3-title" class="bqw-step-title"><?php esc_html_e( 'Your details', 'bomedia-quote-wizard' ); ?></h3>

			<div class="bqw-row">
				<div class="bqw-field">
					<label for="bqw-first-name"><?php esc_html_e( 'First name', 'bomedia-quote-wizard' ); ?> *</label>
					<input type="text" id="bqw-first-name" name="first_name" required autocomplete="given-name" />
				</div>
				<div class="bqw-field">
					<label for="bqw-last-name"><?php esc_html_e( 'Last name', 'bomedia-quote-wizard' ); ?> *</label>
					<input type="text" id="bqw-last-name" name="last_name" required autocomplete="family-name" />
				</div>
			</div>

			<div class="bqw-field">
				<label for="bqw-company"><?php esc_html_e( 'Company', 'bomedia-quote-wizard' ); ?> *</label>
				<input type="text" id="bqw-company" name="company" required autocomplete="organization" />
			</div>

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
					<span class="bqw-dial" id="bqw-dial">+34</span>
					<input type="tel" id="bqw-phone" name="phone" required autocomplete="tel" />
				</div>
			</div>

			<div class="bqw-field">
				<label for="bqw-message"><?php esc_html_e( 'Message (optional)', 'bomedia-quote-wizard' ); ?></label>
				<textarea id="bqw-message" name="message" rows="3"></textarea>
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
				<button type="button" class="bqw-btn bqw-btn-secondary" data-prev-flow><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
				<button type="button" class="bqw-btn bqw-btn-primary" data-next="4"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 4 — Confirmation (shared) -->
		<section class="bqw-step" data-step="4" aria-labelledby="bqw-step4-title" hidden>
			<h3 id="bqw-step4-title" class="bqw-step-title"><?php esc_html_e( 'Confirmation', 'bomedia-quote-wizard' ); ?></h3>
			<div class="bqw-summary" id="bqw-summary"></div>

			<div class="bqw-actions">
				<button type="button" class="bqw-btn bqw-btn-secondary" data-prev="3"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
				<button type="submit" class="bqw-btn bqw-btn-primary" id="bqw-submit">
					<span class="bqw-submit-label"><?php esc_html_e( 'Send request', 'bomedia-quote-wizard' ); ?></span>
					<span class="bqw-spinner" hidden></span>
				</button>
			</div>
			<p class="bqw-error" id="bqw-error" role="alert" aria-live="polite" hidden></p>
		</section>

		<!-- ============================================================
		 *  MATCHMAKER FLOW: m1 → m5 → recommendations
		 * ============================================================ -->

		<?php if ( $matchmaker_on ) : ?>
			<section class="bqw-step" data-step="m1" data-flow="matchmaker" aria-labelledby="bqw-mm1-title" hidden>
				<h3 id="bqw-mm1-title" class="bqw-step-title"><?php esc_html_e( 'What do you want to produce?', 'bomedia-quote-wizard' ); ?></h3>
				<?php if ( ! empty( $bqw_config['application_options'] ) ) : ?>
					<div class="bqw-opt-grid">
						<?php foreach ( $bqw_config['application_options'] as $i => $opt ) {
							$render_option_card( $opt, 'mm_application[]', 'checkbox' );
						} ?>
					</div>
				<?php endif; ?>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="hero"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" data-mm-next="m2"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>

			<section class="bqw-step" data-step="m2" data-flow="matchmaker" aria-labelledby="bqw-mm2-title" hidden>
				<h3 id="bqw-mm2-title" class="bqw-step-title"><?php esc_html_e( 'On which materials?', 'bomedia-quote-wizard' ); ?></h3>
				<div class="bqw-opt-grid">
					<?php foreach ( $bqw_config['materials_options'] as $i => $opt ) {
						$render_option_card( $opt, 'mm_materials[]', 'checkbox' );
					} ?>
				</div>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="m1"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" data-mm-next="m3"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>

			<section class="bqw-step" data-step="m3" data-flow="matchmaker" aria-labelledby="bqw-mm3-title" hidden>
				<h3 id="bqw-mm3-title" class="bqw-step-title"><?php esc_html_e( 'Estimated monthly volume?', 'bomedia-quote-wizard' ); ?></h3>
				<div class="bqw-opt-grid bqw-opt-grid-narrow">
					<?php foreach ( $bqw_config['volume_options'] as $i => $opt ) {
						$render_option_card( $opt, 'mm_volume', 'radio' );
					} ?>
				</div>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="m2"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" data-mm-next="m4"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>

			<section class="bqw-step" data-step="m4" data-flow="matchmaker" aria-labelledby="bqw-mm4-title" hidden>
				<h3 id="bqw-mm4-title" class="bqw-step-title"><?php esc_html_e( 'Maximum piece size?', 'bomedia-quote-wizard' ); ?></h3>
				<div class="bqw-opt-grid">
					<?php foreach ( $bqw_config['matchmaker_format_options'] as $i => $opt ) {
						$render_option_card( $opt, 'mm_format[]', 'checkbox' );
					} ?>
				</div>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="m3"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" data-mm-next="m5"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>

			<section class="bqw-step" data-step="m5" data-flow="matchmaker" aria-labelledby="bqw-mm5-title" hidden>
				<h3 id="bqw-mm5-title" class="bqw-step-title"><?php esc_html_e( 'Approximate budget? (optional)', 'bomedia-quote-wizard' ); ?></h3>
				<div class="bqw-opt-grid">
					<?php foreach ( $bqw_config['matchmaker_budget_options'] as $i => $opt ) {
						$render_option_card( $opt, 'mm_budget', 'radio' );
					} ?>
				</div>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="m4"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" data-mm-next="rec"><?php esc_html_e( 'See recommendations', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>

			<section class="bqw-step" data-step="rec" data-flow="matchmaker" aria-labelledby="bqw-rec-title" hidden>
				<h3 id="bqw-rec-title" class="bqw-step-title"><?php esc_html_e( 'Best matches for you', 'bomedia-quote-wizard' ); ?></h3>
				<div class="bqw-rec-list" id="bqw-rec-list"></div>
				<div class="bqw-actions">
					<button type="button" class="bqw-btn bqw-btn-secondary" data-mm-back="m5"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
					<button type="button" class="bqw-btn bqw-btn-primary" id="bqw-rec-next" disabled><?php esc_html_e( 'Continue', 'bomedia-quote-wizard' ); ?></button>
				</div>
			</section>
		<?php endif; ?>

	</form>

	<!-- Modal: edit current selection -->
	<div class="bqw-modal" id="bqw-selection-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bqw-selection-modal-title">
		<div class="bqw-modal-backdrop" data-close="1"></div>
		<div class="bqw-modal-card">
			<header>
				<h4 id="bqw-selection-modal-title"><?php esc_html_e( 'Your selected machines', 'bomedia-quote-wizard' ); ?></h4>
				<button type="button" class="bqw-modal-close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'bomedia-quote-wizard' ); ?>">×</button>
			</header>
			<ul class="bqw-modal-list" id="bqw-selection-modal-list"></ul>
		</div>
	</div>

	<div class="bqw-thanks" id="bqw-thanks" hidden></div>
</div>
