<?php
/**
 * Remote catalog client (Supabase).
 *
 * IMPORTANT (privacy): the upstream JSON includes operator data (users,
 * passwordHash, openaiKey, activityLog, templates…). The client extracts
 * ONLY data.products and data.brands and discards everything else
 * immediately. Nothing else is persisted, logged or cached.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Catalog_Client {

	public const DEFAULT_URL = 'https://midvgxxndddasxlnstkg.supabase.co/rest/v1/composer_data?select=data&id=eq.main';
	public const DEFAULT_KEY = 'sb_publishable_uiXB5JmZfPETeyGn3Rsw_Q_D7SLaJNd';

	public const REFRESH_HOOK = 'bqw_refresh_catalog';

	public static function url(): string {
		$u = (string) Settings::get( 'catalog_url', '' );
		return '' !== $u ? $u : self::DEFAULT_URL;
	}

	public static function api_key(): string {
		$enc = (string) Settings::get( 'catalog_api_key', '' );
		$dec = '' !== $enc ? Settings::decrypt( $enc ) : '';
		return '' !== $dec ? $dec : self::DEFAULT_KEY;
	}

	public static function refresh_interval_seconds(): int {
		$v = (int) Settings::get( 'catalog_refresh_interval', 86400 );
		return in_array( $v, [ 3600, 21600, 86400, 604800 ], true ) ? $v : 86400;
	}

	/**
	 * Fetches the upstream JSON, strips everything except products + brands,
	 * persists to the on-disk cache. Returns an array on success or WP_Error.
	 */
	public static function fetch_remote() {
		$key = self::api_key();
		$response = wp_remote_get( self::url(), [
			'timeout' => 15,
			'headers' => [
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			self::log_err( 'transport: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::log_err( sprintf( 'HTTP %d', $code ) );
			return new WP_Error( 'bqw_catalog_http_' . $code, sprintf( 'HTTP %d', $code ) );
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded[0]['data'] ) || ! is_array( $decoded[0]['data'] ) ) {
			self::log_err( 'malformed JSON' );
			return new WP_Error( 'bqw_catalog_bad', 'malformed JSON' );
		}

		// PRIVACY GUARD — extract only what we need; discard everything else.
		$data     = $decoded[0]['data'];
		$products = is_array( $data['products'] ?? null ) ? array_values( $data['products'] ) : [];
		$brands   = is_array( $data['brands']   ?? null ) ? array_values( $data['brands'] )   : [];

		// Drop the rest of the payload (users, openaiKey, passwordHash, activityLog, templates, …).
		unset( $decoded, $data, $body );

		self::cache_to_disk( $products, $brands );
		self::log_info( sprintf( 'fetched %d products, %d brands', count( $products ), count( $brands ) ) );

		return [ 'products' => $products, 'brands' => $brands ];
	}

	public static function cache_to_disk( array $products, array $brands ): void {
		$path = self::cache_path();
		if ( ! $path ) {
			return;
		}
		$payload = [
			'products'   => $products,
			'brands'     => $brands,
			'cached_at'  => time(),
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $path, wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), LOCK_EX );
		update_option( 'bqw_catalog_last_fetch', time(), false );
		update_option( 'bqw_catalog_count', count( $products ), false );
	}

	public static function read_cache(): array {
		$path = self::cache_path();
		if ( ! $path || ! file_exists( $path ) ) {
			return [ 'products' => [], 'brands' => [], 'cached_at' => 0 ];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$raw = @file_get_contents( $path );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : [ 'products' => [], 'brands' => [], 'cached_at' => 0 ];
	}

	public static function get_products(): array {
		self::maybe_refresh();
		$cache = self::read_cache();
		$out = [];
		foreach ( (array) ( $cache['products'] ?? [] ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			if ( isset( $p['visible'] ) && false === $p['visible'] ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	public static function get_brands(): array {
		$cache = self::read_cache();
		$out = [];
		foreach ( (array) ( $cache['brands'] ?? [] ) as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			if ( isset( $b['visible'] ) && false === $b['visible'] ) {
				continue;
			}
			$out[] = $b;
		}
		return $out;
	}

	/**
	 * Returns the localized product fields for the given locale. Falls back
	 * to root-level fields when a locale block is missing.
	 */
	public static function localize_product( array $p, string $locale ): array {
		$lang = strtolower( substr( $locale, 0, 2 ) );
		$tr = isset( $p['i18n'][ $lang ] ) && is_array( $p['i18n'][ $lang ] ) ? $p['i18n'][ $lang ] : [];
		$pick = static function ( $k ) use ( $tr, $p ) {
			$v = $tr[ $k ] ?? null;
			if ( null === $v || '' === $v ) {
				$v = $p[ $k ] ?? '';
			}
			return is_string( $v ) ? $v : (string) $v;
		};
		return [
			'id'    => (string) ( $p['id'] ?? '' ),
			'brand' => (string) ( $p['brand'] ?? '' ),
			'badge' => $pick( 'badge' ),
			'area'  => $pick( 'area' ),
			'feat1' => $pick( 'feat1' ),
			'feat2' => $pick( 'feat2' ),
			'price' => $pick( 'price' ),
			'desc'  => $pick( 'desc' ),
			'name'  => $pick( 'name' ),
			'img'   => (string) ( $p['img']  ?? '' ),
			'link'  => $pick( 'link' ) ?: (string) ( $p['link'] ?? '' ),
		];
	}

	/**
	 * Schedules a one-off background refresh if the cache is stale.
	 */
	public static function maybe_refresh(): void {
		$last     = (int) get_option( 'bqw_catalog_last_fetch', 0 );
		$interval = self::refresh_interval_seconds();
		if ( $last > 0 && ( $last + $interval ) > time() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REFRESH_HOOK );
		}
	}

	public static function last_fetch(): int {
		return (int) get_option( 'bqw_catalog_last_fetch', 0 );
	}

	public static function product_count(): int {
		return (int) get_option( 'bqw_catalog_count', 0 );
	}

	public static function override_with( array $products, array $brands ): void {
		self::cache_to_disk( $products, $brands );
		self::log_info( sprintf( 'manual upload %d products', count( $products ) ) );
	}

	private static function cache_path(): ?string {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-cache';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir . '/catalog.json';
	}

	private static function log_dir(): ?string {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $u['basedir'] ) . 'bqw-logs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	private static function log_err( string $msg ): void {
		$d = self::log_dir();
		if ( ! $d ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $d . '/catalog.log', sprintf( "[%s] ERR %s\n", gmdate( 'Y-m-d H:i:s' ), $msg ), FILE_APPEND | LOCK_EX );
	}

	private static function log_info( string $msg ): void {
		$d = self::log_dir();
		if ( ! $d ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $d . '/catalog.log', sprintf( "[%s] INFO %s\n", gmdate( 'Y-m-d H:i:s' ), $msg ), FILE_APPEND | LOCK_EX );
	}
}
