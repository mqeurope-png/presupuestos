<?php
/**
 * Singleton orchestrator for the plugin.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain(
			BQW_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( BQW_PLUGIN_FILE ) ) . '/languages'
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ $this, 'render_woo_missing_notice' ] );
			add_action( 'admin_init', [ $this, 'maybe_self_deactivate' ] );
			return;
		}

		Settings::maybe_migrate();

		Lead_CPT::instance()->register();
		Settings::instance()->register();
		Shortcode::instance()->register();
		Ajax::instance()->register();
	}

	public function render_woo_missing_notice(): void {
		$message = esc_html__( 'Bomedia Quote Wizard requires WooCommerce to be installed and active. The plugin has been deactivated.', 'bomedia-quote-wizard' );
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function maybe_self_deactivate(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			return;
		}
		deactivate_plugins( plugin_basename( BQW_PLUGIN_FILE ) );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	public static function activate(): void {
		Lead_CPT::instance()->register();
		flush_rewrite_rules();

		Settings::maybe_migrate();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
