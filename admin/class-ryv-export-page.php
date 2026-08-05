<?php
/**
 * "Export YAML" admin action: streams a `ryv_forms` post's title,
 * description, and node graph as a downloadable YAML file - the inverse
 * direction of RYV_Import_Page, but a clean native RYV schema dump rather
 * than a Drupal-webform-shaped file (reversing the whole flatten()/
 * build_subtree() translation back into per-state #states-gated containers
 * is a different, much larger undertaking than "export the current
 * schema" - see class docblock note below for why this one stays one-way).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Symfony\Component\Yaml\Yaml;

/**
 * Class RYV_Export_Page
 *
 * No separate admin page of its own - just a row action on the `ryv_forms`
 * list table (edit.php?post_type=ryv_forms) plus a matching button in the
 * builder SPA (see class-ryv-builder-page.php's `exportUrl` localization),
 * both pointing at the same nonced `admin.php?action=ryv_export_yaml`
 * link. Uses core's `admin_action_{$action}` hook - the same "click a
 * link, stream a file, exit" mechanism WordPress's own list-table row
 * actions use - rather than a bespoke admin_init check.
 */
class RYV_Export_Page {

	/**
	 * Capability required to export - matches RYV_Import_Page::CAPABILITY
	 * and RYV_Builder_Page::CAPABILITY (same trust boundary: a form's
	 * `markup` nodes carry raw HTML, and the export would hand a copy of it
	 * to whoever can trigger this).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * `admin_action_{ACTION}` hook suffix / the `action` query arg.
	 */
	const ACTION = 'ryv_export_yaml';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_action_' . self::ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Build the nonced export URL for one form - shared by the list-table
	 * row action below and RYV_Builder_Page's localized settings, so both
	 * entry points ("management screen" and "editor", per the feature
	 * request) use the exact same link.
	 *
	 * @param int $post_id Form post ID.
	 * @return string
	 */
	public static function export_url( int $post_id ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?action=' . self::ACTION . '&post=' . $post_id ),
			self::ACTION . '_' . $post_id
		);
	}

	/**
	 * Add an "Export YAML" row action, right after "Edit", to every
	 * `ryv_forms` row in its list table.
	 *
	 * @param array<string,string> $actions Existing action id => HTML link map.
	 * @param WP_Post               $post   Row's post.
	 * @return array<string,string>
	 */
	public function add_row_action( array $actions, WP_Post $post ): array {
		if ( RYV_CPT::POST_TYPE !== $post->post_type || ! current_user_can( self::CAPABILITY ) ) {
			return $actions;
		}

		$new = array();

		foreach ( $actions as $key => $link ) {
			$new[ $key ] = $link;

			if ( 'edit' === $key ) {
				$new['ryv_export_yaml'] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( self::export_url( $post->ID ) ),
					esc_html__( 'Export YAML', 'ryv-eligibility-tool' )
				);
			}
		}

		return $new;
	}

	/**
	 * `admin_action_ryv_export_yaml` handler: validate, then stream the
	 * file and exit - runs on `wp-admin/admin.php` before any page HTML
	 * would otherwise be sent, same as the CSV export in
	 * RYV_Submissions_Page.
	 */
	public function handle_export() {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Form not found.', 'ryv-eligibility-tool' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to export this form.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::ACTION . '_' . $post_id );

		if ( ! class_exists( Yaml::class ) ) {
			wp_die( esc_html__( 'Symfony Yaml component is not available. Run "composer install" in the plugin root.', 'ryv-eligibility-tool' ) );
		}

		$this->stream( $post_id, $post );
	}

	/**
	 * Build the export array and stream it as a `.yml` download.
	 *
	 * @param int     $post_id Form post ID.
	 * @param WP_Post $post    Form post.
	 */
	private function stream( int $post_id, WP_Post $post ): void {
		$schema = RYV_CPT::get_schema( $post_id );

		// Native RYV schema shape, not Drupal's #-prefixed element-tree
		// format - the exact same {title, description, entry_node, nodes}
		// shape the builder SPA round-trips already (see
		// RYV_Admin_API/RYV_CPT::get_schema()), just YAML instead of JSON.
		$export = array(
			'title'       => $post->post_title,
			'description' => $post->post_excerpt,
			'entry_node'  => $schema['entry_node'],
			'nodes'       => $schema['nodes'],
		);

		// Inline level 8: block (multi-line, readable) style through
		// node -> options -> option, only collapsing to inline flow style
		// past that depth (nothing in this schema nests that deep) -
		// avoids the unreadable single-line-per-node output Yaml::dump()'s
		// low default inline level would otherwise produce for a graph
		// this shape.
		$yaml = Yaml::dump( $export, 8, 2 );

		nocache_headers();
		header( 'Content-Type: text/yaml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="form-' . $post_id . '-export.yml"' );
		echo $yaml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw YAML file download, not HTML.
		exit;
	}
}
