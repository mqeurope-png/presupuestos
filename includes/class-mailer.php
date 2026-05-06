<?php
/**
 * Internal email notifications.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	public static function send_lead_notification( array $lead, ?string $contact_url = null, ?string $error = null ): bool {
		$emails_raw = (string) Settings::get( 'notify_emails', '' );
		$recipients = array_filter( array_map( 'trim', explode( ',', $emails_raw ) ), 'is_email' );
		if ( ! $recipients ) {
			return false;
		}

		$subject_tpl = (string) Settings::get( 'notify_subject', 'Nueva solicitud de presupuesto: {producto} - {empresa}' );
		$subject     = strtr(
			$subject_tpl,
			[
				'{nombre}'   => trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) ),
				'{empresa}'  => $lead['company'] ?? '',
				'{producto}' => $lead['product_name'] ?? ( $lead['category_name'] ?? '' ),
			]
		);

		if ( $error ) {
			$subject = '[ERROR] ' . $subject;
		}

		$lines   = [];
		$lines[] = sprintf( '%s: %s %s', __( 'Name', 'bomedia-quote-wizard' ), $lead['first_name'] ?? '', $lead['last_name'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Company', 'bomedia-quote-wizard' ), $lead['company'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Email', 'bomedia-quote-wizard' ), $lead['email'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Phone', 'bomedia-quote-wizard' ), $lead['phone'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Country', 'bomedia-quote-wizard' ), $lead['country'] ?? '' );
		$lines[] = '';
		$lines[] = sprintf( '%s: %s', __( 'Category', 'bomedia-quote-wizard' ), $lead['category_name'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Product', 'bomedia-quote-wizard' ), $lead['product_name'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Application', 'bomedia-quote-wizard' ), $lead['application'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Materials', 'bomedia-quote-wizard' ), is_array( $lead['materials'] ?? null ) ? implode( ', ', $lead['materials'] ) : ( $lead['materials'] ?? '' ) );
		$lines[] = sprintf( '%s: %s', __( 'Monthly volume', 'bomedia-quote-wizard' ), $lead['volume'] ?? '' );
		$lines[] = '';
		$lines[] = __( 'Message:', 'bomedia-quote-wizard' );
		$lines[] = $lead['message'] ?? '';
		$lines[] = '';
		$lines[] = sprintf( '%s: %s', __( 'Source URL', 'bomedia-quote-wizard' ), $lead['source_url'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Source site', 'bomedia-quote-wizard' ), $lead['source_site'] ?? '' );

		if ( $contact_url ) {
			$lines[] = '';
			$lines[] = sprintf( '%s: %s', __( 'AgileCRM contact', 'bomedia-quote-wizard' ), $contact_url );
		}
		if ( $error ) {
			$lines[] = '';
			$lines[] = '--- ERROR ---';
			$lines[] = $error;
		}

		$body    = implode( "\n", $lines );
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		return wp_mail( $recipients, $subject, $body, $headers );
	}
}
