<?php
/**
 * Chat conversations storage (custom DB table).
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Conversations {

	private const SCHEMA_OPTION  = 'bqw_conversations_schema_v1';
	private const TABLE_BASENAME = 'bqw_conversations';

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
			role VARCHAR(20) NOT NULL,
			content LONGTEXT NOT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			ip VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			KEY session_idx (session_id, created_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION, 1 );
	}

	public static function append( string $session_id, string $role, string $content, array $metadata = [] ): int {
		global $wpdb;
		$ip   = self::client_ip();
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$role = in_array( $role, [ 'user', 'assistant', 'system' ], true ) ? $role : 'user';
		$wpdb->insert(
			self::table(),
			[
				'session_id' => self::normalize_session( $session_id ),
				'role'       => $role,
				'content'    => $content,
				'metadata'   => $metadata ? wp_json_encode( $metadata ) : null,
				'created_at' => current_time( 'mysql', true ),
				'ip'         => $ip,
				'user_agent' => $ua,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public static function history( string $session_id, int $limit = 50 ): array {
		global $wpdb;
		$session_id = self::normalize_session( $session_id );
		$table = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, metadata, created_at FROM {$table} WHERE session_id = %s ORDER BY id ASC LIMIT %d",
				$session_id,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	public static function delete_session( string $session_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), [ 'session_id' => self::normalize_session( $session_id ) ], [ '%s' ] );
	}

	/**
	 * Returns sessions with at least N messages but no associated lead.
	 */
	public static function abandoned( int $min_messages = 3, int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, MIN(created_at) AS first_at, MAX(created_at) AS last_at, COUNT(*) AS messages,
					MAX(ip) AS ip, MAX(user_agent) AS user_agent
				FROM {$table}
				GROUP BY session_id
				HAVING messages >= %d
				ORDER BY last_at DESC
				LIMIT %d",
				$min_messages,
				$limit
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return [];
		}
		// Filter out sessions that produced a lead.
		$ids = array_map( static function ( $r ) { return (string) $r['session_id']; }, $rows );
		$with_lead = self::sessions_with_lead( $ids );
		return array_values( array_filter( $rows, static function ( $r ) use ( $with_lead ) {
			return ! isset( $with_lead[ (string) $r['session_id'] ] );
		} ) );
	}

	public static function sessions_with_lead( array $session_ids ): array {
		global $wpdb;
		if ( empty( $session_ids ) ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$query = $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bqw_session_id' AND meta_value IN ({$placeholders})",
			$session_ids
		);
		$values = $wpdb->get_col( $query );
		$out = [];
		foreach ( (array) $values as $v ) {
			$out[ (string) $v ] = true;
		}
		return $out;
	}

	private static function normalize_session( string $sid ): string {
		$sid = preg_replace( '/[^A-Za-z0-9_\-]/', '', $sid );
		return substr( (string) $sid, 0, 64 );
	}

	private static function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0];
				return trim( $ip );
			}
		}
		return '';
	}
}
