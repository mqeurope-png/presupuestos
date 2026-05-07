<?php
/**
 * Thanks template. Override by copying to your theme:
 *   wp-content/themes/<your-theme>/bomedia-quote-wizard/thanks.php
 *
 * Available variable: $bqw_data (array).
 *
 * @package Bomedia\QuoteWizard
 */

defined( 'ABSPATH' ) || exit;

/** @var array $bqw_data */
?>
<div class="bqw-thanks-inner">
	<div class="bqw-thanks-icon" aria-hidden="true">
		<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
	</div>
	<h3><?php esc_html_e( 'Thanks! We received your request.', 'bomedia-quote-wizard' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: customer first name */
			esc_html__( '%s, our team will contact you shortly with a personalized quote.', 'bomedia-quote-wizard' ),
			esc_html( $bqw_data['first_name'] ?? '' )
		);
		?>
	</p>
	<?php if ( ! empty( $bqw_data['product_name'] ) ) : ?>
		<p class="bqw-thanks-product">
			<strong><?php esc_html_e( 'Requested model:', 'bomedia-quote-wizard' ); ?></strong>
			<?php echo esc_html( $bqw_data['product_name'] ); ?>
		</p>
	<?php endif; ?>
	<p>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bqw-btn bqw-btn-primary"><?php esc_html_e( 'Back to home', 'bomedia-quote-wizard' ); ?></a>
	</p>
</div>
