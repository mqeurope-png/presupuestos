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

		$is_callme = isset( $lead['flow_origin'] ) && 'callme' === (string) $lead['flow_origin'];

		$default_subject = $is_callme
			? '📞 Solicitud de llamada — {nombre}'
			: 'Nueva solicitud de presupuesto: {producto} - {empresa}';
		$subject_tpl = (string) Settings::get(
			$is_callme ? 'notify_subject_callme' : 'notify_subject',
			$default_subject
		);

		$vars = self::vars( $lead );
		$subject = strtr( $subject_tpl, $vars );
		if ( $error ) {
			$subject = '[ERROR] ' . $subject;
		}

		$body_tpl = (string) Settings::get(
			$is_callme ? 'notify_body_callme' : 'notify_body',
			''
		);

		if ( '' !== $body_tpl ) {
			$body = strtr( $body_tpl, $vars );
			if ( $error ) {
				$body .= "\n\n--- ERROR ---\n" . $error;
			}
			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
			return wp_mail( $recipients, $subject, $body, $headers );
		}

		// No custom template — fall back to the historical plain-text body.
		$body = $is_callme ? self::default_callme_body( $lead, $vars, $contact_url ) : self::default_body( $lead, $contact_url );
		if ( $error ) {
			$body .= "\n\n--- ERROR ---\n" . $error;
		}
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		return wp_mail( $recipients, $subject, $body, $headers );
	}

	private static function vars( array $lead ): array {
		$products = '';
		if ( ! empty( $lead['selected_products'] ) ) {
			$names = [];
			foreach ( $lead['selected_products'] as $p ) {
				$names[] = '- ' . ( $p['name'] ?? '' ) . ( ! empty( $p['brand'] ) ? ' (' . $p['brand'] . ')' : '' );
			}
			$products = implode( "\n", $names );
		}
		return [
			'{nombre}'    => trim( ( $lead['first_name'] ?? '' ) . ' ' . ( $lead['last_name'] ?? '' ) ),
			'{email}'     => (string) ( $lead['email'] ?? '' ),
			'{empresa}'   => (string) ( $lead['company'] ?? '' ),
			'{producto}'  => (string) ( $lead['product_name'] ?? ( $lead['category_name'] ?? '' ) ),
			'{productos}' => $products,
			'{telefono}'  => (string) ( $lead['phone'] ?? '' ),
			'{pais}'      => (string) ( $lead['country'] ?? '' ),
			'{idioma}'    => (string) ( $lead['lang'] ?? substr( get_locale(), 0, 2 ) ),
			'{ip}'        => (string) ( $lead['ip'] ?? '' ),
			'{cuando}'    => (string) ( $lead['message'] ?? '' ),
			'{mensaje}'   => (string) ( $lead['message'] ?? '' ),
			'{fecha}'     => date_i18n( 'Y-m-d H:i' ),
			'{site_display_name}' => (string) Settings::get( 'site_display_name', '' ),
			'{bot_name}'  => (string) Settings::get( 'bot_name', '' ),
		];
	}

	private static function default_callme_body( array $lead, array $vars, ?string $contact_url ): string {
		$lines = [];
		$lines[] = 'Hola,';
		$lines[] = '';
		$lines[] = 'Has recibido una solicitud de llamada:';
		$lines[] = '';
		$lines[] = '📞 Llamar a: ' . $vars['{telefono}'];
		if ( '' !== trim( $vars['{cuando}'] ) ) {
			$lines[] = '🕐 Cuándo: ' . $vars['{cuando}'];
		}
		$lines[] = '';
		$lines[] = 'Datos del contacto:';
		$lines[] = '  - Nombre: ' . $vars['{nombre}'];
		$lines[] = '  - Email: ' . $vars['{email}'];
		if ( '' !== $vars['{pais}'] ) {
			$lines[] = '  - País: ' . $vars['{pais}'];
		}
		$lines[] = '  - Idioma: ' . $vars['{idioma}'];
		if ( '' !== $vars['{ip}'] ) {
			$lines[] = '  - IP: ' . $vars['{ip}'];
		}
		if ( '' !== trim( $vars['{productos}'] ) ) {
			$lines[] = '';
			$lines[] = 'Máquinas que tenía en la consulta:';
			$lines[] = $vars['{productos}'];
		}
		if ( $contact_url ) {
			$lines[] = '';
			$lines[] = 'Lead creado en AgileCRM: ' . $contact_url;
		}
		$lines[] = '';
		$lines[] = '— Bomedia Quote Wizard';
		return implode( "\n", $lines );
	}

	private static function default_body( array $lead, ?string $contact_url ): string {
		$lines   = [];
		$lines[] = sprintf( '%s: %s %s', __( 'Name', 'bomedia-quote-wizard' ), $lead['first_name'] ?? '', $lead['last_name'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Company', 'bomedia-quote-wizard' ), $lead['company'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Email', 'bomedia-quote-wizard' ), $lead['email'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Phone', 'bomedia-quote-wizard' ), $lead['phone'] ?? '' );
		$lines[] = sprintf( '%s: %s', __( 'Country', 'bomedia-quote-wizard' ), $lead['country'] ?? '' );
		$lines[] = '';
		$lines[] = __( 'Machines of interest:', 'bomedia-quote-wizard' );
		if ( ! empty( $lead['unsure'] ) ) {
			$lines[] = '  - ' . __( 'Customer is not sure, asks for help.', 'bomedia-quote-wizard' );
		} elseif ( ! empty( $lead['selected_products'] ) ) {
			foreach ( $lead['selected_products'] as $p ) {
				$line = '  - ' . $p['name'];
				if ( ! empty( $p['sku'] ) )          $line .= ' [SKU: ' . $p['sku'] . ']';
				if ( ! empty( $p['categoryName'] ) ) $line .= ' (' . $p['categoryName'] . ')';
				$lines[] = $line;
			}
		} else {
			$lines[] = '  - Lead sin máquinas seleccionadas. Contactar para conocer necesidades.';
		}
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
		return implode( "\n", $lines );
	}
}
