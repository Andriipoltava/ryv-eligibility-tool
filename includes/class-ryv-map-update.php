<?php
/**
 * One-click "Map Update": brings an environment (staging/production) to the
 * current state-map configuration, so none of it has to be redone by hand.
 *
 * Three things, none of which travel with a deploy on their own:
 *
 * 1. **Categories** - the options-page repeater gets the 9 current
 *    categories/colors (RYV_States_ACF::DEFAULT_MAP_CATEGORIES) and loses the
 *    retired ones. Done by RYV_States_ACF::reconcile_map_categories().
 * 2. **States** - every state still holding a retired category slug is moved
 *    to its replacement, plus its secondary (striped) category where known,
 *    and the retired single-value category fields are folded into the one
 *    multi-select (`ryv_map_category_list`). A state already on current
 *    categories keeps them, so re-running never overwrites an editor's choice.
 * 3. **Popup text sizes** - the click popup's title/description/button sizes
 *    live in each Elementor widget's saved settings (`_elementor_data`), not
 *    in code, so they are written into every map widget found.
 *
 * Idempotent: safe to run any number of times.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Map_Update
 */
class RYV_Map_Update {

	/**
	 * Popup text sizes (px) (the legend swatch size is set alongside them), per the client's brief: title 18, description 14,
	 * "Learn More" button 16. Applied to desktop, tablet and mobile alike - the
	 * widgets previously carried smaller tablet/mobile overrides that would
	 * otherwise keep winning on those breakpoints.
	 */
	const POPUP_SIZES = array(
		'popup_title_typography'       => 18,
		'popup_description_typography' => 14,
		'popup_link_typography'        => 16,
	);

	/**
	 * Retired slug => replacement, used only for a state the generator's own
	 * per-state tables (RYV_States_Generator::CLC_CATEGORY_STATES) don't list.
	 */
	const LEGACY_REPLACEMENTS = array(
		'no-restrictions'                   => 'voting-rights-not-lost',
		'prison-only'                       => 'lost-only-during-incarceration',
		'prison-parole-probation'           => 'restored-after-sentence-completion',
		'prison-parole-probation-lfos'      => 'restored-after-sentence-completion',
		'completion-plus-requirements'      => 'documents-or-certificate-required',
		'permanent-multiple-felonies'       => 'permanent-disenfranchisement',
		'streamlined-executive-restoration' => 'discretionary-action',
	);

	/**
	 * Run everything.
	 *
	 * @param bool $dry_run Report what would change without writing.
	 * @return array
	 */
	public static function run( bool $dry_run = false ): array {
		$result = array(
			'dry_run' => $dry_run,
			'states'  => array(),
			'popups'  => array(),
			'synced'  => 0,
		);

		if ( ! $dry_run && class_exists( 'RYV_States_ACF' ) ) {
			// Categories first: reconcile also drops the retired rows. Not
			// maybe_seed_map_defaults( true ) - its force mode would re-assign
			// every state from the reference table and overwrite editors' choices.
			RYV_States_ACF::reconcile_map_categories();
		}

		$result['states'] = self::migrate_state_categories( $dry_run );
		$result['popups'] = self::apply_popup_sizes( $dry_run );

		if ( ! $dry_run ) {
			// States with no category at all get the reference default; never overwrites.
			RYV_States_Generator::assign_default_map_categories( false );

			if ( class_exists( 'RYV_MapSVG_Sync' ) ) {
				$result['synced'] = RYV_MapSVG_Sync::sync_all();
			}
		}

		return $result;
	}

