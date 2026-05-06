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
?>
<div class="bqw-wizard" id="bqw-wizard" data-started="<?php echo esc_attr( (string) $started_ts ); ?>" role="region" aria-label="<?php esc_attr_e( 'Quote request wizard', 'bomedia-quote-wizard' ); ?>">

	<div class="bqw-progress" aria-hidden="true">
		<ol class="bqw-progress-steps">
			<li class="bqw-progress-step is-active" data-step="1"><span class="bqw-progress-num">1</span><span class="bqw-progress-label"><?php esc_html_e( 'Product', 'bomedia-quote-wizard' ); ?></span></li>
			<li class="bqw-progress-step" data-step="2"><span class="bqw-progress-num">2</span><span class="bqw-progress-label"><?php esc_html_e( 'Application', 'bomedia-quote-wizard' ); ?></span></li>
			<li class="bqw-progress-step" data-step="3"><span class="bqw-progress-num">3</span><span class="bqw-progress-label"><?php esc_html_e( 'Details', 'bomedia-quote-wizard' ); ?></span></li>
			<li class="bqw-progress-step" data-step="4"><span class="bqw-progress-num">4</span><span class="bqw-progress-label"><?php esc_html_e( 'Confirm', 'bomedia-quote-wizard' ); ?></span></li>
		</ol>
	</div>

	<form class="bqw-form" id="bqw-form" novalidate>
		<input type="hidden" name="action" value="bqw_submit" />
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'bqw_submit' ) ); ?>" />
		<input type="hidden" name="bqw_started" value="<?php echo esc_attr( (string) $started_ts ); ?>" />
		<input type="hidden" name="source_url" value="<?php echo esc_attr( home_url( add_query_arg( null, null ) ) ); ?>" />
		<input type="hidden" name="category_id" id="bqw-category-id" />
		<input type="hidden" name="category_slug" id="bqw-category-slug" />
		<input type="hidden" name="category_name" id="bqw-category-name" />
		<input type="hidden" name="product_id" id="bqw-product-id" />
		<input type="hidden" name="product_name" id="bqw-product-name" />

		<div class="bqw-honeypot" aria-hidden="true">
			<label>Leave this empty: <input type="text" name="bqw_hp" tabindex="-1" autocomplete="off" /></label>
		</div>

		<!-- STEP 1 -->
		<section class="bqw-step is-active" data-step="1" aria-labelledby="bqw-step1-title">
			<h3 id="bqw-step1-title" class="bqw-step-title"><?php esc_html_e( 'Product of interest', 'bomedia-quote-wizard' ); ?></h3>

			<!-- Screen 1.1 — categories -->
			<div class="bqw-screen bqw-screen-categories" id="bqw-step1-categories"></div>

			<!-- Screen 1.2 — products of selected category -->
			<div class="bqw-screen bqw-screen-products" id="bqw-step1-products" hidden>
				<div class="bqw-products-header">
					<button type="button" class="bqw-back-link" id="bqw-back-to-categories"><?php esc_html_e( '← Back to categories', 'bomedia-quote-wizard' ); ?></button>
					<h4 class="bqw-products-title" id="bqw-products-title"></h4>
				</div>
				<div class="bqw-products-body" id="bqw-products-body"></div>
			</div>

			<div class="bqw-actions">
				<span></span>
				<button type="button" class="bqw-btn bqw-btn-primary" id="bqw-next-1" disabled><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 2 -->
		<section class="bqw-step" data-step="2" aria-labelledby="bqw-step2-title" hidden>
			<h3 id="bqw-step2-title" class="bqw-step-title"><?php esc_html_e( 'Your application', 'bomedia-quote-wizard' ); ?></h3>

			<?php if ( ! empty( $bqw_config['enable_application'] ) ) : ?>
				<fieldset class="bqw-fieldset">
					<legend><?php esc_html_e( 'Application', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-radio-cards" id="bqw-application">
						<?php foreach ( $bqw_config['application_options'] as $opt ) : ?>
							<label class="bqw-radio-card">
								<input type="radio" name="application" value="<?php echo esc_attr( $opt ); ?>" />
								<span><?php echo esc_html( $opt ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<?php if ( ! empty( $bqw_config['enable_materials'] ) ) : ?>
				<fieldset class="bqw-fieldset">
					<legend><?php esc_html_e( 'Materials (multi-select)', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-check-grid">
						<?php foreach ( $bqw_config['materials_options'] as $opt ) : ?>
							<label class="bqw-check">
								<input type="checkbox" name="materials[]" value="<?php echo esc_attr( $opt ); ?>" />
								<span><?php echo esc_html( $opt ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<?php if ( ! empty( $bqw_config['enable_volume'] ) ) : ?>
				<fieldset class="bqw-fieldset">
					<legend><?php esc_html_e( 'Estimated monthly volume', 'bomedia-quote-wizard' ); ?></legend>
					<div class="bqw-radio-row">
						<?php foreach ( $bqw_config['volume_options'] as $opt ) : ?>
							<label class="bqw-radio-pill">
								<input type="radio" name="volume" value="<?php echo esc_attr( $opt ); ?>" />
								<span><?php echo esc_html( $opt ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<div class="bqw-actions">
				<button type="button" class="bqw-btn bqw-btn-secondary" data-prev="1"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
				<button type="button" class="bqw-btn bqw-btn-primary" data-next="3"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 3 -->
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

			<div class="bqw-actions">
				<button type="button" class="bqw-btn bqw-btn-secondary" data-prev="2"><?php esc_html_e( 'Back', 'bomedia-quote-wizard' ); ?></button>
				<button type="button" class="bqw-btn bqw-btn-primary" data-next="4"><?php esc_html_e( 'Next', 'bomedia-quote-wizard' ); ?></button>
			</div>
		</section>

		<!-- STEP 4 -->
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

	</form>

	<div class="bqw-thanks" id="bqw-thanks" hidden></div>
</div>
