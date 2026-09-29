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
				'nodes'       => self::format_node_html( $schema['nodes'] ),
			),
			200
		);
	}

	/**
	 * Turn line breaks in a node's rich text into paragraphs/`<br>`, the
	 * same way WordPress displays post content.
	 *
	 * The builder's editor (and the ACF node editor on a state page) is
	 * WordPress's classic editor, which - like for a normal post - saves
	 * paragraphs as blank lines rather than `<p>` tags, relying on wpautop()
	 * at display time to restore them. Without this, a hard return typed or
	 * pasted into an answer was simply collapsed away on the frontend.
	 * wpautop() leaves already-formatted HTML alone, so text that has its
	 * own `<p>`/`<br>` markup renders exactly as before. Display-only: the
	 * stored schema (and the admin API the builder edits) stays untouched.
	 *
	 * @param array $nodes node_id => node.
	 * @return array
	 */
	private static function format_node_html( array $nodes ): array {
		foreach ( $nodes as &$node ) {
			foreach ( array( 'content', 'description' ) as $field ) {
				if ( isset( $node[ $field ] ) && is_string( $node[ $field ] ) && '' !== trim( $node[ $field ] ) ) {
					$node[ $field ] = wpautop( $node[ $field ] );
				}
			}
		}
		unset( $node );

		return $nodes;
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
	 * node's `form_fields` (what inputs exist) and `email_settings` (what to
	 * do with the submission) are read straight from the schema rather than
	 * trusted from the request - only `node_id`/`form_data`/`session_id`
	 * come from the client, and `form_data` is only ever read for keys the
	 * schema's own `form_fields` declares, each sanitized per that field's
	 * declared type before it's stored or dropped into an email template.
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

		$form_fields = isset( $node['form_fields'] ) && is_array( $node['form_fields'] ) ? $node['form_fields'] : array();
		$submitted   = $request->get_param( 'form_data' );
		$submitted   = is_array( $submitted ) ? $submitted : array();

		list( $sanitized_data, $invalid_labels ) = $this->sanitize_form_data( $form_fields, $submitted );

		if ( ! empty( $invalid_labels ) ) {
			return new WP_Error(
				'ryv_invalid_form_data',
				sprintf(
					/* translators: %s: comma-separated list of field labels */
					__( 'Missing or invalid value for: %s', 'ryv-eligibility-tool' ),
					implode( ', ', $invalid_labels )
				),
				array( 'status' => 400 )
			);
		}

		$session_id_param = $request->get_param( 'session_id' );
		$session_id        = is_string( $session_id_param ) && '' !== $session_id_param
			? substr( preg_replace( '/[^a-zA-Z0-9-]/', '', $session_id_param ), 0, 64 )
			: null;

		$email_settings = is_array( $node['email_settings'] ?? null ) ? $node['email_settings'] : array();

		if ( ! empty( $email_settings['save_to_db'] ) ) {
			RYV_Leads::record( $post_id, $node_id, $session_id, $sanitized_data );
		}

		if ( ! empty( $email_settings['enable_admin_email'] ) && ! empty( $email_settings['admin_email_to'] ) ) {
			$recipients = array_filter(
				array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $email_settings['admin_email_to'] ) ) ),
				'is_email'
			);

			if ( ! empty( $recipients ) ) {
				$subject = $this->render_template(
					sanitize_text_field( (string) ( $email_settings['admin_email_subject'] ?? '' ) ),
					$sanitized_data,
					$form_fields
				);
				$body = $this->render_template(
					sanitize_textarea_field( (string) ( $email_settings['admin_email_body'] ?? '' ) ),
					$sanitized_data,
					$form_fields
				);

				wp_mail( $recipients, $subject, $body );
			}
		}

		if ( ! empty( $email_settings['enable_user_email'] ) && ! empty( $email_settings['user_email_field'] ) ) {
			$field_name = sanitize_key( (string) $email_settings['user_email_field'] );
			$user_email = isset( $sanitized_data[ $field_name ] ) ? sanitize_email( $sanitized_data[ $field_name ] ) : '';

			if ( is_email( $user_email ) ) {
				$subject = $this->render_template(
					sanitize_text_field( (string) ( $email_settings['user_email_subject'] ?? '' ) ),
					$sanitized_data,
					$form_fields
				);
				$body = $this->render_template(
					sanitize_textarea_field( (string) ( $email_settings['user_email_body'] ?? '' ) ),
					$sanitized_data,
					$form_fields
				);

				wp_mail( $user_email, $subject, $body );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Sanitize submitted `form_data` against the node's own `form_fields`
	 * declaration - only keys the schema declares are ever read (anything
	 * else in the request payload is silently dropped), each sanitized per
	 * its declared `type` rather than with one generic sanitizer.
	 *
	 * @param array $form_fields Node's `form_fields` ({ label, name, type, required }[]).
	 * @param array $submitted   Raw `form_data` from the request (field name => value).
	 * @return array{0: array<string,string>, 1: string[]} Sanitized `{name: value}` map,
	 *   and the labels of any required/invalid fields (empty means valid).
	 */
	private function sanitize_form_data( array $form_fields, array $submitted ): array {
		$sanitized_data = array();
		$invalid_labels = array();

		foreach ( $form_fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$name  = sanitize_key( (string) $field['name'] );
			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$label = ! empty( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $name;

			$raw = array_key_exists( $field['name'], $submitted ) ? $submitted[ $field['name'] ] : '';
			$raw = is_scalar( $raw ) ? (string) $raw : '';

			if ( 'email' === $type ) {
				$value = sanitize_email( $raw );
			} elseif ( 'textarea' === $type ) {
				$value = sanitize_textarea_field( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			$is_missing = ! empty( $field['required'] ) && '' === $value;
			$is_bad_email = 'email' === $type && '' !== $value && ! is_email( $value );

			if ( $is_missing || $is_bad_email ) {
				$invalid_labels[] = $label;
			}

			$sanitized_data[ $name ] = $value;
		}

		return array( $sanitized_data, $invalid_labels );
	}

	/**
	 * Replace `{field_name}` placeholders in an admin-authored email
	 * template with the matching sanitized submitted value, plus the
	 * special `{all_fields}` tag (a "Label: Value" summary of every
	 * submitted field). Placeholders with no matching field are left
	 * untouched rather than silently blanked, so a typo'd tag stays visible
	 * instead of disappearing.
	 *
	 * @param string $template       Subject or body template.
	 * @param array  $sanitized_data Sanitized `{name: value}` map from sanitize_form_data().
	 * @param array  $form_fields    Node's `form_fields`, for {all_fields}'s labels/ordering.
	 * @return string
	 */
	private function render_template( string $template, array $sanitized_data, array $form_fields ): string {
		if ( '' === $template ) {
			return '';
		}

		return preg_replace_callback(
			'/\{([a-zA-Z0-9_]+)\}/',
			function ( $matches ) use ( $sanitized_data, $form_fields ) {
				$key = $matches[1];

				if ( 'all_fields' === $key ) {
					return $this->format_all_fields( $sanitized_data, $form_fields );
				}

				return array_key_exists( $key, $sanitized_data ) ? $sanitized_data[ $key ] : $matches[0];
			},
			$template
		);
	}

	/**
	 * The `{all_fields}` template tag's expansion: one "Label: Value" line
	 * per submitted field, in `form_fields` order.
	 *
	 * @param array $sanitized_data Sanitized `{name: value}` map.
	 * @param array $form_fields    Node's `form_fields`.
	 * @return string
	 */
	private function format_all_fields( array $sanitized_data, array $form_fields ): string {
		$lines = array();

		foreach ( $form_fields as $field ) {
			$name = is_array( $field ) && ! empty( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';

			if ( '' === $name || ! array_key_exists( $name, $sanitized_data ) ) {
				continue;
			}

			$label   = ! empty( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $name;
			$lines[] = sprintf( '%s: %s', $label, $sanitized_data[ $name ] );
		}

		return implode( "\n", $lines );
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
