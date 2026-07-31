<?php
/**
 * Admin-only REST surface for the form builder SPA: read/write the whole
 * `_ryv_form_schema` blob for one post. Distinct from RYV_API (public,
 * read-only, one state at a time) - this is auth-gated and round-trips the
 * entire schema so the builder can hold it in memory and save it back.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Admin_API
 */
class RYV_Admin_API {

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
			'/admin/form/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_form' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_form' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'schema' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);
	}

	/**
	 * Only someone who can edit this specific post may read/write its schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_permission( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * GET /admin/form/{post_id} - the post's title/description + full decoded schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_form( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = $this->get_form_post( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		return new WP_REST_Response(
			array(
				'post_id'     => $post_id,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'status'      => $post->post_status,
				'schema'      => RYV_CPT::get_schema( $post_id ),
			),
			200
		);
	}

	/**
	 * POST /admin/form/{post_id} - replace the whole schema.
	 *
	 * Only checks top-level shape ({"nodes": {...}}); the builder UI itself
	 * is what keeps node references sane (next_node dropdowns are only ever
	 * populated from real node ids), so there's no separate semantic
	 * validation to duplicate here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_form( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = $this->get_form_post( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		$schema = $request->get_param( 'schema' );

		if ( ! is_array( $schema ) || ! isset( $schema['nodes'] ) || ! is_array( $schema['nodes'] ) ) {
			return new WP_Error(
				'ryv_invalid_schema',
				__( 'Schema must be an object with a "nodes" object.', 'ryv-eligibility-tool' ),
				array( 'status' => 400 )
			);
		}

		// Captured before the write below, so the diff reflects what this save
		// actually changed rather than comparing the new schema to itself.
		$old_schema = RYV_CPT::get_schema( $post_id );

		RYV_CPT::save_schema( $post_id, $schema );

		RYV_Edit_Log::record( $post_id, get_current_user_id(), RYV_Schema_Diff::compute( $old_schema, $schema ) );

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'schema'  => $schema,
			),
			200
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	private function get_form_post( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );

		if ( ! $post || RYV_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $post;
	}

	/**
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