	/**
	 * Move every state off a retired category.
	 *
	 * @param bool $dry_run Report only.
	 * @return array<int,array{post_id:int,state:string,from:string,to:string,secondary:string}>
	 */
	public static function migrate_state_categories( bool $dry_run = false ): array {
		if ( ! function_exists( 'get_field' ) || ! class_exists( 'RYV_States_Generator' ) ) {
			return array();
		}

		$slug_to_category = array();
		foreach ( RYV_States_Generator::CLC_CATEGORY_STATES as $category => $state_slugs ) {
			foreach ( $state_slugs as $state_slug ) {
				$slug_to_category[ $state_slug ] = $category;
			}
		}

		$posts = get_posts( array(
			'post_type'      => RYV_States_CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => 'all',
		) );

		$changes = array();

		foreach ( $posts as $post_id ) {
			$stored = RYV_States_ACF::get_state_categories( $post_id );
			if ( empty( $stored ) ) {
				continue;
			}

			$state_slug = (string) get_field( 'ryv_state_slug', $post_id );
			$canonical  = $state_slug ? RYV_States_Generator::resolve_canonical_state_slug( $state_slug ) : null;

			// Retired slugs are replaced (per-state table first, generic
			// replacement as the fallback). A state already on current
			// categories is only re-saved to fold the two retired single-value
			// fields into the one multi-select.
			$new = array();
			if ( array_intersect( $stored, RYV_States_ACF::LEGACY_MAP_CATEGORIES ) ) {
				$primary = ( $canonical && isset( $slug_to_category[ $canonical ] ) )
					? $slug_to_category[ $canonical ]
					: ( self::LEGACY_REPLACEMENTS[ $stored[0] ] ?? $stored[0] );
				$new     = array( $primary, $canonical ? ( RYV_States_Generator::CLC_SECONDARY_CATEGORY_STATES[ $canonical ] ?? '' ) : '' );
			} else {
				$new = $stored;
			}
			$new = array_slice( array_values( array_unique( array_filter( $new ) ) ), 0, 5 );

			// Raw meta, not get_field(): on a translation, an empty field reads
			// through to the original-language post (RYV_States_I18n), which
			// would make an unmigrated translation look already done.
			$in_list = get_post_meta( $post_id, 'ryv_map_category_list', true );
			$in_list = is_array( $in_list ) ? array_values( $in_list ) : array();

			if ( $new === $in_list ) {
				continue;
			}

			$changes[] = array(
				'post_id'   => (int) $post_id,
				'state'     => $state_slug,
				'from'      => implode( ' + ', $stored ),
				'to'        => $new[0] ?? '',
				'secondary' => $new[1] ?? '',
			);

			if ( ! $dry_run ) {
				RYV_States_ACF::set_state_categories( $post_id, $new );
			}
		}

		return $changes;
	}

	/**
	 * Write the popup text sizes into every Elementor document that has the
	 * map widget.
	 *
	 * @param bool $dry_run Report only.
	 * @return array<int,array{post_id:int,title:string,widgets:int}>
	 */
	public static function apply_popup_sizes( bool $dry_run = false ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_elementor_data'
			   AND pm.meta_value LIKE '%ryv_state_map%'
			   AND p.post_type <> 'revision'
			   AND p.post_status <> 'trash'"
		);

		$report  = array();
		$changed = false;

		foreach ( (array) $rows as $row ) {
			$data = json_decode( $row->meta_value, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$widgets = self::set_popup_sizes( $data );
			if ( ! $widgets ) {
				continue;
			}

			$report[] = array(
				'post_id' => (int) $row->post_id,
				'title'   => get_the_title( (int) $row->post_id ),
				'widgets' => $widgets,
			);

			if ( ! $dry_run ) {
				update_post_meta( (int) $row->post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
				$changed = true;
			}
		}

		if ( $changed && class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return $report;
	}

	/**
	 * Recursively set the sizes on every map widget in an element tree.
	 *
	 * @param array $elements Element tree (modified in place).
	 * @return int Widgets whose settings actually changed.
	 */
	private static function set_popup_sizes( array &$elements ): int {
		$count = 0;

		foreach ( $elements as &$element ) {
			if ( ( $element['widgetType'] ?? '' ) === 'ryv_state_map' ) {
				$settings = $element['settings'] ?? array();
				$before   = $settings;

				foreach ( self::POPUP_SIZES as $prefix => $size ) {
					$settings[ $prefix . '_typography' ]             = 'custom';
					$settings[ $prefix . '_font_size' ]              = array( 'unit' => 'px', 'size' => $size, 'sizes' => array() );
					$settings[ $prefix . '_font_size_tablet' ]       = array( 'unit' => 'px', 'size' => $size );
					$settings[ $prefix . '_font_size_mobile' ]       = array( 'unit' => 'px', 'size' => $size );
				}

				// Legend swatches: a little larger than before (was 15px).
				$settings['legend_swatch_size'] = array( 'unit' => 'px', 'size' => 20, 'sizes' => array() );

				// A typography bound to a Global Style (`__globals__`) takes its
				// size from that style and ignores the widget's own value, so the
				// binding is cleared (an empty string is how Elementor stores
				// "not bound"). The widget's own font family/weight stay.
				foreach ( array_keys( self::POPUP_SIZES ) as $prefix ) {
					if ( ! empty( $settings['__globals__'][ $prefix . '_typography' ] ) ) {
						$settings['__globals__'][ $prefix . '_typography' ] = '';
					}
				}

				if ( $settings !== $before ) {
					$element['settings'] = $settings;
					$count++;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::set_popup_sizes( $element['elements'] );
			}
		}
		unset( $element );

		return $count;
	}
}
