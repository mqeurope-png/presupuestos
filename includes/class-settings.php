<?php
/**
 * Settings page (Settings → Bomedia Quote Wizard) with three independent
 * option_names so each tab persists separately.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPT_AGILE  = 'bqw_agilecrm_settings';
	public const OPT_WIZARD = 'bqw_wizard_settings';
	public const OPT_NOTIF  = 'bqw_notifications_settings';
	public const PAGE_SLUG  = 'bqw-settings';

	private const MIGRATED_FLAG = 'bqw_settings_migrated_v2';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_bqw_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	/* ---------------------------------------------------------------------
	 * Defaults
	 * ------------------------------------------------------------------- */

	public static function default_agile(): array {
		return [
			'agile_domain'       => '',
			'agile_email'        => '',
			'agile_api_key'      => '',
			'agile_default_tags' => 'web-lead',
		];
	}

	public static function default_wizard(): array {
		return [
			'selected_categories'   => [],
			'products_by_category'  => [],
			'enable_application'    => 1,
			'application_options'   => "Textil\nPackaging\nIndustrial\nPromocional\nOtros",
			'enable_materials'      => 1,
			'materials_options'     => "Textil\nPVC\nMadera\nCristal\nMetal\nPapel\nCuero\nOtros",
			'enable_volume'         => 1,
			'volume_options'        => "<100\n100-500\n500-2000\n>2000",
			'wizard_language'       => '',
			'privacy_url'           => '',
			'enable_captcha'        => 1,
			'enable_email_optin'    => 0,
			'email_optin_label'     => 'Sí, me gustaría recibir novedades de producto y ofertas por email.',
		];
	}

	public static function default_notifications(): array {
		return [
			'notify_emails'  => get_option( 'admin_email' ),
			'notify_subject' => 'Nueva solicitud de presupuesto: {producto} - {empresa}',
			'enable_log'     => 1,
		];
	}

	/* ---------------------------------------------------------------------
	 * Getters (with backwards-compat key→option lookup)
	 * ------------------------------------------------------------------- */

	public static function get_agile(): array {
		return wp_parse_args( (array) get_option( self::OPT_AGILE, [] ), self::default_agile() );
	}

	public static function get_wizard(): array {
		return wp_parse_args( (array) get_option( self::OPT_WIZARD, [] ), self::default_wizard() );
	}

	public static function get_notifications(): array {
		return wp_parse_args( (array) get_option( self::OPT_NOTIF, [] ), self::default_notifications() );
	}

	/**
	 * Backwards-compat single-key getter used across the plugin. Routes the
	 * key to the right option group automatically.
	 */
	public static function get( string $key, $default = '' ) {
		$agile = self::get_agile();
		if ( array_key_exists( $key, $agile ) ) {
			return $agile[ $key ];
		}
		$wiz = self::get_wizard();
		if ( array_key_exists( $key, $wiz ) ) {
			return $wiz[ $key ];
		}
		$notif = self::get_notifications();
		if ( array_key_exists( $key, $notif ) ) {
			return $notif[ $key ];
		}
		return $default;
	}

	/* ---------------------------------------------------------------------
	 * Migration from v1 (single bqw_settings) to v2 (three options)
	 * ------------------------------------------------------------------- */

	public static function maybe_migrate(): void {
		if ( get_option( self::MIGRATED_FLAG ) ) {
			return;
		}

		$old = get_option( 'bqw_settings' );
		if ( is_array( $old ) && ! empty( $old ) ) {
			$agile_keys = array_keys( self::default_agile() );
			$wiz_keys   = array_keys( self::default_wizard() );
			$notif_keys = array_keys( self::default_notifications() );

			$agile = array_intersect_key( $old, array_flip( $agile_keys ) );
			$wiz   = array_intersect_key( $old, array_flip( $wiz_keys ) );
			$notif = array_intersect_key( $old, array_flip( $notif_keys ) );

			update_option( self::OPT_AGILE,  array_merge( self::default_agile(), $agile ) );
			update_option( self::OPT_WIZARD, array_merge( self::default_wizard(), $wiz ) );
			update_option( self::OPT_NOTIF,  array_merge( self::default_notifications(), $notif ) );

			delete_option( 'bqw_settings' );
		} else {
			if ( false === get_option( self::OPT_AGILE ) ) {
				add_option( self::OPT_AGILE, self::default_agile() );
			}
			if ( false === get_option( self::OPT_WIZARD ) ) {
				add_option( self::OPT_WIZARD, self::default_wizard() );
			}
			if ( false === get_option( self::OPT_NOTIF ) ) {
				add_option( self::OPT_NOTIF, self::default_notifications() );
			}
		}

		update_option( self::MIGRATED_FLAG, 1 );

		self::maybe_migrate_v3();
	}

	/**
	 * v3: rename wizard_categories → selected_categories.
	 */
	private static function maybe_migrate_v3(): void {
		$flag = 'bqw_settings_migrated_v3';
		if ( get_option( $flag ) ) {
			return;
		}
		$wiz = (array) get_option( self::OPT_WIZARD, [] );
		if ( isset( $wiz['wizard_categories'] ) && empty( $wiz['selected_categories'] ) ) {
			$wiz['selected_categories'] = $wiz['wizard_categories'];
		}
		unset( $wiz['wizard_categories'] );
		update_option( self::OPT_WIZARD, $wiz );
		update_option( $flag, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Settings registration
	 * ------------------------------------------------------------------- */

	public function register_settings(): void {
		register_setting(
			'bqw_agilecrm_group',
			self::OPT_AGILE,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_agile' ],
				'default'           => self::default_agile(),
			]
		);
		register_setting(
			'bqw_wizard_group',
			self::OPT_WIZARD,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_wizard' ],
				'default'           => self::default_wizard(),
			]
		);
		register_setting(
			'bqw_notifications_group',
			self::OPT_NOTIF,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_notifications' ],
				'default'           => self::default_notifications(),
			]
		);
	}

	public function sanitize_agile( $input ): array {
		$current = self::get_agile();
		$out     = $current;

		$out['agile_domain']       = sanitize_text_field( $input['agile_domain'] ?? '' );
		$out['agile_email']        = sanitize_email( $input['agile_email'] ?? '' );
		$out['agile_default_tags'] = sanitize_text_field( $input['agile_default_tags'] ?? '' );

		// Encrypt API key only if a new one was provided (not the masked placeholder).
		if ( isset( $input['agile_api_key'] ) ) {
			$new_key = trim( (string) $input['agile_api_key'] );
			if ( '' !== $new_key && '********' !== $new_key ) {
				$out['agile_api_key'] = self::encrypt( $new_key );
			}
		}

		return $out;
	}

	public function sanitize_wizard( $input ): array {
		$current = self::get_wizard();
		$out     = $current;

		$out['selected_categories'] = array_values( array_map( 'absint', (array) ( $input['selected_categories'] ?? [] ) ) );
		$out['enable_application']  = ! empty( $input['enable_application'] ) ? 1 : 0;
		$out['application_options'] = $this->sanitize_lines( $input['application_options'] ?? '' );
		$out['enable_materials']    = ! empty( $input['enable_materials'] ) ? 1 : 0;
		$out['materials_options']   = $this->sanitize_lines( $input['materials_options'] ?? '' );
		$out['enable_volume']       = ! empty( $input['enable_volume'] ) ? 1 : 0;
		$out['volume_options']      = $this->sanitize_lines( $input['volume_options'] ?? '' );
		$out['wizard_language']     = sanitize_text_field( $input['wizard_language'] ?? '' );
		$out['privacy_url']         = esc_url_raw( $input['privacy_url'] ?? '' );
		$out['enable_captcha']      = ! empty( $input['enable_captcha'] ) ? 1 : 0;
		$out['enable_email_optin']  = ! empty( $input['enable_email_optin'] ) ? 1 : 0;
		$out['email_optin_label']   = sanitize_text_field( $input['email_optin_label'] ?? '' );

		// Merge products_by_category, preserving entries for categories not posted
		// (so unchecking a category does not erase its product filter config).
		$existing_pbc  = (array) ( $current['products_by_category'] ?? [] );
		$submitted_pbc = (array) ( $input['products_by_category'] ?? [] );
		foreach ( $submitted_pbc as $term_id => $cfg ) {
			$term_id = absint( $term_id );
			if ( ! $term_id ) {
				continue;
			}
			$mode = isset( $cfg['mode'] ) && 'all' === $cfg['mode'] ? 'all' : 'manual';
			$ids  = array_values( array_unique( array_map( 'absint', (array) ( $cfg['ids'] ?? [] ) ) ) );
			$existing_pbc[ $term_id ] = [
				'mode' => $mode,
				'ids'  => $ids,
			];
		}
		$out['products_by_category'] = $existing_pbc;

		return $out;
	}

	public function sanitize_notifications( $input ): array {
		$current = self::get_notifications();
		$out     = $current;

		$out['notify_emails']  = sanitize_text_field( $input['notify_emails'] ?? '' );
		$out['notify_subject'] = sanitize_text_field( $input['notify_subject'] ?? '' );
		$out['enable_log']     = ! empty( $input['enable_log'] ) ? 1 : 0;

		return $out;
	}

	private function sanitize_lines( string $raw ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = array_filter( array_map( 'sanitize_text_field', $lines ?: [] ), 'strlen' );
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------
	 * Admin assets + page render
	 * ------------------------------------------------------------------- */

	public function enqueue_admin( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$inline_css = '
			.bqw-dual{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:stretch;}
			@media(max-width:900px){.bqw-dual{grid-template-columns:1fr;}}
			.bqw-dual-col{border:1px solid #ccd0d4;background:#fff;border-radius:6px;display:flex;flex-direction:column;min-height:420px;max-height:600px;}
			.bqw-dual-header{padding:10px 12px;border-bottom:1px solid #e5e7eb;background:#f9fafb;border-radius:6px 6px 0 0;display:flex;flex-wrap:wrap;align-items:center;gap:8px;}
			.bqw-dual-header h3{margin:0;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#475569;flex:1 1 100%;}
			.bqw-dual-search{flex:1 1 60%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;}
			.bqw-dual-empty-toggle{font-size:11px;color:#666;display:inline-flex;align-items:center;gap:4px;}
			.bqw-dual-empty-toggle input{margin:0;}
			.bqw-dual-count{font-size:11px;color:#666;margin-left:auto;}

			.bqw-dual-tree{flex:1;overflow:auto;padding:6px 0;}
			.bqw-tree-root,.bqw-tree-children{list-style:none;margin:0;padding:0;}
			.bqw-tree-children{margin-left:18px;}
			.bqw-tree-node{padding:0;}
			.bqw-tree-node.is-hidden{display:none;}
			.bqw-tree-node[data-count="0"]{display:none;}
			.bqw-show-empty:checked ~ .bqw-dual-tree .bqw-tree-node[data-count="0"],
			.bqw-dual-tree.bqw-show-empty .bqw-tree-node[data-count="0"]{display:block;}
			.bqw-tree-row{display:flex;align-items:center;gap:6px;padding:3px 12px;font-size:13px;}
			.bqw-tree-row:hover{background:#f1f5f9;}
			.bqw-tree-toggle{background:none;border:0;cursor:pointer;color:#94a3b8;font-size:11px;width:18px;line-height:1;padding:0;}
			.bqw-tree-toggle.is-leaf{visibility:hidden;}
			.bqw-tree-toggle:hover{color:#0066cc;}
			.bqw-tree-name{flex:1;color:#1f2937;}
			.bqw-tree-count{color:#94a3b8;font-size:11px;}
			.bqw-tree-add{background:#fff;border:1px solid #d1d5db;color:#0066cc;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;font-weight:600;}
			.bqw-tree-add:hover:not(:disabled){background:#0066cc;color:#fff;border-color:#0066cc;}
			.bqw-tree-add:disabled{color:#16a34a;border-color:#e5e7eb;cursor:default;background:#f0fdf4;}
			.bqw-tree-node.is-added > .bqw-tree-row .bqw-tree-name{color:#94a3b8;}

			.bqw-selected-list{flex:1;overflow:auto;list-style:none;margin:0;padding:8px;display:flex;flex-direction:column;gap:6px;}
			.bqw-empty-hint{margin:0;padding:24px 16px;text-align:center;color:#94a3b8;font-size:13px;font-style:italic;}
			.bqw-empty-hint.is-hidden{display:none;}
			.bqw-selected-row{border:1px solid #e5e7eb;border-radius:6px;background:#fff;}
			.bqw-selected-row.bqw-dragging{opacity:0.4;border-color:#0066cc;background:#eef5ff;}
			.bqw-selected-row-head{display:flex;align-items:center;gap:6px;padding:8px 10px;}
			.bqw-drag-handle{cursor:grab;color:#cbd5e1;user-select:none;font-size:14px;line-height:1;padding:0 2px;}
			.bqw-drag-handle:hover{color:#0066cc;}
			.bqw-drag-handle:active{cursor:grabbing;}
			.bqw-row-toggle{background:none;border:0;cursor:pointer;color:#64748b;font-size:11px;width:18px;padding:0;}
			.bqw-row-toggle.is-open{transform:rotate(90deg);}
			.bqw-row-name{flex:1;font-weight:600;font-size:13px;color:#1f2937;}
			.bqw-row-remove{background:transparent;border:1px solid transparent;color:#94a3b8;font-size:18px;width:24px;height:24px;border-radius:50%;cursor:pointer;line-height:1;padding:0;}
			.bqw-row-remove:hover{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
			.bqw-row-body{border-top:1px solid #e5e7eb;background:#f9fafb;padding:10px 14px;border-radius:0 0 6px 6px;}
			.bqw-mode-toggle{display:block;font-weight:600;margin-bottom:6px;font-size:13px;}
			.bqw-cat-prod-list{margin:0;padding:0;list-style:none;max-height:220px;overflow:auto;}
			.bqw-cat-prod-list li{padding:3px 0;font-size:13px;display:flex;align-items:center;gap:6px;}
			.bqw-cat-prod-list li.bqw-dragging{opacity:0.4;background:#eef5ff;}
			.bqw-cat-prod-list input:disabled + *{color:#888;}
			.bqw-helper-mini{margin:8px 0 0;font-size:11px;color:#888;font-style:italic;}
		';
		wp_register_style( 'bqw-admin-inline', false, [], BQW_VERSION );
		wp_enqueue_style( 'bqw-admin-inline' );
		wp_add_inline_style( 'bqw-admin-inline', $inline_css );

		wp_enqueue_script( 'bqw-admin', BQW_PLUGIN_URL . 'assets/js/admin.js', [], BQW_VERSION, true );
		wp_localize_script(
			'bqw-admin',
			'BQW_Admin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bqw_test_connection' ),
				'i18n'    => [
					'testing'    => __( 'Testing…', 'bomedia-quote-wizard' ),
					'ok'         => __( 'Connection OK', 'bomedia-quote-wizard' ),
					'fail'       => __( 'Connection failed: ', 'bomedia-quote-wizard' ),
					'Add'        => __( 'Add', 'bomedia-quote-wizard' ),
					'Added'      => __( 'Added', 'bomedia-quote-wizard' ),
					'selected'   => __( 'selected', 'bomedia-quote-wizard' ),
				],
			]
		);
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Bomedia Quote Wizard', 'bomedia-quote-wizard' ),
			__( 'Bomedia Quote Wizard', 'bomedia-quote-wizard' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agilecrm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_tab, [ 'agilecrm', 'wizard', 'notifications' ], true ) ) {
			$active_tab = 'agilecrm';
		}
		$tabs = [
			'agilecrm'      => __( 'AgileCRM', 'bomedia-quote-wizard' ),
			'wizard'        => __( 'Wizard', 'bomedia-quote-wizard' ),
			'notifications' => __( 'Notifications', 'bomedia-quote-wizard' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bomedia Quote Wizard', 'bomedia-quote-wizard' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $slug ], admin_url( 'options-general.php' ) ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $active_tab ) {
				case 'wizard':
					$this->render_form_wizard();
					break;
				case 'notifications':
					$this->render_form_notifications();
					break;
				default:
					$this->render_form_agile();
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Per-tab forms (each posts to options.php with its own option_group).
	 * The hidden _wp_http_referer added by settings_fields() preserves the
	 * active ?tab= value across the redirect.
	 * ------------------------------------------------------------------- */

	private function render_form_agile(): void {
		$s       = self::get_agile();
		$has_key = ! empty( $s['agile_api_key'] );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bqw_agilecrm_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bqw_agile_domain"><?php esc_html_e( 'Domain', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="text" id="bqw_agile_domain" name="<?php echo esc_attr( self::OPT_AGILE ); ?>[agile_domain]"
							value="<?php echo esc_attr( $s['agile_domain'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'e.g. "bomedia" for bomedia.agilecrm.com', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_agile_email"><?php esc_html_e( 'Account email', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="email" id="bqw_agile_email" name="<?php echo esc_attr( self::OPT_AGILE ); ?>[agile_email]"
							value="<?php echo esc_attr( $s['agile_email'] ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_agile_api_key"><?php esc_html_e( 'REST API Key', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="password" id="bqw_agile_api_key" name="<?php echo esc_attr( self::OPT_AGILE ); ?>[agile_api_key]"
							value="<?php echo $has_key ? '********' : ''; ?>" class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Stored encrypted with AUTH_KEY. Leave the masked value to keep current.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_agile_default_tags"><?php esc_html_e( 'Default tags', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="text" id="bqw_agile_default_tags" name="<?php echo esc_attr( self::OPT_AGILE ); ?>[agile_default_tags]"
							value="<?php echo esc_attr( $s['agile_default_tags'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Comma separated, e.g. web-lead, boprint', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test connection', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<button type="button" class="button" id="bqw-test-connection"><?php esc_html_e( 'Test connection', 'bomedia-quote-wizard' ); ?></button>
						<span id="bqw-test-result" style="margin-left:10px;"></span>
						<p class="description"><?php esc_html_e( 'Save your changes before testing.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_form_wizard(): void {
		$s         = self::get_wizard();
		$selected  = array_map( 'intval', (array) ( $s['selected_categories'] ?? [] ) );
		$languages = self::supported_locales();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bqw_wizard_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Product categories', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<?php $this->render_dual_picker( $selected, (array) ( $s['products_by_category'] ?? [] ) ); ?>
						<p class="description"><?php esc_html_e( 'Pick categories from the left, drag to reorder on the right. Expand a row to refine which products are exposed and in what order.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Application step', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[enable_application]" value="1" <?php checked( ! empty( $s['enable_application'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
						<textarea name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[application_options]" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( $s['application_options'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One option per line.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Materials step', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[enable_materials]" value="1" <?php checked( ! empty( $s['enable_materials'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
						<textarea name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[materials_options]" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( $s['materials_options'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Monthly volume step', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[enable_volume]" value="1" <?php checked( ! empty( $s['enable_volume'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
						<textarea name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[volume_options]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $s['volume_options'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_wizard_language"><?php esc_html_e( 'Wizard language', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<select id="bqw_wizard_language" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[wizard_language]">
							<?php foreach ( $languages as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['wizard_language'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_privacy_url"><?php esc_html_e( 'Privacy policy URL', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="url" id="bqw_privacy_url" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[privacy_url]" class="regular-text"
							value="<?php echo esc_attr( $s['privacy_url'] ); ?>" />
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Security & marketing', 'bomedia-quote-wizard' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Bot verification', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[enable_captcha]" value="1" <?php checked( ! empty( $s['enable_captcha'] ) ); ?> />
							<?php esc_html_e( 'Show a built-in math challenge ("3 + 5 = ?") on the confirmation step.', 'bomedia-quote-wizard' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'No third-party keys needed. Combined with the existing honeypot and minimum-fill-time guards, blocks the vast majority of bots.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Marketing opt-in', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[enable_email_optin]" value="1" <?php checked( ! empty( $s['enable_email_optin'] ) ); ?> />
							<?php esc_html_e( 'Show a marketing opt-in checkbox in the contact step.', 'bomedia-quote-wizard' ); ?>
						</label>
						<p style="margin-top:8px;">
							<label for="bqw_email_optin_label"><?php esc_html_e( 'Checkbox label', 'bomedia-quote-wizard' ); ?></label><br/>
							<input type="text" id="bqw_email_optin_label" class="large-text" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[email_optin_label]"
								value="<?php echo esc_attr( $s['email_optin_label'] ); ?>" />
						</p>
						<p class="description"><?php esc_html_e( 'When checked, the lead is tagged "marketing-optin" in AgileCRM and the custom field Marketing_Optin is set to "yes".', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_form_notifications(): void {
		$s = self::get_notifications();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bqw_notifications_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bqw_notify_emails"><?php esc_html_e( 'Recipient emails', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="text" id="bqw_notify_emails" name="<?php echo esc_attr( self::OPT_NOTIF ); ?>[notify_emails]" class="regular-text"
							value="<?php echo esc_attr( $s['notify_emails'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma separated.', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bqw_notify_subject"><?php esc_html_e( 'Email subject', 'bomedia-quote-wizard' ); ?></label></th>
					<td>
						<input type="text" id="bqw_notify_subject" name="<?php echo esc_attr( self::OPT_NOTIF ); ?>[notify_subject]" class="large-text"
							value="<?php echo esc_attr( $s['notify_subject'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Placeholders: {nombre}, {empresa}, {producto}', 'bomedia-quote-wizard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'File logging', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_NOTIF ); ?>[enable_log]" value="1" <?php checked( ! empty( $s['enable_log'] ) ); ?> /> <?php esc_html_e( 'Write log file in uploads/bqw-logs/', 'bomedia-quote-wizard' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Dual-list category picker (Available ↔ Selected)
	 * ------------------------------------------------------------------- */

	private function render_dual_picker( array $selected, array $pbc ): void {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			echo '<em>' . esc_html__( 'WooCommerce is not active. Activate it to pick categories.', 'bomedia-quote-wizard' ) . '</em>';
			return;
		}

		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<em>' . esc_html__( 'No product categories found.', 'bomedia-quote-wizard' ) . '</em>';
			return;
		}

		// Drop "uncategorized".
		$terms = array_values(
			array_filter(
				$terms,
				static function ( $t ) {
					return 'uncategorized' !== $t->slug;
				}
			)
		);

		// Index by id and by parent.
		$by_id = [];
		foreach ( $terms as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
		}
		$by_parent = [];
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = (int) $t->term_id;
		}

		$selected_set = array_flip( array_map( 'intval', $selected ) );
		?>
		<div class="bqw-dual" id="bqw-dual">
			<div class="bqw-dual-col bqw-dual-available">
				<header class="bqw-dual-header">
					<h3><?php esc_html_e( 'Available categories', 'bomedia-quote-wizard' ); ?></h3>
					<input type="search"
						class="bqw-dual-search"
						placeholder="<?php esc_attr_e( 'Search…', 'bomedia-quote-wizard' ); ?>"
						aria-label="<?php esc_attr_e( 'Search categories', 'bomedia-quote-wizard' ); ?>" />
					<label class="bqw-dual-empty-toggle">
						<input type="checkbox" class="bqw-show-empty" />
						<?php esc_html_e( 'Show empty', 'bomedia-quote-wizard' ); ?>
					</label>
				</header>
				<div class="bqw-dual-tree">
					<ul class="bqw-tree-root">
						<?php $this->render_tree_nodes( $by_parent, $by_id, 0, $selected_set ); ?>
					</ul>
				</div>
			</div>

			<div class="bqw-dual-col bqw-dual-selected">
				<header class="bqw-dual-header">
					<h3><?php esc_html_e( 'Selected (drag to reorder)', 'bomedia-quote-wizard' ); ?></h3>
					<span class="bqw-dual-count" id="bqw-dual-count"></span>
				</header>
				<ul class="bqw-selected-list" id="bqw-selected-list" data-bqw-sortable="categories">
					<?php foreach ( $selected as $tid ) :
						$tid = (int) $tid;
						if ( ! isset( $by_id[ $tid ] ) ) {
							continue;
						}
						$this->render_selected_row( $by_id[ $tid ], $by_id, $pbc[ $tid ] ?? [] );
					endforeach; ?>
				</ul>
				<p class="bqw-empty-hint" id="bqw-empty-hint"><?php esc_html_e( 'Add categories from the list on the left.', 'bomedia-quote-wizard' ); ?></p>
			</div>
		</div>

		<div id="bqw-cat-templates" hidden>
			<?php
			foreach ( $by_id as $tid => $term ) :
				if ( isset( $selected_set[ $tid ] ) ) {
					continue; // already rendered live.
				}
				?>
				<template id="bqw-cat-tpl-<?php echo esc_attr( (string) $tid ); ?>"><?php
				$this->render_selected_row( $term, $by_id, $pbc[ $tid ] ?? [] );
				?></template>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_tree_nodes( array $by_parent, array $by_id, int $parent, array $selected_set ): void {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}
		foreach ( $by_parent[ $parent ] as $tid ) {
			$term      = $by_id[ $tid ];
			$has_kids  = ! empty( $by_parent[ $tid ] );
			$is_added  = isset( $selected_set[ $tid ] );
			$count     = (int) $term->count;
			$node_cls  = 'bqw-tree-node';
			if ( $is_added ) {
				$node_cls .= ' is-added';
			}
			?>
			<li class="<?php echo esc_attr( $node_cls ); ?>"
				data-term-id="<?php echo esc_attr( (string) $tid ); ?>"
				data-name="<?php echo esc_attr( strtolower( $term->name ) ); ?>"
				data-count="<?php echo esc_attr( (string) $count ); ?>">
				<div class="bqw-tree-row">
					<button type="button" class="bqw-tree-toggle <?php echo $has_kids ? '' : 'is-leaf'; ?>" aria-label="<?php esc_attr_e( 'Toggle children', 'bomedia-quote-wizard' ); ?>">
						<?php echo $has_kids ? '▸' : '·'; ?>
					</button>
					<span class="bqw-tree-name"><?php echo esc_html( $term->name ); ?></span>
					<span class="bqw-tree-count">(<?php echo esc_html( (string) $count ); ?>)</span>
					<button type="button"
						class="bqw-tree-add"
						data-term-id="<?php echo esc_attr( (string) $tid ); ?>"
						<?php disabled( $is_added ); ?>>
						<?php echo $is_added ? '✓ ' . esc_html__( 'Added', 'bomedia-quote-wizard' ) : '+ ' . esc_html__( 'Add', 'bomedia-quote-wizard' ); ?>
					</button>
				</div>
				<?php if ( $has_kids ) : ?>
					<ul class="bqw-tree-children" hidden>
						<?php $this->render_tree_nodes( $by_parent, $by_id, $tid, $selected_set ); ?>
					</ul>
				<?php endif; ?>
			</li>
			<?php
		}
	}

	private function render_selected_row( $term, array $by_id, array $cfg ): void {
		$term_id    = (int) $term->term_id;
		$breadcrumb = $this->breadcrumb_label( $term, $by_id );
		?>
		<li class="bqw-selected-row" data-term-id="<?php echo esc_attr( (string) $term_id ); ?>" draggable="true">
			<input type="hidden" name="<?php echo esc_attr( self::OPT_WIZARD ); ?>[selected_categories][]" value="<?php echo esc_attr( (string) $term_id ); ?>" />
			<div class="bqw-selected-row-head">
				<span class="bqw-drag-handle" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'bomedia-quote-wizard' ); ?>">⠿</span>
				<button type="button" class="bqw-row-toggle" aria-label="<?php esc_attr_e( 'Expand', 'bomedia-quote-wizard' ); ?>">▸</button>
				<span class="bqw-row-name"><?php echo esc_html( $breadcrumb ); ?></span>
				<button type="button" class="bqw-row-remove" aria-label="<?php esc_attr_e( 'Remove', 'bomedia-quote-wizard' ); ?>">×</button>
			</div>
			<div class="bqw-row-body" hidden>
				<?php $this->render_row_products( $term_id, $cfg ); ?>
			</div>
		</li>
		<?php
	}

	private function render_row_products( int $term_id, array $cfg ): void {
		$products = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'no_found_rows'  => true,
				'tax_query'      => [
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $term_id,
						'include_children' => false,
					],
				],
			]
		);
		$total = count( $products );

		$mode       = isset( $cfg['mode'] ) && 'manual' === $cfg['mode'] ? 'manual' : 'all';
		$manual_ids = array_map( 'absint', (array) ( $cfg['ids'] ?? [] ) );

		// In manual mode, sort by saved order; in all mode, keep menu_order/title order.
		if ( 'manual' === $mode && ! empty( $manual_ids ) ) {
			$by_pid = [];
			foreach ( $products as $p ) {
				$by_pid[ (int) $p->ID ] = $p;
			}
			$sorted = [];
			foreach ( $manual_ids as $mid ) {
				if ( isset( $by_pid[ $mid ] ) ) {
					$sorted[] = $by_pid[ $mid ];
					unset( $by_pid[ $mid ] );
				}
			}
			foreach ( $by_pid as $p ) {
				$sorted[] = $p;
			}
			$products = $sorted;
		}

		$opt = self::OPT_WIZARD;
		?>
		<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[products_by_category][<?php echo esc_attr( (string) $term_id ); ?>][mode]" value="manual" />
		<label class="bqw-mode-toggle">
			<input type="checkbox" class="bqw-mode-cb"
				name="<?php echo esc_attr( $opt ); ?>[products_by_category][<?php echo esc_attr( (string) $term_id ); ?>][mode]"
				value="all" <?php checked( 'all' === $mode ); ?> />
			<?php esc_html_e( 'Show all published products', 'bomedia-quote-wizard' ); ?>
		</label>

		<?php if ( 0 === $total ) : ?>
			<p class="bqw-helper-mini"><?php esc_html_e( 'No published products in this category.', 'bomedia-quote-wizard' ); ?></p>
		<?php else : ?>
			<ul class="bqw-cat-prod-list" data-bqw-sortable="products">
				<?php foreach ( $products as $product ) :
					$pid     = (int) $product->ID;
					$checked = 'all' === $mode || in_array( $pid, $manual_ids, true );
					?>
					<li draggable="true">
						<span class="bqw-drag-handle bqw-drag-handle-prod" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'bomedia-quote-wizard' ); ?>">⠿</span>
						<label>
							<input type="checkbox"
								class="bqw-prod-cb"
								name="<?php echo esc_attr( $opt ); ?>[products_by_category][<?php echo esc_attr( (string) $term_id ); ?>][ids][]"
								value="<?php echo esc_attr( (string) $pid ); ?>"
								<?php checked( $checked ); ?>
								<?php disabled( 'all' === $mode ); ?> />
							<?php echo esc_html( $product->post_title ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="bqw-helper-mini"><?php esc_html_e( 'Drag products to reorder. In "Show all published" mode, dragging will switch to manual to preserve the new order.', 'bomedia-quote-wizard' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function breadcrumb_label( $term, array $lookup ): string {
		$names  = [ $term->name ];
		$parent = (int) $term->parent;
		$guard  = 0;
		while ( $parent && $guard < 8 ) {
			if ( ! isset( $lookup[ $parent ] ) ) {
				break;
			}
			$names[] = $lookup[ $parent ]->name;
			$parent  = (int) $lookup[ $parent ]->parent;
			$guard++;
		}
		return implode( ' › ', array_reverse( $names ) );
	}

	/* ---------------------------------------------------------------------
	 * Test connection AJAX
	 * ------------------------------------------------------------------- */

	public function ajax_test_connection(): void {
		check_ajax_referer( 'bqw_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden', 'bomedia-quote-wizard' ) ], 403 );
		}

		$client = new AgileCRM_Client();
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => 'OK' ] );
	}

	/* ---------------------------------------------------------------------
	 * Encryption (unchanged)
	 * ------------------------------------------------------------------- */

	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$key = self::auth_key();
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return '';
		}
		return 'enc::' . base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, 'enc::' ) ) {
			return $value;
		}
		$raw = base64_decode( substr( $value, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$enc = substr( $raw, 16 );
		$dec = openssl_decrypt( $enc, 'aes-256-cbc', self::auth_key(), OPENSSL_RAW_DATA, $iv );
		return false === $dec ? '' : $dec;
	}

	/**
	 * Locales supported by the wizard. Keys are WP locale codes, values are
	 * the human-readable label shown in the admin dropdown.
	 */
	public static function supported_locales(): array {
		return [
			''      => __( 'Auto (site language)', 'bomedia-quote-wizard' ),
			'es_ES' => 'Español',
			'en_US' => 'English',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
			'pt_PT' => 'Português',
		];
	}

	private static function auth_key(): string {
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bqw-fallback-' . get_site_url();
		return substr( hash( 'sha256', $key, true ), 0, 32 );
	}
}
