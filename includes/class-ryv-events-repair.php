<?php
/**
 * One-off repair for Events that were written straight into wp_posts/postmeta
 * (a raw import or direct SQL) rather than through The Events Calendar's own
 * save pipeline.
 *
 * The symptom is total and silent: not one `tribe_events` post is visible
 * anywhere - not on a state page's events list, not in the ACF relationship
 * picker ("No matches found"), not even on TEC's own Upcoming Events archive -
 * while the posts plainly exist in the database.
 *
 * The cause is two layers deep:
 *
 * 1. TEC 6.x adds an `INNER JOIN` on its own `tec_occurrences` table to every
 *    `tribe_events` query. An event with no row there does not exist as far
 *    as any query is concerned.
 * 2. TEC only writes that row when its Event model validates. On the data
 *    found here, validation failed on
 *    "The start_date and start_date_utc has a conflict when using the
 *    timezone of the event": the import had written `_EventStartDateUTC`
 *    with the *local* time string (10:00 stored as both local and UTC) while
 *    the site runs on America/New_York, and had written no `_EventTimezone`
 *    at all.
 *
 * So this recomputes the UTC meta from local time + timezone, fills in a
 * missing timezone from the site's own, and then asks TEC to rebuild its
 * tables through its own updater rather than writing to them directly.
 *
 * Idempotent: an event whose UTC values already agree with its local ones is
 * left untouched, and TEC's updater upserts. Safe to re-run on any
 * environment, which is the point - the same broken rows exist wherever that
 * import was applied.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Events_Repair
 */
class RYV_Events_Repair {

	/**
	 * TEC's post type.
	 */
	const POST_TYPE = 'tribe_events';

	/**
	 * TEC's own custom-tables updater, addressed by class name through the
	 * `tribe()` container so this file never has to load TEC's classes.
	 */
	const TEC_UPDATER = 'TEC\\Events\\Custom_Tables\\V1\\Updates\\Events';

	/**
	 * Whether TEC is active enough to repair anything.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'tribe' ) && class_exists( self::TEC_UPDATER );
	}

	/**
	 * Repair every event whose stored UTC dates disagree with its local ones.
	 *
	 * @return array{checked:int,fixed:int,rebuilt:int,messages:string[]}
	 */
	public static function repair_all(): array {
		global $wpdb;

		$result = array(
			'checked'  => 0,
			'fixed'    => 0,
			'rebuilt'  => 0,
			'messages' => array(),
		);

		if ( ! self::is_available() ) {
			$result['messages'][] = __( 'The Events Calendar is not active, or is older than the version that uses custom tables.', 'ryv-eligibility-tool' );
			return $result;
		}

		// Read ids straight from the posts table: every WP_Query path for this
		// post type is exactly what's broken, so asking WP_Query for the list
		// would be circular.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft' )",
				self::POST_TYPE
			)
		);

		$site_timezone = self::get_site_timezone();

		foreach ( $ids as $id ) {
			$id = (int) $id;
			$result['checked']++;

			if ( self::repair_event( $id, $site_timezone ) ) {
				$result['fixed']++;
			}

			if ( tribe( self::TEC_UPDATER )->update( $id ) ) {
				$result['rebuilt']++;
			}
		}

		return $result;
	}

	/**
	 * Bring one event's timezone/UTC meta into agreement with its local dates.
	 *
	 * @param int    $post_id       Event post ID.
	 * @param string $site_timezone Fallback timezone identifier.
	 * @return bool Whether anything was actually changed.
	 */
	private static function repair_event( int $post_id, string $site_timezone ): bool {
		$changed  = false;
		$timezone = (string) get_post_meta( $post_id, '_EventTimezone', true );

		if ( '' === $timezone ) {
			$timezone = $site_timezone;
			update_post_meta( $post_id, '_EventTimezone', $timezone );
			update_post_meta( $post_id, '_EventTimezoneAbbr', $timezone );
			$changed = true;
		}

		try {
			$tz = new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			// An unparseable timezone is a data problem this can't guess its
			// way out of - leave the event alone rather than writing dates
			// derived from a wrong zone.
			return $changed;
		}

		foreach ( array( '_EventStartDate' => '_EventStartDateUTC', '_EventEndDate' => '_EventEndDateUTC' ) as $local_key => $utc_key ) {
			$local = (string) get_post_meta( $post_id, $local_key, true );

			if ( '' === $local ) {
				continue;
			}

			try {
				$date = new DateTime( $local, $tz );
			} catch ( Exception $e ) {
				continue;
			}

			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			$expected = $date->format( 'Y-m-d H:i:s' );

			if ( (string) get_post_meta( $post_id, $utc_key, true ) !== $expected ) {
				update_post_meta( $post_id, $utc_key, $expected );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * The site's timezone as an identifier TEC accepts.
	 *
	 * A site configured with a raw UTC offset rather than a city has no
	 * identifier at all; 'UTC' is the honest answer there, and still lets
	 * TEC's validation pass.
	 *
	 * @return string
	 */
	private static function get_site_timezone(): string {
		$timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : (string) get_option( 'timezone_string' );

		// wp_timezone_string() returns an offset like "+03:00" when no city is
		// set, which DateTimeZone accepts but TEC's own validator may not.
		if ( '' === $timezone || preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone ) ) {
			return 'UTC';
		}

		return $timezone;
	}
}
