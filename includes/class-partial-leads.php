<?php
/**
 * Partial leads (early capture: name + email before the user finishes).
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Partial_Leads {

	private const SCHEMA_OPTION  = 'bqw_partial_leads_schema_v2';
	private const TABLE_BASENAME = 'bqw_partial_leads';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASENAME;
	}

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) ) {
			return;
		}
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			name VARCHAR(120) NOT NULL DEFAULT '',
			email VARCHAR(160) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			marketing_optin TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			abandoned_step VARCHAR(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_session (session_id),
			KEY idx_email (email),
			KEY idx_created (created_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		// Clear the v1 option so a downgrade-then-upgrade still triggers dbDelta.
		delete_option( 'bqw_partial_leads_schema_v1' );
		update_option( self::SCHEMA_OPTION, 1 );
	}

	public static function upsert( string $session_id, string $name, string $email, array $extra = [] ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ip  = self::client_ip();
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE session_id = %s LIMIT 1',
			$session_id
		) );

		$row = [
			'name'       => $name,
			'email'      => $email,
			'updated_at' => $now,
			'ip'         => $ip,
			'user_agent' => $ua,
		];
		if ( array_key_exists( 'phone', $extra ) ) {
			$row['phone'] = (string) $extra['phone'];
		}
		if ( array_key_exists( 'marketing_optin', $extra ) ) {
			$row['marketing_optin'] = ! empty( $extra['marketing_optin'] ) ? 1 : 0;
		}

		if ( $existing ) {
			$formats = array_fill( 0, count( $row ), '%s' );
			if ( isset( $row['marketing_optin'] ) ) {
				$keys = array_keys( $row );
				$formats[ array_search( 'marketing_optin', $keys, true ) ] = '%d';
			}
			$wpdb->update( self::table(), $row, [ 'id' => (int) $existing ], $formats, [ '%d' ] );
		} else {
			$row['session_id']     = $session_id;
			$row['created_at']     = $now;
			$row['abandoned_step'] = '';
			$wpdb->insert( self::table(), $row );
		}
	}

	public static function update_step( string $session_id, string $step ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'abandoned_step' => $step,
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'session_id' => $session_id ],
			[ '%s', '%s' ],
			[ '%s' ]
		);
	}

	public static function delete( string $session_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'session_id' => $session_id ], [ '%s' ] );
	}

	public static function delete_by_id( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
	}

	public static function get( string $session_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %s LIMIT 1',
			$session_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function paginated( int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
			$per_page,
			$offset
		), ARRAY_A );
		$total    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
		return [ 'rows' => (array) $rows, 'total' => $total ];
	}

	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip;
	}
}
