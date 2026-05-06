<?php
/**
 * AgileCRM HTTP client.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AgileCRM_Client {

	private const TIMEOUT = 15;

	private string $domain;
	private string $email;
	private string $api_key;

	public function __construct() {
		$this->domain  = (string) Settings::get( 'agile_domain' );
		$this->email   = (string) Settings::get( 'agile_email' );
		$enc_key       = (string) Settings::get( 'agile_api_key' );
		$this->api_key = Settings::decrypt( $enc_key );
	}

	public function is_configured(): bool {
		return $this->domain !== '' && $this->email !== '' && $this->api_key !== '';
	}

	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'bqw_not_configured', __( 'AgileCRM credentials are incomplete.', 'bomedia-quote-wizard' ) );
		}
		$response = wp_remote_get(
			$this->base_url() . '/dev/api/users/current-user',
			[
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
			]
		);
		return $this->parse_response( $response );
	}

	/**
	 * @return array|WP_Error  Decoded contact on success.
	 */
	public function create_contact( array $payload ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'bqw_not_configured', __( 'AgileCRM credentials are incomplete.', 'bomedia-quote-wizard' ) );
		}
		$response = wp_remote_post(
			$this->base_url() . '/dev/api/contacts',
			[
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $payload ),
			]
		);
		return $this->parse_response( $response );
	}

	/**
	 * @return array|WP_Error
	 */
	public function add_note( $contact_id, string $subject, string $description ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'bqw_not_configured', __( 'AgileCRM credentials are incomplete.', 'bomedia-quote-wizard' ) );
		}
		$body = [
			'subject'        => $subject,
			'description'    => $description,
			'contact_ids'    => [ (string) $contact_id ],
		];
		$response = wp_remote_post(
			$this->base_url() . '/dev/api/notes',
			[
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			]
		);
		return $this->parse_response( $response );
	}

	private function base_url(): string {
		return 'https://' . rawurlencode( $this->domain ) . '.agilecrm.com';
	}

	private function headers(): array {
		return [
			'Authorization' => 'Basic ' . base64_encode( $this->email . ':' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			Logger::error( 'AgileCRM transport error', [ 'msg' => $response->get_error_message() ] );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			Logger::error( 'AgileCRM HTTP ' . $code, [ 'body' => $body ] );
			return new WP_Error( 'bqw_agile_http_' . $code, sprintf( 'HTTP %d: %s', $code, $body ) );
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public function contact_url( $contact_id ): string {
		return $this->base_url() . '/#contact/' . rawurlencode( (string) $contact_id );
	}
}
