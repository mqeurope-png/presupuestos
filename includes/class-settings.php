<?php
/**
 * Settings page (Settings → Bomedia Quote Wizard) with three tabs.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_KEY = 'bqw_settings';
	public const PAGE_SLUG  = 'bqw-settings';

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

	public static function default_settings(): array {
		return [
			'agile_domain'      => '',
			'agile_email'       => '',
			'agile_api_key'     => '',
			'agile_default_tags'=> 'web-lead',
			'wizard_categories' => [],
			'enable_application'=> 1,
			'application_options' => "Textil\nPackaging\nIndustrial\nPromocional\nOtros",
			'enable_materials'  => 1,
			'materials_options' => "Textil\nPVC\nMadera\nCristal\nMetal\nPapel\nCuero\nOtros",
			'enable_volume'     => 1,
			'volume_options'    => "<100\n100-500\n500-2000\n>2000",
			'wizard_language'   => '',
			'privacy_url'       => '',
			'notify_emails'     => get_option( 'admin_email' ),
			'notify_subject'    => 'Nueva solicitud de presupuesto: {producto} - {empresa}',
			'enable_log'        => 1,
		];
	}

	public static function get( string $key, $default = '' ) {
		$settings = get_option( self::OPTION_KEY, self::default_settings() );
		return $settings[ $key ] ?? $default;
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

	public function register_settings(): void {
		register_setting(
			'bqw_settings_group',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::default_settings(),
			]
		);
	}

	public function sanitize( $input ): array {
		$current = get_option( self::OPTION_KEY, self::default_settings() );
		$out     = $current;

		$out['agile_domain']        = sanitize_text_field( $input['agile_domain'] ?? '' );
		$out['agile_email']         = sanitize_email( $input['agile_email'] ?? '' );
		$out['agile_default_tags']  = sanitize_text_field( $input['agile_default_tags'] ?? '' );
		$out['wizard_categories']   = array_map( 'absint', (array) ( $input['wizard_categories'] ?? [] ) );
		$out['enable_application']  = ! empty( $input['enable_application'] ) ? 1 : 0;
		$out['application_options'] = $this->sanitize_lines( $input['application_options'] ?? '' );
		$out['enable_materials']    = ! empty( $input['enable_materials'] ) ? 1 : 0;
		$out['materials_options']   = $this->sanitize_lines( $input['materials_options'] ?? '' );
		$out['enable_volume']       = ! empty( $input['enable_volume'] ) ? 1 : 0;
		$out['volume_options']      = $this->sanitize_lines( $input['volume_options'] ?? '' );
		$out['wizard_language']     = sanitize_text_field( $input['wizard_language'] ?? '' );
		$out['privacy_url']         = esc_url_raw( $input['privacy_url'] ?? '' );
		$out['notify_emails']       = sanitize_text_field( $input['notify_emails'] ?? '' );
		$out['notify_subject']      = sanitize_text_field( $input['notify_subject'] ?? '' );
		$out['enable_log']          = ! empty( $input['enable_log'] ) ? 1 : 0;

		// Encrypt API key only if a new one was provided (not the masked placeholder).
		if ( isset( $input['agile_api_key'] ) ) {
			$new_key = trim( (string) $input['agile_api_key'] );
			if ( $new_key !== '' && '********' !== $new_key ) {
				$out['agile_api_key'] = self::encrypt( $new_key );
			}
		}

		return $out;
	}

	private function sanitize_lines( string $raw ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$lines = array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' );
		return implode( "\n", $lines );
	}

	public function enqueue_admin( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'bqw-admin', BQW_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], BQW_VERSION, true );
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agilecrm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings   = get_option( self::OPTION_KEY, self::default_settings() );
		$tabs       = [
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
			<form method="post" action="options.php">
				<?php settings_fields( 'bqw_settings_group' ); ?>
				<?php
				switch ( $active_tab ) {
					case 'wizard':
						$this->render_tab_wizard( $settings );
						break;
					case 'notifications':
						$this->render_tab_notifications( $settings );
						break;
					default:
						$this->render_tab_agile( $settings );
				}
				?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function render_tab_agile( array $settings ): void {
		$has_key = ! empty( $settings['agile_api_key'] );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bqw_agile_domain"><?php esc_html_e( 'Domain', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="text" id="bqw_agile_domain" name="bqw_settings[agile_domain]"
						value="<?php echo esc_attr( $settings['agile_domain'] ?? '' ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g. "bomedia" for bomedia.agilecrm.com', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_agile_email"><?php esc_html_e( 'Account email', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="email" id="bqw_agile_email" name="bqw_settings[agile_email]"
						value="<?php echo esc_attr( $settings['agile_email'] ?? '' ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_agile_api_key"><?php esc_html_e( 'REST API Key', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="password" id="bqw_agile_api_key" name="bqw_settings[agile_api_key]"
						value="<?php echo $has_key ? '********' : ''; ?>" class="regular-text" autocomplete="new-password" />
					<p class="description"><?php esc_html_e( 'Stored encrypted with AUTH_KEY. Leave the masked value to keep current.', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_agile_default_tags"><?php esc_html_e( 'Default tags', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="text" id="bqw_agile_default_tags" name="bqw_settings[agile_default_tags]"
						value="<?php echo esc_attr( $settings['agile_default_tags'] ?? '' ); ?>" class="regular-text" />
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
		<?php
	}

	private function render_tab_wizard( array $settings ): void {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);
		$selected_cats = (array) ( $settings['wizard_categories'] ?? [] );
		$languages     = [ '' => __( 'Auto (site language)', 'bomedia-quote-wizard' ), 'es' => 'Español', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'pt' => 'Português' ];
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bqw_wizard_categories"><?php esc_html_e( 'Product categories', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<select multiple id="bqw_wizard_categories" name="bqw_settings[wizard_categories][]" size="6" style="min-width:280px;">
						<?php
						if ( ! is_wp_error( $categories ) ) :
							foreach ( $categories as $cat ) :
								?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, array_map( 'intval', $selected_cats ), true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?>
								</option>
							<?php endforeach; endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Hold Cmd/Ctrl to select multiple.', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Application step', 'bomedia-quote-wizard' ); ?></th>
				<td>
					<label><input type="checkbox" name="bqw_settings[enable_application]" value="1" <?php checked( ! empty( $settings['enable_application'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
					<textarea name="bqw_settings[application_options]" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( $settings['application_options'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One option per line.', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Materials step', 'bomedia-quote-wizard' ); ?></th>
				<td>
					<label><input type="checkbox" name="bqw_settings[enable_materials]" value="1" <?php checked( ! empty( $settings['enable_materials'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
					<textarea name="bqw_settings[materials_options]" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( $settings['materials_options'] ?? '' ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Monthly volume step', 'bomedia-quote-wizard' ); ?></th>
				<td>
					<label><input type="checkbox" name="bqw_settings[enable_volume]" value="1" <?php checked( ! empty( $settings['enable_volume'] ) ); ?> /> <?php esc_html_e( 'Enable', 'bomedia-quote-wizard' ); ?></label><br/>
					<textarea name="bqw_settings[volume_options]" rows="4" cols="40" class="large-text code"><?php echo esc_textarea( $settings['volume_options'] ?? '' ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_wizard_language"><?php esc_html_e( 'Wizard language', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<select id="bqw_wizard_language" name="bqw_settings[wizard_language]">
						<?php foreach ( $languages as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['wizard_language'] ?? '', $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_privacy_url"><?php esc_html_e( 'Privacy policy URL', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="url" id="bqw_privacy_url" name="bqw_settings[privacy_url]" class="regular-text"
						value="<?php echo esc_attr( $settings['privacy_url'] ?? '' ); ?>" />
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_tab_notifications( array $settings ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bqw_notify_emails"><?php esc_html_e( 'Recipient emails', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="text" id="bqw_notify_emails" name="bqw_settings[notify_emails]" class="regular-text"
						value="<?php echo esc_attr( $settings['notify_emails'] ?? '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma separated.', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bqw_notify_subject"><?php esc_html_e( 'Email subject', 'bomedia-quote-wizard' ); ?></label></th>
				<td>
					<input type="text" id="bqw_notify_subject" name="bqw_settings[notify_subject]" class="large-text"
						value="<?php echo esc_attr( $settings['notify_subject'] ?? '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Placeholders: {nombre}, {empresa}, {producto}', 'bomedia-quote-wizard' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'File logging', 'bomedia-quote-wizard' ); ?></th>
				<td>
					<label><input type="checkbox" name="bqw_settings[enable_log]" value="1" <?php checked( ! empty( $settings['enable_log'] ) ); ?> /> <?php esc_html_e( 'Write log file in uploads/bqw-logs/', 'bomedia-quote-wizard' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

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
			return $value; // legacy plain
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
