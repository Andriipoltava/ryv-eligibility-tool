<?php
/**
 * REST API surface consumed by the frontend decision engine.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_API
 *
 * One read-only route:
 *  - GET /ryv/v1/form/{post_id} -> the entire flat decision graph
 *    ({ form_id, title, start_node, nodes }), served whole. There is no
 *    per-state lazy-loading anymore - the graph is agnostic to what the
 *    first node happens to branch on (state, category, anything else),
 *    and is small enough to ship in one response.
 */
class RYV_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'ryv/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/form/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * GET /form/{post_id} - the full flat decision graph for this form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_form( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = $this->get_viewable_form( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		$schema = $this->get_schema_meta( $post_id );

		return new WP_REST_Response(
			array(
				'form_id'    => $post_id,
				'title'      => $post->post_title,
				'start_node' => $schema['start_node'],
				'nodes'      => $schema['nodes'],
			),
			200
		);
	}

	/**
	 * Fetch a `ryv_forms` post, if it exists and is viewable by the current
	 * requester (published, or a draft the requester can edit - so editors
	 * can preview an in-progress form through the widget).
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	private function get_viewable_form( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Decode a form's schema postmeta into its `start_node`/`nodes` pair.
	 *
	 * Tolerant of a missing/malformed blob (unsaved new form) - returns an
	 * empty graph rather than erroring, so the frontend can render an empty
	 * state instead of treating "no schema yet" as a 404.
	 *
	 * @param int $post_id Post ID.
	 * @return array{start_node: ?string, nodes: array}
	 */
	private function get_schema_meta( int $post_id ): array {
		$json   = get_post_meta( $post_id, RYV_CPT::SCHEMA_META_KEY, true );
		$schema = json_decode( (string) $json, true );

		if ( ! is_array( $schema ) ) {
			$schema = array();
		}

		$start_node = ! empty( $schema['start_node'] ) && is_string( $schema['start_node'] )
			? $schema['start_node']
			: null;

		$nodes = ! empty( $schema['nodes'] ) && is_array( $schema['nodes'] )
			? $schema['nodes']
			: array();

		return array(
			'start_node' => $start_node,
			'nodes'      => $nodes,
		);
	}

	/**
	 * Shared 404 response.
	 *
	 * @return WP_Error
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'ryv_form_not_found',
			__( 'Form not found.', 'ryv-eligibility-tool' ),
			array( 'status' => 404 )
		);
	}
}