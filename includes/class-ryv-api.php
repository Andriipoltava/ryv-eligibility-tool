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
 * One read-only route, plus two write routes:
 *  - GET  /ryv/v1/form/{post_id} -> the whole flat decision graph in one shot
 *  - POST /ryv/v1/form/{post_id}/log -> upsert this visitor's answer trail so far (see RYV_Submission_Log)
 *  - POST /ryv/v1/form/{post_id}/submit -> submit a `lead_capture` node (see RYV_Leads)
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
			'/form/(?P<post_id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_lead' ),
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
	 * GET /form/{post_id} - the whole flat decision graph in one response.
	 * No more lazy per-state fetch: with a single universal DAG, there is
	 * no natural "fetch just the picker, then fetch the rest" boundary
	 * left to preserve.
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

		$schema = RYV_CPT::get_schema( $post_id );

		return new WP_REST_Response(
			array(
				'form_id'     => $post_id,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'entry_node'  => $schema['entry_node'],
				'nodes'       => $schema['nodes'],
			),
			200
		);
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
	 * POST /form/{post_id}/submit - submit a `lead_capture` node.
	 *
	 * No auth, no nonce, same as log_progress() above: this is the public
	 * questionnaire's own form submission, not an authenticated action. The
	 * node's `actions` config (what to actually do with the submission) is
	 * read straight from the schema rather than trusted from the request -
	 * only `node_id`/`name`/`email`/`session_id` come from the client, all
	 * sanitized before use.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_lead( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$post    = $this->get_viewable_form( $post_id );

		if ( ! $post ) {
			return $this->not_found_error();
		}

		$node_id = sanitize_text_field( (string) $request->get_param( 'node_id' ) );

		if ( '' === $node_id ) {
			return new WP_Error(
				'ryv_invalid_node',
				__( 'A node id is required.', 'ryv-eligibility-tool' ),
				array( 'status' => 400 )
			);
		}

		$schema = RYV_CPT::get_schema( $post_id );
		$node   = isset( $schema['nodes'][ $node_id ] ) ? $schema['nodes'][ $node_id ] : null;

		if ( ! is_array( $node ) || 'lead_capture' !== ( $node['type'] ?? '' ) ) {
			return new WP_Error(
				'ryv_invalid_node',
				__( 'This step is not a form submission step.', 'ryv-eligibility-tool' ),
				array( 'status' => 400 )
			);
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'ryv_invalid_email',
				__( 'A valid email address is required.', 'ryv-eligibility-tool' ),
				array( 'status' => 400 )
			);
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		$session_id_param = $request->get_param( 'session_id' );
		$session_id        = is_string( $session_id_param ) && '' !== $session_id_param
			? substr( preg_replace( '/[^a-zA-Z0-9-]/', '', $session_id_param ), 0, 64 )
			: null;

		$actions = is_array( $node['actions'] ?? null ) ? $node['actions'] : array();

		if ( ! empty( $actions['save_to_db'] ) ) {
			RYV_Leads::record( $post_id, $node_id, $session_id, $name, $email );
		}

		if ( ! empty( $actions['email_admin'] ) && ! empty( $actions['admin_email_address'] ) ) {
			$admin_email = sanitize_email( (string) $actions['admin_email_address'] );

			if ( is_email( $admin_email ) ) {
				$subject = sprintf(
					/* translators: %s: form title */
					__( 'New form submission - %s', 'ryv-eligibility-tool' ),
					$post->post_title
				);
				$body = sprintf(
					"Name: %s\nEmail: %s",
					'' !== $name ? $name : __( '(not provided)', 'ryv-eligibility-tool' ),
					$email
				);

				wp_mail( $admin_email, $subject, $body );
			}
		}

		if ( ! empty( $actions['email_user'] ) ) {
			$message = isset( $actions['user_email_message'] ) ? sanitize_textarea_field( (string) $actions['user_email_message'] ) : '';
			$subject = sprintf(
				/* translators: %s: form title */
				__( 'Your results - %s', 'ryv-eligibility-tool' ),
				$post->post_title
			);

			wp_mail( $email, $subject, $message );
		}

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
