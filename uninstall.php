<?php
/**
 * Uninstall handler. Removes plugin options and CPT data.
 *
 * @package Bomedia\QuoteWizard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bqw_settings' );
delete_option( 'bqw_agilecrm_settings' );
delete_option( 'bqw_wizard_settings' );
delete_option( 'bqw_notifications_settings' );
delete_option( 'bqw_settings_migrated_v2' );
delete_option( 'bqw_settings_migrated_v3' );
delete_option( 'bqw_conversations_schema_v1' );
delete_option( 'bqw_openai_last_call' );
delete_option( 'bqw_catalog_last_fetch' );
delete_option( 'bqw_catalog_count' );

// Drop the conversations table.
global $wpdb;
$convo_table = $wpdb->prefix . 'bqw_conversations';
$wpdb->query( "DROP TABLE IF EXISTS {$convo_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

// Drop monthly cost trackers (best-effort, current and recent months).
for ( $i = 0; $i < 12; $i++ ) {
	$mk = 'bqw_openai_month_' . gmdate( 'Y-m', strtotime( '-' . $i . ' month' ) );
	delete_option( $mk );
}

// Remove all bqw_lead posts.
$leads = get_posts(
	[
		'post_type'      => 'bqw_lead',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	]
);
foreach ( $leads as $lead_id ) {
	wp_delete_post( $lead_id, true );
}

// Best-effort log cleanup.
$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$dir = trailingslashit( $uploads['basedir'] ) . 'bqw-logs';
	if ( is_dir( $dir ) ) {
		foreach ( glob( $dir . '/*' ) as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
}
