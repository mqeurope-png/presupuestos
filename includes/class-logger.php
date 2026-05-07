<?php
/**
 * Simple file logger.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public static function log( string $level, string $message, array $context = [] ): void {
		$settings = get_option( 'bqw_settings', [] );
		if ( empty( $settings['enable_log'] ) ) {
			return;
		}

		$dir = self::log_dir();
		if ( ! $dir ) {
			return;
		}

		$line = sprintf(
			"[%s] %s %s %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? wp_json_encode( $context ) : ''
		);

		$file = trailingslashit( $dir ) . 'bqw-' . gmdate( 'Y-m' ) . '.log';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	private static function log_dir(): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}
}
