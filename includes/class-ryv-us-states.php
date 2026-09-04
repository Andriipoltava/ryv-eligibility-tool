<?php
/**
 * Static US state slug => display name lookup, used to label the state
 * picker without requiring a display name to be re-entered per import.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_US_States
 */
class RYV_US_States {

	/**
	 * Slug => display name, including DC.
	 */
	const MAP = array(
		'alabama'        => 'Alabama',
		'alaska'         => 'Alaska',
		'arizona'        => 'Arizona',
		'arkansas'       => 'Arkansas',
		'california'     => 'California',
		'colorado'       => 'Colorado',
		'connecticut'    => 'Connecticut',
		'delaware'       => 'Delaware',
		'district-of-columbia' => 'District of Columbia',
		'florida'        => 'Florida',
		'georgia'        => 'Georgia',
		'hawaii'         => 'Hawaii',
		'idaho'          => 'Idaho',
		'illinois'       => 'Illinois',
		'indiana'        => 'Indiana',
		'iowa'           => 'Iowa',
		'kansas'         => 'Kansas',
		'kentucky'       => 'Kentucky',
		'louisiana'      => 'Louisiana',
		'maine'          => 'Maine',
		'maryland'       => 'Maryland',
		'massachusetts'  => 'Massachusetts',
		'michigan'       => 'Michigan',
		'minnesota'      => 'Minnesota',
		'mississippi'    => 'Mississippi',
		'missouri'       => 'Missouri',
		'montana'        => 'Montana',
		'nebraska'       => 'Nebraska',
		'nevada'         => 'Nevada',
		'new-hampshire'  => 'New Hampshire',
		'new-jersey'     => 'New Jersey',
		'new-mexico'     => 'New Mexico',
		'new-york'       => 'New York',
		'north-carolina' => 'North Carolina',
		'north-dakota'   => 'North Dakota',
		'ohio'           => 'Ohio',
		'oklahoma'       => 'Oklahoma',
		'oregon'         => 'Oregon',
		'pennsylvania'   => 'Pennsylvania',
		'puerto-rico'    => 'Puerto Rico',
		'rhode-island'   => 'Rhode Island',
		'south-carolina' => 'South Carolina',
		'south-dakota'   => 'South Dakota',
		'tennessee'      => 'Tennessee',
		'texas'          => 'Texas',
		'utah'           => 'Utah',
		'vermont'        => 'Vermont',
		'virginia'       => 'Virginia',
		'washington'     => 'Washington',
		'west-virginia'  => 'West Virginia',
		'wisconsin'      => 'Wisconsin',
		'wyoming'        => 'Wyoming',
	);

	/**
	 * Slug => 2-letter SVG region ID used by MapSVG.
	 *
	 * These match the `id` attributes in the usa.svg bundled with MapSVG
	 * (maps/not-calibrated/usa/usa.svg).
	 */
	const SVG_IDS = array(
		'alabama'              => 'AL',
		'alaska'               => 'AK',
		'arizona'              => 'AZ',
		'arkansas'             => 'AR',
		'california'           => 'CA',
		'colorado'             => 'CO',
		'connecticut'          => 'CT',
		'delaware'             => 'DE',
		'district-of-columbia' => 'DC',
		'florida'              => 'FL',
		'georgia'              => 'GA',
		'hawaii'               => 'HI',
		'idaho'                => 'ID',
		'illinois'             => 'IL',
		'indiana'              => 'IN',
		'iowa'                 => 'IA',
		'kansas'               => 'KS',
		'kentucky'             => 'KY',
		'louisiana'            => 'LA',
		'maine'                => 'ME',
		'maryland'             => 'MD',
		'massachusetts'        => 'MA',
		'michigan'             => 'MI',
		'minnesota'            => 'MN',
		'mississippi'          => 'MS',
		'missouri'             => 'MO',
		'montana'              => 'MT',
		'nebraska'             => 'NE',
		'nevada'               => 'NV',
		'new-hampshire'        => 'NH',
		'new-jersey'           => 'NJ',
		'new-mexico'           => 'NM',
		'new-york'             => 'NY',
		'north-carolina'       => 'NC',
		'north-dakota'         => 'ND',
		'ohio'                 => 'OH',
		'oklahoma'             => 'OK',
		'oregon'               => 'OR',
		'pennsylvania'         => 'PA',
		'puerto-rico'          => 'PR',
		'rhode-island'         => 'RI',
		'south-carolina'       => 'SC',
		'south-dakota'         => 'SD',
		'tennessee'            => 'TN',
		'texas'                => 'TX',
		'utah'                 => 'UT',
		'vermont'              => 'VT',
		'virginia'             => 'VA',
		'washington'           => 'WA',
		'west-virginia'        => 'WV',
		'wisconsin'            => 'WI',
		'wyoming'              => 'WY',
	);

