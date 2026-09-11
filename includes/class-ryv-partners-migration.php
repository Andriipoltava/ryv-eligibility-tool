<?php
/**
 * One-time data migration: copies each `ryv_states` post's legacy
 * single-partner fields (`ryv_partner_name`/`_link_url`/`_location`/
 * `_description`/`_quote`) into row 1 of the new `featured_partners`
 * repeater (RYV_States_ACF::register_partner_highlight_group()) that the
 * reworked Featured Partner section actually renders
 * (RYV_Featured_Partners_Renderer). Carrying all five fields, not just
 * name/url, is what keeps a migrated state's card rendering identically to
 * how its legacy-fallback rendering looked the moment before - those same
 * fields were always "this one partner's" content, just not in a repeater.
 *
 * Investigated against the live data before writing this (see the project
 * report): the old "small thumbnail" next to the partner name was never a
 * separate attachment - both the big photo and the thumbnail widgets in the
 * Elementor template are bound to the exact same `ryv_partner_image` field.
 * There is therefore nothing valid to migrate into the new repeater's
 * `logo` sub-field for any existing post - it is always left empty here, by
 * design, not by omission. Content editors add real per-partner logos by
 * hand afterward.
 *
 * Deliberately its own file/class, entirely separate from the ACF field
 * registration and the shortcode that renders the new fields - both of
 * those are permanent, this is not. Delete this file, its WP-CLI command
 * (class-ryv-partners-migration-cli.php) and its admin fallback page
 * (admin/class-ryv-partners-migration-page.php) together once every state
 * has been migrated and QA'd; nothing else in the plugin depends on any of
 * the three.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Partners_Migration
 */
class RYV_Partners_Migration {

	/**
	 * Migrate every `ryv_states` post (or just `$only_post_id`, if given).
	 *
	 * @param bool     $dry_run      True: report only, write nothing.
	 * @param int|null $only_post_id Limit to one post.
	 * @return array{rows: array<int, array{id:int,title:string,status:string,detail:string}>, totals: array<string,int>}
	 */
	public function run( bool $dry_run = true, ?int $only_post_id = null ): array {
		$args = array(
			'post_type'        => class_exists( 'RYV_States_CPT' ) ? RYV_States_CPT::POST_TYPE : 'ryv_states',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		);

		if ( $only_post_id ) {
			$args['include'] = array( $only_post_id );
		}

		$post_ids = get_posts( $args );

		$rows   = array();
		$totals = array(
			'migrated'             => 0,
			'migrated_needs_split' => 0,
			'skipped_has_rows'     => 0,
			'skipped_no_data'      => 0,
			'skipped_no_acf'       => 0,
		);

		foreach ( $post_ids as $post_id ) {
			$row              = $this->migrate_post( (int) $post_id, $dry_run );
			$rows[]           = $row;
			$totals[ $row['status'] ] = ( $totals[ $row['status'] ] ?? 0 ) + 1;
		}

		return array(
			'rows'   => $rows,
			'totals' => $totals,
		);
	}

	/**
	 * @param int  $post_id
	 * @param bool $dry_run
	 * @return array{id:int,title:string,status:string,detail:string}
	 */
	public function migrate_post( int $post_id, bool $dry_run ): array {
		$title = get_the_title( $post_id );

		if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			return $this->row( $post_id, $title, 'skipped_no_acf', 'ACF Pro is not active.' );
		}

		// The section's own "Main Image" is a separate, independently
		// idempotent copy from the partner-row migration below (it has to
		// run even for a post that skips the row migration - either because
		// its repeater already has rows, or because it never had a legacy
		// partner name to begin with, both cases where the image can still
		// be genuinely missing). See RYV_Tag_Featured_Partners_Image's own
		// docblock for why the template needs this field to exist at all.
		$image_note = $this->maybe_migrate_image( $post_id, $dry_run );

		// Idempotent: never overwrite content the team has already entered,
		// whether that came from an earlier run of this migration or from
		// an editor filling the new repeater in by hand before this ran.
		$existing = get_field( 'featured_partners', $post_id );
		if ( is_array( $existing ) && count( $existing ) > 0 ) {
			return $this->row( $post_id, $title, 'skipped_has_rows', sprintf( 'Repeater already has %d row(s).', count( $existing ) ) . $image_note );
		}

		$legacy_name = trim( (string) get_field( 'ryv_partner_name', $post_id ) );
		if ( '' === $legacy_name ) {
			return $this->row( $post_id, $title, 'skipped_no_data', 'ryv_partner_name is empty - nothing to migrate.' . $image_note );
		}

