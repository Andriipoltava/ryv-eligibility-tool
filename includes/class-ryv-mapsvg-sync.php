<?php
/**
 * Syncs ryv_states ACF fields (category/color, tooltip, link) to MapSVG so
 * the interactive map reflects the WP admin data.
 *
 * Fires on `acf/save_post` for ryv_states posts.
 *
 * MapSVG 8.x's region table (`{wp_prefix}mapsvg6_regions_{map_id}`) has no
 * per-region color/tooltip column at all - confirmed directly against the
 * real schema on this site (`DESCRIBE`), not assumed. A region only carries
 * an `id`/`title`/`description`/`status`/`status_text`/`link`. The fill
 * color actually comes from the *map's own* `options` JSON
 * (`{wp_prefix}mapsvg6_maps.options`), which defines a small
 * `regionStatuses` array of `{label, value, color, disabled}` - a region's
 * `status` column is just an index into that array. So making a state's
 * category color show up on the map is a two-part write: (1) make sure the
 * map's `regionStatuses` has an entry for that category (creating/updating
 * it as needed), (2) point the region's own `status`/`status_text` at it.
 *
 * `description` is used as this plugin's tooltip text - there's no
 * dedicated tooltip column either.
 *
 * Requires:
 *  - MapSVG plugin active (checks for the tables before writing).
 *  - ACF Pro (for get_field / options page).
 *  - The "Map Settings" ACF options page populated with at least one category
 *    and a valid MapSVG map ID.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_MapSVG_Sync
 */
class RYV_MapSVG_Sync {

	/**
	 * MapSVG table prefix — MapSVG 6.x uses `{wp_prefix}mapsvg6_`.
	 */
	const MAPSVG_TABLE_PREFIX = 'mapsvg6_';

	/**
	 * regionStatuses `value` reserved for RYV categories, so they never
	 * collide with whatever low numbers (0, 1, ...) an admin's own manual
	 * statuses already use in MapSVG's own UI.
	 */
	const STATUS_VALUE_BASE = 100;

