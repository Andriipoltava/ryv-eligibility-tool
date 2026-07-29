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
