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
			'wizard_categories'   => [],
			'enable_application'  => 1,
			'application_options' => "Textil\nPackaging\nIndustrial\nPromocional\nOtros",
			'enable_materials'    => 1,
			'materials_options'   => "Textil\nPVC\nMadera\nCristal\nMetal\nPapel\nCuero\nOtros",
			'enable_volume'       => 1,
			'volume_options'      => "<100\n100-500\n500-2000\n>2000",
			'wizard_language'     => '',
			'privacy_url'         => '',
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

		$out['wizard_categories']   = array_values( array_map( 'absint', (array) ( $input['wizard_categories'] ?? [] ) ) );
		$out['enable_application']  = ! empty( $input['enable_application'] ) ? 1 : 0;
		$out['application_options'] = $this->sanitize_lines( $input['application_options'] ?? '' );
		$out['enable_materials']    = ! empty( $input['enable_materials'] ) ? 1 : 0;
		$out['materials_options']   = $this->sanitize_lines( $input['materials_options'] ?? '' );
		$out['enable_volume']       = ! empty( $input['enable_volume'] ) ? 1 : 0;
		$out['volume_options']      = $this->sanitize_lines( $input['volume_options'] ?? '' );
		$out['wizard_language']     = sanitize_text_field( $input['wizard_language'] ?? '' );
		$out['privacy_url']         = esc_url_raw( $input['privacy_url'] ?? '' );

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
		wp_enqueue_script( 'bqw-admin', BQW_PLUGIN_URL . 'assets/js/admin.js', [], BQW_VERSION, true );
		wp_localize_script(
			'bqw-admin',
			'BQW_Admin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bqw_test_connection' ),
				'i18n'    => [
					'testing' => __( 'Testing…', 'bomedia-quote-wizard' ),
					'ok'      => __( 'Connection OK', 'bomedia-quote-wizard' ),
					'fail'    => __( 'Connection failed: ', 'bomedia-quote-wizard' ),
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
		$selected  = array_map( 'intval', (array) ( $s['wizard_categories'] ?? [] ) );
		$languages = [
			''   => __( 'Auto (site language)', 'bomedia-quote-wizard' ),
			'es' => 'Español',
			'en' => 'English',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'pt' => 'Português',
		];
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bqw_wizard_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Product categories', 'bomedia-quote-wizard' ); ?></th>
					<td>
						<?php $this->render_category_checkboxes( $selected ); ?>
						<p class="description"><?php esc_html_e( 'Pick the WooCommerce product categories to expose in the wizard. Subcategories are indented.', 'bomedia-quote-wizard' ); ?></p>
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
	 * Hierarchical product_cat checkbox renderer
	 * ------------------------------------------------------------------- */

	private function render_category_checkboxes( array $selected ): void {
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

		// Filter out the default "uncategorized" term.
		$terms = array_values(
			array_filter(
				$terms,
				static function ( $t ) {
					return 'uncategorized' !== $t->slug;
				}
			)
		);

		echo '<div class="bqw-cat-tree" style="max-height:280px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:8px 12px;border-radius:4px;">';
		$this->walk_category_tree( $terms, 0, 0, $selected );
		echo '</div>';
	}

	private function walk_category_tree( array $terms, int $parent, int $depth, array $selected ): void {
		foreach ( $terms as $term ) {
			if ( (int) $term->parent !== $parent ) {
				continue;
			}
			$indent = str_repeat( '&nbsp;&nbsp;&nbsp;&nbsp;', $depth );
			$id     = 'bqw_cat_' . (int) $term->term_id;
			$checked = in_array( (int) $term->term_id, $selected, true );
			printf(
				'<div class="bqw-cat-row" style="padding:2px 0;">%s<label for="%s"><input type="checkbox" id="%s" name="%s[wizard_categories][]" value="%d" %s /> %s <span style="color:#777;">(%d)</span></label></div>',
				$indent, // already escaped (nbsp).
				esc_attr( $id ),
				esc_attr( $id ),
				esc_attr( self::OPT_WIZARD ),
				(int) $term->term_id,
				$checked ? 'checked="checked"' : '',
				esc_html( $term->name ),
				(int) $term->count
			);
			$this->walk_category_tree( $terms, (int) $term->term_id, $depth + 1, $selected );
		}
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

	private static function auth_key(): string {
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bqw-fallback-' . get_site_url();
		return substr( hash( 'sha256', $key, true ), 0, 32 );
	}
}
