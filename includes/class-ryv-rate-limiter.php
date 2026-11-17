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
	 * A WP_REST_Response rather than a WP_Error: core only turns a `headers`
	 * entry into real HTTP headers for responses, not for WP_Error data.
	 *
	 * @param int $retry_after Seconds until the window resets.
	 * @return WP_REST_Response
	 */
	public static function error( int $retry_after ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'code'    => 'ryv_rate_limited',
				'message' => __( 'Too many requests. Please wait a moment and try again.', 'ryv-eligibility-tool' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$response->header( 'Retry-After', (string) $retry_after );

		return $response;
	}

	/**
	 * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
	 * `CF-Connecting-IP` is believed only when the connection itself came from
	 * one of these, i.e. when the request really passed through Cloudflare -
	 * a client talking to the origin directly cannot make us trust the header.
	 * Filterable (`ryv_trusted_proxy_ranges`) so another CDN/proxy can be added.
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * The visitor's address. REMOTE_ADDR, except when the connection came from
	 * a trusted proxy (Cloudflare) - then the address that proxy reports in
	 * `CF-Connecting-IP`. X-Forwarded-For & co. are client-controlled and never
	 * used. Sites behind another proxy supply the real address through the
	 * `ryv_rate_limit_ip` filter.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$resolved = self::resolve_ip();

		/**
		 * Filters the address used for rate limiting.
		 *
		 * @param string $ip Detected address.
		 */
		$ip = (string) apply_filters( 'ryv_rate_limit_ip', $resolved['ip'] );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Which address is used and why (also shown to admins on Embed & Share, so
	 * a shared proxy address - one counter for every visitor - is easy to spot).
	 *
	 * @return array{ip:string,source:string,remote_addr:string,cf_header:string}
	 */
	public static function resolve_ip(): array {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		$cf     = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
		$cf     = filter_var( $cf, FILTER_VALIDATE_IP ) ? $cf : '';

		if ( '' !== $remote && '' !== $cf && self::ip_in_ranges( $remote, self::trusted_ranges() ) ) {
			return array( 'ip' => $cf, 'source' => 'cloudflare', 'remote_addr' => $remote, 'cf_header' => $cf );
		}

		return array( 'ip' => $remote, 'source' => 'remote_addr', 'remote_addr' => $remote, 'cf_header' => $cf );
	}

	/**
	 * @return string[] CIDR ranges whose connections may vouch for the real client address.
	 */
	private static function trusted_ranges(): array {
		/**
		 * Filters the proxy ranges trusted to report the client address in `CF-Connecting-IP`.
		 *
		 * @param string[] $ranges CIDR ranges.
		 */
		return (array) apply_filters( 'ryv_trusted_proxy_ranges', self::CLOUDFLARE_RANGES );
	}

	/**
	 * Whether $ip lies in any of the CIDR $ranges (IPv4 and IPv6).
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	public static function ip_in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is filtered above; returns false.
		if ( false === $bin ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			if ( ! is_string( $range ) || false === strpos( $range, '/' ) ) {
				continue;
			}
			list( $base, $bits ) = explode( '/', $range, 2 );
			$base_bin            = @inet_pton( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$bits                = (int) $bits;
			if ( false === $base_bin || strlen( $base_bin ) !== strlen( $bin ) || $bits < 0 || $bits > strlen( $bin ) * 8 ) {
				continue;
			}

			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( 0 !== strncmp( $bin, $base_bin, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $base_bin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}
