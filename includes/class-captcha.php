<?php
/**
 * Captcha provider abstraction with concrete implementations:
 * Math (HMAC-signed), reCAPTCHA v2, reCAPTCHA v3, Turnstile, hCaptcha.
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Factory                                                             */
/* ------------------------------------------------------------------ */

final class Captcha {

	public const PROVIDER_MATH        = 'math';
	public const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';
	public const PROVIDER_RECAPTCHA_V3 = 'recaptcha_v3';
	public const PROVIDER_TURNSTILE   = 'turnstile';
	public const PROVIDER_HCAPTCHA    = 'hcaptcha';

	public static function is_enabled(): bool {
		return (int) Settings::get( 'enable_captcha', 1 ) === 1;
	}

	public static function provider(): Captcha_Provider {
		$slug = (string) Settings::get( 'captcha_provider', self::PROVIDER_MATH );
		switch ( $slug ) {
			case self::PROVIDER_RECAPTCHA_V2:
				return new Recaptcha_V2_Provider();
			case self::PROVIDER_RECAPTCHA_V3:
				return new Recaptcha_V3_Provider();
			case self::PROVIDER_TURNSTILE:
				return new Turnstile_Provider();
			case self::PROVIDER_HCAPTCHA:
				return new Hcaptcha_Provider();
			default:
				return new Math_Provider();
		}
	}

	public static function providers_list(): array {
		return [
			self::PROVIDER_MATH         => __( 'Math (no setup)', 'bomedia-quote-wizard' ),
			self::PROVIDER_RECAPTCHA_V2 => __( 'Google reCAPTCHA v2 (checkbox)', 'bomedia-quote-wizard' ),
			self::PROVIDER_RECAPTCHA_V3 => __( 'Google reCAPTCHA v3 (invisible, score)', 'bomedia-quote-wizard' ),
			self::PROVIDER_TURNSTILE    => __( 'Cloudflare Turnstile', 'bomedia-quote-wizard' ),
			self::PROVIDER_HCAPTCHA     => __( 'hCaptcha', 'bomedia-quote-wizard' ),
		];
	}

	// Backwards-compat thin wrappers.
	public static function generate_challenge(): array {
		return ( new Math_Provider() )->generate_challenge();
	}
}

/* ------------------------------------------------------------------ */
/* Abstract base                                                       */
/* ------------------------------------------------------------------ */

abstract class Captcha_Provider {

	/** @return string Slug identifier. */
	abstract public function slug(): string;

	/** Returns context array used by template render. */
	abstract public function build_context(): array;

	/** Verifies the submission. Returns true on success or WP_Error. */
	abstract public function verify( array $request ); // bool|WP_Error.

	/** Optional script URL to enqueue. */
	public function script_url(): ?string {
		return null;
	}

	protected function http_post( string $url, array $body ): array {
		$response = wp_remote_post( $url, [
			'timeout' => 8,
			'body'    => $body,
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false ];
	}

	protected function err( string $msg ): WP_Error {
		return new WP_Error( 'bqw_captcha_invalid', $msg );
	}
}

/* ------------------------------------------------------------------ */
/* Math (legacy default)                                               */
/* ------------------------------------------------------------------ */

final class Math_Provider extends Captcha_Provider {

	private const TTL_SECONDS = 1800;

	public function slug(): string { return Captcha::PROVIDER_MATH; }

	public function generate_challenge(): array {
		$a = wp_rand( 2, 9 );
		$b = wp_rand( 2, 9 );
		$op = wp_rand( 0, 1 ) ? '+' : '×';
		$expected = '+' === $op ? $a + $b : $a * $b;
		$ts = time();
		return [
			'question' => sprintf( '%d %s %d', $a, $op, $b ),
			'token'    => $this->sign( $expected, $ts ),
			'ts'       => $ts,
		];
	}

	public function build_context(): array {
		$c = $this->generate_challenge();
		return [
			'provider' => $this->slug(),
			'question' => $c['question'],
			'token'    => $c['token'],
			'ts'       => $c['ts'],
		];
	}

	public function verify( array $r ) {
		$answer = (int) ( $r['bqw_captcha_answer'] ?? 0 );
		$ts     = (int) ( $r['bqw_captcha_ts'] ?? 0 );
		$token  = (string) ( $r['bqw_captcha_token'] ?? '' );
		if ( $ts <= 0 || ( time() - $ts ) > self::TTL_SECONDS ) {
			return $this->err( __( 'The verification expired. Please reload the page.', 'bomedia-quote-wizard' ) );
		}
		if ( ! hash_equals( $this->sign( $answer, $ts ), $token ) ) {
			return $this->err( __( 'The verification answer is incorrect. Please try again.', 'bomedia-quote-wizard' ) );
		}
		return true;
	}

	private function sign( int $value, int $ts ): string {
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bqw-fallback-' . get_site_url();
		return hash_hmac( 'sha256', $value . '|' . $ts, $key );
	}
}

/* ------------------------------------------------------------------ */
/* reCAPTCHA v2                                                        */
/* ------------------------------------------------------------------ */

final class Recaptcha_V2_Provider extends Captcha_Provider {

