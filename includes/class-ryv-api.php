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
 * Two read-only routes:
 *  - GET /ryv/v1/form/{post_id}            -> which states have logic on this form (populates the initial state picker)
 *  - GET /ryv/v1/form/{post_id}/state/{id} -> that state's node graph (fetched lazily, only after a state is chosen)
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

		register_rest_route(
			self::NAMESPACE,
			'/form/(?P<post_id>\d+)/state/(?P<state_id>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form_state' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id'  => array(
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value );
						},
					),
					'state_id' => array(
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== $value;
						},
					),
				),
			)
		);
	}

	/**
	 * GET /form/{post_id} - list of states with logic on this form.
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

		$states = $this->get_states_meta( $post_id );

		$out = array();
		foreach ( $states as $state_id => $state_data ) {
			$label = ! empty( $state_data['label'] ) ? (string) $state_data['label'] : RYV_US_States::label( $state_id );

			$out[] = array(
				'id'    => $state_id,
				'label' => $label,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return new WP_REST_Response(
			array(
				'form_id' => $post_id,
				'title'   => $post->post_title,
				'states'  => $out,
			),
			200
		);
	}

	/**
	 * GET /form/{post_id}/state/{state_id} - the node graph for one state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_form_state( WP_REST_Request $request ) {
		$post_id  = (int) $request['post_id'];
		$state_id = sanitize_title( (string) $request['state_id'] );

		$post = $this->get_viewable_form( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		$states = $this->get_states_meta( $post_id );

		if ( ! isset( $states[ $state_id ] ) ) {
			return new WP_Error(
				'ryv_state_not_found',
				__( 'No questionnaire is configured for this state.', 'ryv-eligibility-tool' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $states[ $state_id ], 200 );
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
	 * Decode the `states` map out of a form's schema postmeta.
	 *
	 * @param int $post_id Post ID.
	 * @return array state_id => state graph.
	 */
	private function get_states_meta( int $post_id ): array {
		$json   = get_post_meta( $post_id, RYV_CPT::SCHEMA_META_KEY, true );
		$schema = json_decode( (string) $json, true );

		if ( ! is_array( $schema ) || empty( $schema['states'] ) || ! is_array( $schema['states'] ) ) {
			return array();
		}

		return $schema['states'];
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