		$legacy_url         = trim( (string) get_field( 'ryv_partner_link_url', $post_id ) );
		$legacy_location    = trim( (string) get_field( 'ryv_partner_location', $post_id ) );
		$legacy_description = trim( (string) get_field( 'ryv_partner_description', $post_id ) );
		$legacy_quote       = trim( (string) get_field( 'ryv_partner_quote', $post_id ) );

		// A combined title like "ABLE, LWVKY, KFTC" is migrated as-is, as
		// one row - see class docblock - and flagged here rather than
		// auto-split, which would require guessing where one partner's name
		// ends and the next begins (parenthetical abbreviations, "and",
		// ampersands, ...) with no reliable rule across all 50 states' data.
		$needs_split = ( false !== strpos( $legacy_name, ',' ) );

		if ( ! $dry_run ) {
			// Field KEYS (not names) throughout, including each repeater
			// row's own sub-field keys - required for update_field() to
			// work on a post where this repeater has never been saved
			// before (there is no existing row structure for ACF to infer
			// field keys from otherwise). Location/description/quote come
			// along too - RYV_Featured_Partners_Renderer's legacy fallback
			// already showed these as this one partner's own content, so
			// carrying them into the migrated row is what keeps the section
			// rendering identically before and after migration, not a new
			// addition of content that wasn't there.
			update_field(
				'field_ryv_featured_partners',
				array(
					array(
						'field_ryv_featured_partner_logo'        => null, // Always empty - see class docblock.
						'field_ryv_featured_partner_name'        => $legacy_name,
						'field_ryv_featured_partner_url'         => $legacy_url,
						'field_ryv_featured_partner_location'    => $legacy_location,
						'field_ryv_featured_partner_description' => $legacy_description,
						'field_ryv_featured_partner_quote'       => $legacy_quote,
					),
				),
				$post_id
			);
		}

		$detail = $needs_split
			? sprintf( 'Migrated combined name as one row: "%s" - needs manual split into separate partners.', $legacy_name )
			: sprintf( 'Migrated "%s"%s.', $legacy_name, '' !== $legacy_url ? " (url: {$legacy_url})" : ' (no url)' );

		return $this->row( $post_id, $title, $needs_split ? 'migrated_needs_split' : 'migrated', $detail . $image_note );
	}

	/**
	 * Copies the legacy `ryv_partner_image` attachment into the new,
	 * section-level `featured_partners_image` field, but only when that new
	 * field is still empty - same idempotent, non-destructive shape as the
	 * partner-row migration, just for one image field instead of a repeater.
	 *
	 * @param int  $post_id
	 * @param bool $dry_run
	 * @return string A short note to append to the row's detail message -
	 *                 empty string when there was nothing to do.
	 */
	private function maybe_migrate_image( int $post_id, bool $dry_run ): string {
		$existing = (int) get_field( 'featured_partners_image', $post_id );
		if ( $existing ) {
			return '';
		}

		$legacy = get_field( 'ryv_partner_image', $post_id );
		$legacy_id = ( is_array( $legacy ) && ! empty( $legacy['ID'] ) ) ? (int) $legacy['ID'] : 0;

		if ( ! $legacy_id ) {
			return '';
		}

		if ( ! $dry_run ) {
			update_field( 'field_ryv_featured_partners_image', $legacy_id, $post_id );
		}

		return ' Main Image copied from the legacy Image/Video Thumbnail field.';
	}

	/**
	 * @param int    $post_id
	 * @param string $title
	 * @param string $status
	 * @param string $detail
	 * @return array{id:int,title:string,status:string,detail:string}
	 */
	private function row( int $post_id, string $title, string $status, string $detail ): array {
		return array(
			'id'     => $post_id,
			'title'  => $title,
			'status' => $status,
			'detail' => $detail,
		);
	}

	/**
	 * Human-readable label for a row's `status`, shared by the WP-CLI
	 * command and the admin fallback page so the two never drift.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'migrated':
				return 'migrated';
			case 'migrated_needs_split':
				return 'migrated - NEEDS MANUAL SPLIT';
			case 'skipped_has_rows':
				return 'skipped: already has rows';
			case 'skipped_no_data':
				return 'skipped: no old data';
			case 'skipped_no_acf':
				return 'skipped: ACF unavailable';
			default:
				return $status;
		}
	}
}