	public function slug(): string { return Captcha::PROVIDER_RECAPTCHA_V2; }

	public function script_url(): ?string {
		return 'https://www.google.com/recaptcha/api.js?hl=' . substr( get_locale(), 0, 2 );
	}

	public function build_context(): array {
		return [
			'provider' => $this->slug(),
			'site_key' => (string) Settings::get( 'recaptcha_site_key', '' ),
		];
	}

	public function verify( array $r ) {
		$token  = (string) ( $r['g-recaptcha-response'] ?? '' );
		$secret = Settings::decrypt( (string) Settings::get( 'recaptcha_secret_key', '' ) );
		if ( '' === $token || '' === $secret ) {
			return $this->err( __( 'reCAPTCHA verification failed.', 'bomedia-quote-wizard' ) );
		}
		$resp = $this->http_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $r['remote_ip'] ?? '',
		] );
		if ( empty( $resp['success'] ) ) {
			return $this->err( __( 'reCAPTCHA could not verify your submission.', 'bomedia-quote-wizard' ) );
		}
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* reCAPTCHA v3                                                        */
/* ------------------------------------------------------------------ */

final class Recaptcha_V3_Provider extends Captcha_Provider {

	public function slug(): string { return Captcha::PROVIDER_RECAPTCHA_V3; }

	public function script_url(): ?string {
		return 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( (string) Settings::get( 'recaptcha_site_key', '' ) );
	}

	public function build_context(): array {
		return [
			'provider' => $this->slug(),
			'site_key' => (string) Settings::get( 'recaptcha_site_key', '' ),
			'action'   => 'boprint_quote',
		];
	}

	public function verify( array $r ) {
		$token  = (string) ( $r['g-recaptcha-response'] ?? '' );
		$secret = Settings::decrypt( (string) Settings::get( 'recaptcha_secret_key', '' ) );
		if ( '' === $token || '' === $secret ) {
			return $this->err( __( 'reCAPTCHA verification failed.', 'bomedia-quote-wizard' ) );
		}
		$resp = $this->http_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $r['remote_ip'] ?? '',
		] );
		if ( empty( $resp['success'] ) ) {
			return $this->err( __( 'reCAPTCHA could not verify your submission.', 'bomedia-quote-wizard' ) );
		}
		$threshold = (float) Settings::get( 'recaptcha_v3_threshold', 0.5 );
		$score     = isset( $resp['score'] ) ? (float) $resp['score'] : 0.0;
		if ( $score < $threshold ) {
			Logger::error( 'reCAPTCHA v3 score below threshold', [ 'score' => $score, 'threshold' => $threshold ] );
			return $this->err( __( 'Your submission looks suspicious. Try again.', 'bomedia-quote-wizard' ) );
		}
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* Cloudflare Turnstile                                                */
/* ------------------------------------------------------------------ */

final class Turnstile_Provider extends Captcha_Provider {

	public function slug(): string { return Captcha::PROVIDER_TURNSTILE; }

	public function script_url(): ?string {
		return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	}

	public function build_context(): array {
		return [
			'provider' => $this->slug(),
			'site_key' => (string) Settings::get( 'turnstile_site_key', '' ),
		];
	}

	public function verify( array $r ) {
		$token  = (string) ( $r['cf-turnstile-response'] ?? '' );
		$secret = Settings::decrypt( (string) Settings::get( 'turnstile_secret_key', '' ) );
		if ( '' === $token || '' === $secret ) {
			return $this->err( __( 'Turnstile verification failed.', 'bomedia-quote-wizard' ) );
		}
		$resp = $this->http_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $r['remote_ip'] ?? '',
		] );
		if ( empty( $resp['success'] ) ) {
			return $this->err( __( 'Turnstile could not verify your submission.', 'bomedia-quote-wizard' ) );
		}
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* hCaptcha                                                            */
/* ------------------------------------------------------------------ */

final class Hcaptcha_Provider extends Captcha_Provider {

	public function slug(): string { return Captcha::PROVIDER_HCAPTCHA; }

	public function script_url(): ?string {
		return 'https://js.hcaptcha.com/1/api.js';
	}

	public function build_context(): array {
		return [
			'provider' => $this->slug(),
			'site_key' => (string) Settings::get( 'hcaptcha_site_key', '' ),
		];
	}

	public function verify( array $r ) {
		$token  = (string) ( $r['h-captcha-response'] ?? '' );
		$secret = Settings::decrypt( (string) Settings::get( 'hcaptcha_secret_key', '' ) );
		if ( '' === $token || '' === $secret ) {
			return $this->err( __( 'hCaptcha verification failed.', 'bomedia-quote-wizard' ) );
		}
		$resp = $this->http_post( 'https://hcaptcha.com/siteverify', [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => $r['remote_ip'] ?? '',
		] );
		if ( empty( $resp['success'] ) ) {
			return $this->err( __( 'hCaptcha could not verify your submission.', 'bomedia-quote-wizard' ) );
		}
		return true;
	}
}
