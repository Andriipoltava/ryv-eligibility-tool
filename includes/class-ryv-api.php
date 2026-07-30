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
 * Two read-only routes, plus one write route for anonymous progress logging:
 *  - GET  /ryv/v1/form/{post_id}            -> which states have logic on this form (populates the initial state picker)
 *  - GET  /ryv/v1/form/{post_id}/state/{id} -> that state's node graph (fetched lazily, only after a state is chosen)
 *  - POST /ryv/v1/form/{post_id}/log        -> upsert this visitor's answer trail so far (see RYV_Submission_Log)
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
			'/form/(?P<post_id>\d+)/log',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_progress' ),
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
				'form_id'             => $post_id,
				'title'               => $post->post_title,
				'states'              => $out,
				'state_display_style' => $this->get_state_display_style( $post_id ),
				'state_picker_label'  => $this->get_state_picker_label( $post_id ),
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
	 * POST /form/{post_id}/log - upsert this visitor's answer trail so far.
	 *
	 * No auth, no nonce: anonymous visitors have neither, and this only ever
	 * writes an anonymous, session-scoped row (no IP or other identifying
	 * info is accepted or stored) - see RYV_Submission_Log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function log_progress( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = $this->get_viewable_form( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		$session_id = (string) $request->get_param( 'session_id' );
		$session_id = substr( preg_replace( '/[^a-zA-Z0-9-]/', '', $session_id ), 0, 64 );

		if ( '' === $session_id ) {
			return new WP_Error(
				'ryv_invalid_session',
				__( 'A session id is required.', 'ryv-eligibility-tool' ),
				array( 'status' => 400 )
			);
		}

		$state_id_param = $request->get_param( 'state_id' );
		$state_id       = is_string( $state_id_param ) && '' !== $state_id_param ? sanitize_title( $state_id_param ) : null;

		$current_node_param = $request->get_param( 'current_node' );
		$current_node       = is_string( $current_node_param ) && '' !== $current_node_param
			? substr( sanitize_text_field( $current_node_param ), 0, 191 )
			: null;

		$answers_param = $request->get_param( 'answers' );
		$answers       = is_array( $answers_param ) ? $answers_param : array();

		RYV_Submission_Log::record( $post_id, $session_id, $state_id, $current_node, $answers );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
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
	 * How the state picker should render its options: 'buttons' (default,
	 * unchanged look), 'radio', or 'dropdown'. Lives as a schema-level
	 * sibling of `states` rather than per-state, since it's one picker
	 * shared across every state on the form.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_state_display_style( int $post_id ): string {
		$json   = get_post_meta( $post_id, RYV_CPT::SCHEMA_META_KEY, true );
		$schema = json_decode( (string) $json, true );

		$allowed = array( 'buttons', 'radio', 'dropdown' );
		$style   = is_array( $schema ) && ! empty( $schema['state_display_style'] ) ? $schema['state_display_style'] : 'buttons';

		return in_array( $style, $allowed, true ) ? $style : 'buttons';
	}

	/**
	 * The state picker's title, e.g. "Select your state" - editable per-form
	 * in the builder (schema-level, sibling of `states`) so a form whose
	 * first choice isn't really "a state" isn't stuck with that wording.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_state_picker_label( int $post_id ): string {
		$json   = get_post_meta( $post_id, RYV_CPT::SCHEMA_META_KEY, true );
		$schema = json_decode( (string) $json, true );

		$label = is_array( $schema ) && ! empty( $schema['state_picker_label'] ) && is_string( $schema['state_picker_label'] )
			? $schema['state_picker_label']
			: __( 'Select your state', 'ryv-eligibility-tool' );

		return $label;
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