	/**
	 * Get SVG region ID for a state slug, in the exact form MapSVG's own
	 * region table uses.
	 *
	 * The map's SVG source (`maps.options.source`, on this site
	 * `.../maps/not-calibrated/usa/usa.svg`) keeps every region - all 50
	 * states + DC, no "US-" prefix - as a bare 2-letter code; a prior
	 * version of this method prepended "US-" to everything except a
	 * hardcoded territory list, which its own docblock claimed was
	 * "confirmed against the actual wp_mapsvg6_regions_1 region ids" but
	 * wasn't: querying that table directly shows 51 rows, all bare 2-letter
	 * ids ("AL", "AK", ... "DC"), zero with a "US-" prefix. Because
	 * `sync_to_mapsvg()` only ever UPDATEs an existing region row (never
	 * INSERTs), the wrong prefix made its `WHERE id = 'US-AL'` match
	 * nothing for every one of the 50 states + DC - the map's category
	 * colors silently never reached it, no matter how many times a state
	 * was saved or "Sync Category Colors to Map" was clicked, since the
	 * write itself was a no-op the entire time. Confirmed live: every row
	 * in `wp_mapsvg6_regions_1` still carried MapSVG's own default
	 * `status = 1` / `status_text = "Enabled"`, never one of this plugin's
	 * `status` values (see `RYV_MapSVG_Sync::STATUS_VALUE_BASE`).
	 *
	 * `ryv_state_slug` isn't consistently a hyphenated full-name slug
	 * ("new-hampshire") across every state: the original Drupal webform's
	 * state-picker options mixed full-name values with bare 2-letter ones
	 * ("nh") for some states, and RYV_States_Generator just carries
	 * whatever `sanitize_title()` of that option value produces - so a
	 * state's own $slug can legitimately already *be* its postal code.
	 * Falling back to treating $slug itself as a code (if it's a known
	 * postal abbreviation) handles that without needing to fix the
	 * upstream data.
	 *
	 * @param string $slug State slug (e.g. "tennessee", or bare "tn"/"nh").
	 * @return string|null Region ID or null if not found.
	 */
	public static function svg_id( string $slug ): ?string {
		$code = self::SVG_IDS[ $slug ] ?? null;

		if ( ! $code ) {
			$maybe_code = strtoupper( $slug );
			if ( in_array( $maybe_code, self::SVG_IDS, true ) ) {
				$code = $maybe_code;
			}
		}

		return $code ?: null;
	}

	/**
	 * Reverse lookup: SVG region ID => slug.
	 *
	 * @param string $svg_id Region ID, with or without the "US-" prefix (e.g. "US-TN" or "TN").
	 * @return string|null Slug or null.
	 */
	public static function slug_from_svg_id( string $svg_id ): ?string {
		static $flipped = null;
		if ( null === $flipped ) {
			$flipped = array_flip( self::SVG_IDS );
		}
		$code = strtoupper( $svg_id );
		if ( 0 === strpos( $code, 'US-' ) ) {
			$code = substr( $code, 3 );
		}
		return $flipped[ $code ] ?? null;
	}

	/**
	 * Look up a display label for a state slug, falling back to a
	 * humanized version of the slug for anything not in the map.
	 *
	 * @param string $slug State slug (e.g. "new-york").
	 * @return string
	 */
	public static function label( string $slug ): string {
		if ( isset( self::MAP[ $slug ] ) ) {
			return self::MAP[ $slug ];
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
