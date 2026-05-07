<?php
/**
 * Lead custom post type for audit and retry.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Lead_CPT {

	public const POST_TYPE = 'bqw_lead';

	private static ?Lead_CPT $instance = null;

	public static function instance(): Lead_CPT {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'status_filter' ] );
		add_filter( 'parse_query', [ $this, 'apply_status_filter' ] );
		add_action( 'admin_post_bqw_retry_lead', [ $this, 'handle_retry' ] );
		add_action( 'admin_post_bqw_delete_session', [ $this, 'handle_delete_session' ] );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'register_abandoned_submenu' ] );
	}

	public function register_abandoned_submenu(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Abandoned conversations', 'bomedia-quote-wizard' ),
			__( 'Abandoned', 'bomedia-quote-wizard' ),
			'edit_posts',
			'bqw-abandoned',
			[ $this, 'render_abandoned_page' ]
		);
	}

	public function render_abandoned_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$rows = Conversations::abandoned( 3, 100 );
		echo '<div class="wrap"><h1>' . esc_html__( 'Abandoned conversations', 'bomedia-quote-wizard' ) . '</h1>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No abandoned conversations with 3+ messages.', 'bomedia-quote-wizard' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Sessions with at least 3 messages that did not result in a lead. Click a row to view the conversation.', 'bomedia-quote-wizard' ) . '</p>';
		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $view ) {
			$this->render_session_view( $view );
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'First message', 'bomedia-quote-wizard' ) . '</th>';
		echo '<th>' . esc_html__( 'Last message', 'bomedia-quote-wizard' ) . '</th>';
		echo '<th>' . esc_html__( 'Messages', 'bomedia-quote-wizard' ) . '</th>';
		echo '<th>' . esc_html__( 'IP', 'bomedia-quote-wizard' ) . '</th>';
		echo '<th>' . esc_html__( 'Session', 'bomedia-quote-wizard' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'bomedia-quote-wizard' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$view_url = add_query_arg( [ 'post_type' => self::POST_TYPE, 'page' => 'bqw-abandoned', 'view' => $r['session_id'] ], admin_url( 'edit.php' ) );
			$delete_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bqw_delete_session&session=' . rawurlencode( $r['session_id'] ) ),
				'bqw_delete_session_' . $r['session_id']
			);
			echo '<tr>';
			echo '<td>' . esc_html( $r['first_at'] ) . '</td>';
			echo '<td>' . esc_html( $r['last_at'] ) . '</td>';
			echo '<td>' . (int) $r['messages'] . '</td>';
			echo '<td>' . esc_html( $r['ip'] ) . '</td>';
			echo '<td><code>' . esc_html( substr( $r['session_id'], 0, 8 ) ) . '…</code></td>';
			echo '<td><a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'bomedia-quote-wizard' ) . '</a> · ';
			echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this conversation?', 'bomedia-quote-wizard' ) ) . '\');">' . esc_html__( 'Delete', 'bomedia-quote-wizard' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_session_view( string $session_id ): void {
		$rows = Conversations::history( $session_id, 200 );
		$back = remove_query_arg( 'view' );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to list', 'bomedia-quote-wizard' ) . '</a></p>';
		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:12px;max-width:760px;">';
		foreach ( $rows as $r ) {
			$role = $r['role'];
			$bg = ( 'assistant' === $role ) ? '#f1f5f9' : '#dbeafe';
			$align = ( 'assistant' === $role ) ? 'left' : 'right';
			echo '<div style="margin:6px 0;text-align:' . esc_attr( $align ) . ';">';
			echo '<span style="display:inline-block;background:' . esc_attr( $bg ) . ';padding:6px 10px;border-radius:8px;max-width:75%;text-align:left;">';
			echo '<small style="color:#777;">' . esc_html( $r['role'] ) . ' · ' . esc_html( $r['created_at'] ) . '</small><br/>';
			echo esc_html( $r['content'] );
			echo '</span></div>';
		}
		echo '</div></div>';
	}

	public function handle_delete_session(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden', 'bomedia-quote-wizard' ) );
		}
		$sid = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
		check_admin_referer( 'bqw_delete_session_' . $sid );
		Conversations::delete_session( $sid );
		wp_safe_redirect( add_query_arg( [ 'post_type' => self::POST_TYPE, 'page' => 'bqw-abandoned' ], admin_url( 'edit.php' ) ) );
		exit;
	}

	public function register_cpt(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'          => __( 'Quote Leads', 'bomedia-quote-wizard' ),
					'singular_name' => __( 'Quote Lead', 'bomedia-quote-wizard' ),
					'menu_name'     => __( 'Quote Leads', 'bomedia-quote-wizard' ),
				],
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-feedback',
				'supports'            => [ 'title' ],
				'capability_type'     => 'post',
				'capabilities'        => [
					'create_posts' => 'do_not_allow',
				],
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
			]
		);
	}

	public static function create_lead( array $data, string $status, $contact_id = null, ?string $error = null ): int {
		$title = sprintf(
			'%s %s — %s',
			$data['first_name'] ?? '',
			$data['last_name'] ?? '',
			$data['company'] ?? ''
		);
		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_strip_all_tags( $title ),
			],
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, '_bqw_data', wp_json_encode( $data ) );
		update_post_meta( $post_id, '_bqw_status', $status );
		update_post_meta( $post_id, '_bqw_first_name', $data['first_name'] ?? '' );
		update_post_meta( $post_id, '_bqw_last_name', $data['last_name'] ?? '' );
		update_post_meta( $post_id, '_bqw_company', $data['company'] ?? '' );
		update_post_meta( $post_id, '_bqw_email', $data['email'] ?? '' );
		update_post_meta( $post_id, '_bqw_product_name', $data['product_name'] ?? '' );
		update_post_meta( $post_id, '_bqw_product_ids', implode( ',', (array) ( $data['product_ids'] ?? [] ) ) );
		update_post_meta( $post_id, '_bqw_category_names', $data['category_name'] ?? '' );
		update_post_meta( $post_id, '_bqw_email_optin', ! empty( $data['email_optin'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_bqw_ip', $data['ip'] ?? '' );
		update_post_meta( $post_id, '_bqw_user_agent', $data['user_agent'] ?? '' );
		if ( $contact_id ) {
			update_post_meta( $post_id, '_bqw_agile_id', (string) $contact_id );
		}
		if ( $error ) {
			update_post_meta( $post_id, '_bqw_error', $error );
		}
		return (int) $post_id;
	}

	public static function update_status( int $post_id, string $status, $contact_id = null, ?string $error = null ): void {
		update_post_meta( $post_id, '_bqw_status', $status );
		if ( $contact_id ) {
			update_post_meta( $post_id, '_bqw_agile_id', (string) $contact_id );
		}
		if ( null !== $error ) {
			update_post_meta( $post_id, '_bqw_error', $error );
		}
	}

	public function columns( array $cols ): array {
		return [
			'cb'           => $cols['cb'] ?? '',
			'title'        => __( 'Lead', 'bomedia-quote-wizard' ),
			'bqw_company'  => __( 'Company', 'bomedia-quote-wizard' ),
			'bqw_email'    => __( 'Email', 'bomedia-quote-wizard' ),
			'bqw_product'  => __( 'Product', 'bomedia-quote-wizard' ),
			'bqw_status'   => __( 'Status', 'bomedia-quote-wizard' ),
			'bqw_agile'    => __( 'AgileCRM ID', 'bomedia-quote-wizard' ),
			'date'         => __( 'Date', 'bomedia-quote-wizard' ),
		];
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'bqw_company':
				echo esc_html( get_post_meta( $post_id, '_bqw_company', true ) );
				break;
			case 'bqw_email':
				echo esc_html( get_post_meta( $post_id, '_bqw_email', true ) );
				break;
			case 'bqw_product':
				echo esc_html( get_post_meta( $post_id, '_bqw_product_name', true ) );
				break;
			case 'bqw_status':
				$status = get_post_meta( $post_id, '_bqw_status', true );
				$colors = [ 'sent' => '#46b450', 'failed' => '#dc3232', 'pending_retry' => '#ffb900' ];
				$color  = $colors[ $status ] ?? '#777';
				printf( '<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:%s;">%s</span>', esc_attr( $color ), esc_html( $status ) );
				break;
			case 'bqw_agile':
				echo esc_html( get_post_meta( $post_id, '_bqw_agile_id', true ) );
				break;
		}
	}

	public function status_filter(): void {
		global $typenow;
		if ( self::POST_TYPE !== $typenow ) {
			return;
		}
		$current = isset( $_GET['bqw_status'] ) ? sanitize_text_field( wp_unslash( $_GET['bqw_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<select name="bqw_status">
			<option value=""><?php esc_html_e( 'All statuses', 'bomedia-quote-wizard' ); ?></option>
			<?php foreach ( [ 'sent', 'failed', 'pending_retry' ] as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current, $s ); ?>><?php echo esc_html( $s ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function apply_status_filter( $query ) {
		global $pagenow, $typenow;
		if ( 'edit.php' !== $pagenow || self::POST_TYPE !== $typenow || ! is_admin() ) {
			return $query;
		}
		if ( ! empty( $_GET['bqw_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$qv             = $query->query_vars;
			$qv['meta_key'] = '_bqw_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$qv['meta_value'] = sanitize_text_field( wp_unslash( $_GET['bqw_status'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.Security.NonceVerification.Recommended
			$query->query_vars = $qv;
		}
		return $query;
	}

	public function row_actions( array $actions, $post ): array {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		$status = get_post_meta( $post->ID, '_bqw_status', true );
		if ( in_array( $status, [ 'failed', 'pending_retry' ], true ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bqw_retry_lead&lead=' . $post->ID ),
				'bqw_retry_' . $post->ID
			);
			$actions['bqw_retry'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Retry send', 'bomedia-quote-wizard' ) . '</a>';
		}
		return $actions;
	}

	public function handle_retry(): void {
		$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		if ( ! $lead_id || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden', 'bomedia-quote-wizard' ) );
		}
		check_admin_referer( 'bqw_retry_' . $lead_id );

		$raw  = (string) get_post_meta( $lead_id, '_bqw_data', true );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_die( esc_html__( 'Lead data missing or corrupt.', 'bomedia-quote-wizard' ) );
		}

		Ajax::push_to_agile( $data, $lead_id );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}
}
