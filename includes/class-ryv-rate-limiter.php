<?php
/**
 * Small fixed-window rate limiter for the public, unauthenticated REST write
 * routes (progress log, lead submission).
 *
 * Privacy: the plugin's own tables are deliberately anonymous (no IP column),
 * and this keeps it that way - the visitor is identified only by a salted
 * hash of their address, held in a short-lived transient (it expires with the
 * window) and never written to any plugin table or log.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Rate_Limiter
 */
class RYV_Rate_Limiter {

	/**
	 * Count one hit for the current visitor against $bucket and say whether
	 * they're still within $limit hits per $window seconds.
	 *
	 * @param string $bucket Logical bucket, e.g. "submit".
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window length in seconds.
	 * @return int 0 when allowed, otherwise the seconds until the window resets.
	 */
	public static function hit( string $bucket, int $limit, int $window ): int {
		$ip = self::client_ip();

		// No usable address (CLI, odd proxy): fail open rather than lock out
		// everyone behind one blank key.
		if ( '' === $ip ) {
			return 0;
		}

		/**
		 * Filters the limit for a bucket (return 0 to disable it).
		 *
		 * @param int    $limit  Max hits per window.
		 * @param string $bucket Bucket name.
		 */
		$limit = (int) apply_filters( 'ryv_rate_limit', $limit, $bucket );

		if ( $limit <= 0 ) {
			return 0;
		}

		$key  = 'ryv_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['n'], $data['t'] ) || $now - (int) $data['t'] >= $window ) {
			$data = array(
				'n' => 0,
				't' => $now,
			);
		}

		++$data['n'];

		$remaining = max( 1, $window - ( $now - (int) $data['t'] ) );
		set_transient( $key, $data, $remaining );

		return $data['n'] > $limit ? $remaining : 0;
	}

	/**
	 * Build the 429 response for a blocked request.
	 *
	 * @param int $retry_after Seconds until the window resets.
	 * @return WP_Error
	 */
	public static function error( int $retry_after ): WP_Error {
		return new WP_Error(
			'ryv_rate_limited',
			__( 'Too many requests. Please wait a moment and try again.', 'ryv-eligibility-tool' ),
			array(
				'status'  => 429,
				'headers' => array( 'Retry-After' => (string) $retry_after ),
			)
		);
	}

	/**
	 * The visitor's address. REMOTE_ADDR only: X-Forwarded-For & co. are
	 * client-controlled, so trusting them would let an attacker dodge the
	 * limit by rotating a header. Sites behind a trusted proxy/CDN supply the
	 * real address through the `ryv_rate_limit_ip` filter.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the address used for rate limiting.
		 *
		 * @param string $ip Detected address.
		 */
		$ip = (string) apply_filters( 'ryv_rate_limit_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