	/**
	 * Per-request cache of category-slug => regionStatuses `value`,
	 * keyed by map_id - avoids re-reading/re-writing the map's `options`
	 * JSON once per state on a full sync_all() run.
	 *
	 * @var array<int,array<string,int>>
	 */
	private static $status_value_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'acf/save_post', array( $this, 'sync_to_mapsvg' ), 25 );
	}

	/**
	 * After a ryv_states post is saved, push map-relevant fields to MapSVG.
	 *
	 * @param int|string $post_id Post ID (ACF may also pass 'options').
	 */
	public function sync_to_mapsvg( $post_id ) {
		// ACF fires this for options pages too.
		if ( ! is_numeric( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( get_post_type( $post_id ) !== RYV_States_CPT::POST_TYPE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$state_slug = get_field( 'ryv_state_slug', $post_id );
		if ( ! $state_slug ) {
			return;
		}

		$svg_id = RYV_US_States::svg_id( $state_slug );
		if ( ! $svg_id ) {
			return;
		}

		$map_id = $this->get_map_id();
		if ( ! $map_id ) {
			return;
		}

		$regions_table = $this->get_table( 'regions_' . $map_id );
		if ( ! $regions_table ) {
			return;
		}

		global $wpdb;

		// Only proceed if this region actually exists yet - MapSVG creates
		// region rows itself the first time the SVG is loaded/imported, we
		// never INSERT new ones here.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$regions_table}` WHERE id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$svg_id
			)
		);
		if ( ! $exists ) {
			return;
		}

		$category  = RYV_States_ACF::get_state_categories( $post_id )[0] ?? '';
		$tooltip   = get_field( 'ryv_map_tooltip_text', $post_id ) ?: '';
		$mixed     = count( RYV_States_ACF::get_state_categories( $post_id ) ) > 1;
		$permalink = get_permalink( $post_id );

		$update_data = array(
			'description' => $tooltip,
		);
		$update_format = array( '%s' );

		if ( $category ) {
			$status_value = $this->get_or_create_status_value( $map_id, $category );

			if ( null !== $status_value ) {
				$update_data['status']      = $status_value;
				$update_format[]            = '%d';
				$update_data['status_text'] = RYV_States_ACF::get_category_label( $category ) ?: $category;
				$update_format[]            = '%s';
			}
		}

		if ( $permalink ) {
			$update_data['link'] = $permalink;
			$update_format[]     = '%s';
		}

		$wpdb->update(
			$regions_table,
			$update_data,
			array( 'id' => $svg_id ),
			$update_format,
			array( '%s' )
		);

		/**
		 * Fires after a state's map data has been synced to MapSVG.
		 *
		 * @param int    $post_id  The ryv_states post ID.
		 * @param string $svg_id   The MapSVG region ID (e.g. "TN").
		 * @param string $category The map category slug.
		 * @param bool   $is_mixed Whether the state has mixed rules (stripes) -
		 *                         not yet reflected visually on the map, see
		 *                         this class's docblock.
		 */
		do_action( 'ryv_mapsvg_synced', $post_id, $svg_id, $category, $mixed );
	}

	/**
	 * Find (or create) the regionStatuses `value` a category maps to on a
	 * given map, keeping that entry's label/color in sync with the
	 * Options page on every call - so editing a category's color and
	 * re-syncing actually updates the map, not just newly-added categories.
	 *
	 * @param int    $map_id        MapSVG map ID.
	 * @param string $category_slug Category slug.
	 * @return int|null regionStatuses `value`, or null if the category/map is unknown.
	 */
	private function get_or_create_status_value( int $map_id, string $category_slug ): ?int {
		if ( ! isset( self::$status_value_cache[ $map_id ] ) ) {
			self::$status_value_cache[ $map_id ] = $this->sync_region_statuses( $map_id );
		}

		return self::$status_value_cache[ $map_id ][ $category_slug ] ?? null;
	}

	/**
	 * Reconcile the map's `options.regionStatuses` against
	 * RYV_States_ACF::get_map_categories(): every configured category gets
	 * a stable slot (`value` = STATUS_VALUE_BASE + its position among
	 * categories, stable across runs since that position only changes if
	 * the admin reorders/removes categories on the Options page), with its
	 * label/color kept up to date. Existing non-RYV statuses (anything
	 * below STATUS_VALUE_BASE, e.g. MapSVG's own default Enabled/Disabled)
	 * are left untouched.
	 *
	 * @param int $map_id MapSVG map ID.
	 * @return array<string,int> category slug => regionStatuses value.
	 */
	private function sync_region_statuses( int $map_id ): array {
		$maps_table = $this->get_table( 'maps' );
		if ( ! $maps_table ) {
			return array();
		}

		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT options FROM `{$maps_table}` WHERE id = %d", $map_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( null === $raw ) {
			return array();
		}

		$options = json_decode( $raw, true );
		if ( ! is_array( $options ) ) {
			return array();
		}

		$existing = is_array( $options['regionStatuses'] ?? null ) ? $options['regionStatuses'] : array();

		// MapSVG's own frontend requires `regionStatuses` to be a JS object
		// keyed by each status's own `value` - a JSON *array* gets silently
		// re-keyed by array *position* instead when it loads
		// (`InitGlobals-*.js`: `regionStatuses.forEach((e,i) => t[i]=e)`),
		// which breaks lookup for any value that doesn't happen to equal
		// its position. $existing may currently be either shape (a plain
		// PHP list decoded from a JSON array, or an already-keyed object)
		// depending on what MapSVG itself last wrote - always re-key
		// explicitly by each entry's own `value` rather than trusting
		// whatever shape came in.
		$kept = array();
		foreach ( $existing as $key => $status ) {
			$value = isset( $status['value'] ) ? (string) $status['value'] : (string) $key;
			if ( (int) $value < self::STATUS_VALUE_BASE ) {
				$kept[ $value ] = $status;
			}
		}

		$slug_to_value = array();
		$changed       = false;
		$position      = 0;

		foreach ( RYV_States_ACF::get_map_categories() as $cat ) {
			$slug = $cat['slug'] ?? '';
			if ( '' === $slug ) {
				continue;
			}

			$value                  = self::STATUS_VALUE_BASE + $position;
			$slug_to_value[ $slug ] = $value;
			$position++;

			$kept[ (string) $value ] = array(
				'label'    => $cat['label'] ?? $slug,
				'value'    => (string) $value,
				'color'    => $cat['color'] ?? '',
				'disabled' => false,
			);
			$changed = true;
		}

		if ( ! $changed ) {
			return $slug_to_value;
		}

		$options['regionStatuses'] = $kept;

		$wpdb->update(
			$maps_table,
			array( 'options' => wp_json_encode( $options ) ),
			array( 'id' => $map_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->sync_schema_status_field( $map_id, $kept );

		return $slug_to_value;
	}

	/**
	 * Update the *actual* source MapSVG's frontend renders region fill
	 * colors from: not `maps.options.regionStatuses` (updated above - that
	 * only feeds the admin editor's status dropdown/legend), but the
	 * `status`-type field's own `options` array inside
	 * `{wp_prefix}mapsvg6_schema` (`getRegionStylesByState()` in
	 * MapSVG's own JS reads
	 * `this.regionsRepository.getSchema().getFieldByType("status").options`
	 * directly - confirmed by reading MapSVG's own bundled JS, not
	 * guessed). Same reconciliation approach as the maps-table write
	 * above, kept as a second, parallel write rather than replacing it,
	 * since the maps-table copy still drives MapSVG's own admin UI.
	 *
	 * @param int   $map_id           MapSVG map ID.
	 * @param array $kept_by_value    value(string) => status array, as built for the maps-table write.
	 */
	private function sync_schema_status_field( int $map_id, array $kept_by_value ) {
		$schema_table = $this->get_table( 'schema' );
		if ( ! $schema_table ) {
			return;
		}

		global $wpdb;

		$schema_name = 'regions_' . $map_id;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, fields FROM `{$schema_table}` WHERE name = %s", $schema_name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}

		$fields = json_decode( $row['fields'], true );
		if ( ! is_array( $fields ) ) {
			return;
		}

		$updated = false;

		foreach ( $fields as &$field ) {
			if ( ( $field['type'] ?? '' ) !== 'status' ) {
				continue;
			}

			$existing_options = is_array( $field['options'] ?? null ) ? $field['options'] : array();

			// Schema status options use integer `value`s (confirmed against
			// the actual stored row: {"value":1,...}), unlike the
			// maps-table copy's string values - re-key/rebuild the same
			// way, just with (int) values instead of (string).
			$kept = array();
			foreach ( $existing_options as $opt ) {
				$value = isset( $opt['value'] ) ? (int) $opt['value'] : null;
				if ( null !== $value && $value < self::STATUS_VALUE_BASE ) {
					$kept[ $value ] = $opt;
				}
			}

			foreach ( $kept_by_value as $status ) {
				$value           = (int) $status['value'];
				$kept[ $value ]  = array(
					'label'    => $status['label'],
					'value'    => $value,
					'disabled' => false,
					'color'    => $status['color'],
				);
			}

			$field['options'] = array_values( $kept );
			$updated           = true;
		}
		unset( $field );

		if ( ! $updated ) {
			return;
		}

		$wpdb->update(
			$schema_table,
			array( 'fields' => wp_json_encode( $fields ) ),
			array( 'id' => $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get the MapSVG map ID from the ACF options page.
	 *
	 * @return int|null
	 */
	private function get_map_id(): ?int {
		$map_id = get_field( 'ryv_mapsvg_map_id', 'option' );
		return $map_id ? (int) $map_id : null;
	}

	/**
	 * Build a full MapSVG table name and verify it exists.
	 *
	 * @param string $suffix Table name suffix, e.g. "maps" or "regions_1".
	 * @return string|null Full table name or null if it doesn't exist.
	 */
	private function get_table( string $suffix ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . self::MAPSVG_TABLE_PREFIX . $suffix;

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return $exists ? $table : null;
	}

	/**
	 * Bulk sync: push all published ryv_states to MapSVG.
	 *
	 * Intended for CLI / one-off admin use after initial data entry or
	 * after changing category colors on the options page (also exposed as
	 * a "Sync Category Colors to Map" button on the Generate States admin
	 * page - see RYV_States_Generate_Page).
	 *
	 * There is only ever one MapSVG region row per US state
	 * (`sync_to_mapsvg()`'s `$svg_id` is the same 2-letter code regardless
	 * of which language's `ryv_states` post produced it - the map itself
	 * has no per-language variant), so this deliberately does NOT pass
	 * `'lang' => 'all'` the way RYV_States_Generator's queries do: `get_posts()`
	 * here stays scoped to whichever language is currently active in the
	 * WPML admin-language switcher, so an admin working in the Spanish
	 * content language and clicking "Sync Category Colors to Map" pushes
	 * only the Spanish states' tooltip/category text onto the shared map,
	 * without an immediately-following English-language sync silently
	 * overwriting it back (or vice versa) - switch the admin language
	 * before running this to control which language's copy "wins" the map.
	 *
	 * @return int Number of states synced.
	 */
	public static function sync_all(): int {
		$posts = get_posts( array(
			'post_type'      => RYV_States_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$instance = new self();
		$count    = 0;

		// Temporarily remove the hook to avoid recursion — we're calling
		// sync_to_mapsvg() directly.
		remove_action( 'acf/save_post', array( $instance, 'sync_to_mapsvg' ), 25 );

		foreach ( $posts as $pid ) {
			$instance->sync_to_mapsvg( $pid );
			$count++;
		}

		return $count;
	}
}
