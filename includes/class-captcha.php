<?php
/**
 * Lightweight stateless math captcha. Generates a question PHP-side, signs
 * the expected answer with HMAC + timestamp; verifies on submit.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

final class Captcha {

	private const TTL_SECONDS = 1800; // 30 minutes.

	public static function is_enabled(): bool {
		return (int) Settings::get( 'enable_captcha', 1 ) === 1;
	}

	/**
	 * Returns ['question' => '3 + 5', 'token' => '...', 'ts' => 12345].
	 */
	public static function generate_challenge(): array {
		$a  = wp_rand( 2, 9 );
		$b  = wp_rand( 2, 9 );
		$op = wp_rand( 0, 1 ) ? '+' : '×';
		$expected = '+' === $op ? $a + $b : $a * $b;
		$ts       = time();
		$token    = self::sign( $expected, $ts );
		return [
			'question' => sprintf( '%d %s %d', $a, $op, $b ),
			'token'    => $token,
			'ts'       => $ts,
		];
	}

	public static function verify( $answer, $token, $ts ): bool {
		$answer = (int) $answer;
		$ts     = (int) $ts;
		$token  = is_string( $token ) ? $token : '';
		if ( $ts <= 0 || ( time() - $ts ) > self::TTL_SECONDS ) {
			return false;
		}
		$expected_token = self::sign( $answer, $ts );
		return hash_equals( $expected_token, $token );
	}

	private static function sign( int $value, int $ts ): string {
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bqw-fallback-' . get_site_url();
		return hash_hmac( 'sha256', $value . '|' . $ts, $key );
	}
}
