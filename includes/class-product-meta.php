<?php
/**
 * Per-product internal AI notes meta box.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Product_Meta {

	public const META_KEY = '_bqw_ai_notes';

	private static ?Product_Meta $instance = null;

	public static function instance(): Product_Meta {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_product', [ $this, 'save_meta' ], 10, 2 );
	}

	public function add_meta_box(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		add_meta_box(
			'bqw_ai_notes',
			__( 'Matchmaker AI internal notes', 'bomedia-quote-wizard' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'default'
		);
	}

	public function render_meta_box( $post ): void {
		wp_nonce_field( 'bqw_save_ai_notes', 'bqw_ai_notes_nonce' );
		$value = get_post_meta( $post->ID, self::META_KEY, true );
		?>
		<p style="font-size:12px;color:#555;margin:0 0 8px;">
			<?php esc_html_e( 'Notes the AI will use to recommend this machine. Examples: "ideal for beginners", "EU stock", "best for small textile shops", "avoid for >2000/month". Not shown to the customer.', 'bomedia-quote-wizard' ); ?>
		</p>
		<textarea
			name="bqw_ai_notes"
			rows="10"
			style="width:100%;font-family:inherit;font-size:13px;"
			placeholder="<?php esc_attr_e( 'e.g. ideal for textile, EU stock, best for SMB, avoid >2000 prints/month', 'bomedia-quote-wizard' ); ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
		<?php
	}

	public function save_meta( int $post_id, $post ): void {
		if ( ! isset( $_POST['bqw_ai_notes_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bqw_ai_notes_nonce'] ) ), 'bqw_save_ai_notes' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['bqw_ai_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bqw_ai_notes'] ) ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $raw );
		}
	}
}
