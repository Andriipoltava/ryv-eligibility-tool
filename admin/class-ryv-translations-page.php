<?php
/**
 * "Translations" admin submenu page: lets an admin manually link two
 * existing `ryv_forms` posts (one per language) as WPML translations of
 * each other - entirely through WPML's own public API
 * (`wpml_set_element_language_details`, `wpml_element_trid`,
 * `wpml_post_language_details`), never touching `wp_icl_translations`
 * directly.
 *
 * This exists because the normal WPML ways of doing this don't reach this
 * CPT: `RYV_Builder_Page` fully replaces the standard post edit screen (see
 * its own docblock), so WPML's classic "Languages" meta box - the usual
 * place to point an existing post at another as its translation - never
 * renders for a `ryv_forms` post. The list table's own "+"/pencil icon in
 * the Languages column instead routes through WPML's Translation
 * Management job queue, which is for commissioning a *new* translation, not
 * for linking two posts that were each already created independently (e.g.
 * one YAML-imported per language edition).
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Translations_Page
 */
class RYV_Translations_Page {

	/**
	 * Capability required to view/change - same trust boundary as
	 * RYV_Import_Page/RYV_Export_Page/RYV_Builder_Page (reassigning which
	 * form a visitor's chosen language routes to is as consequential as
	 * editing the form itself).
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-translations';

	/**
	 * Nonce action/name for the "link" form.
	 */
	const NONCE_ACTION = 'ryv_link_translation';

	/**
	 * Nonce action/name for a single group's "Unlink" button.
	 */
	const NONCE_ACTION_UNLINK = 'ryv_unlink_translation';

	/**
	 * Nonce action/name for the "Assign Default Language" bulk backfill button.
	 */
	const NONCE_ACTION_BACKFILL = 'ryv_backfill_default_language';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_submission' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'disable_list_table_translate_link' ) );
	}

	/**
	 * Neutralize WPML's own "+"/pencil/gear translate-link icon
	 * (`a.js-wpml-translate-link` in the `td.column-icl_translations`
	 * WPML itself adds to every post-type list table) on the `ryv_forms`
	 * list screen specifically. Clicking it is exactly what caused this
	 * page to need to exist in the first place: for a post type whose "+"
	 * routes into WPML's Translation Management job queue (commissioning a
	 * *new* translation) rather than linking two already-existing posts,
	 * an admin clicking it - expecting the same "edit the linked post"
	 * behavior it has for ordinary posts/pages - can silently queue an
	 * unwanted duplicate-in-progress translation job (see this page's own
	 * class docblock). Rather than leave that footgun live, this strips the
	 * link's `href` (so neither a click nor keyboard Enter navigates
	 * anywhere) and points its tooltip at this page instead - a
	 * belt-and-braces JS fix, not a CSS-only `pointer-events` hack, since
	 * that alone wouldn't stop keyboard activation.
	 */
	public function disable_list_table_translate_link() {
		$screen = get_current_screen();

		if ( ! $screen || 'edit-' . RYV_CPT::POST_TYPE !== $screen->id ) {
			return;
		}

		$translations_url = admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG );
		?>
		<script>
		( function() {
			var tip = <?php echo wp_json_encode( __( 'Use RYV Forms → Translations to link forms - this icon is disabled here.', 'ryv-eligibility-tool' ) ); ?>;
			var url = <?php echo wp_json_encode( $translations_url ); ?>;
			document.querySelectorAll( 'td.column-icl_translations a.js-wpml-translate-link' ).forEach( function( link ) {
				link.removeAttribute( 'href' );
				link.setAttribute( 'aria-disabled', 'true' );
				link.setAttribute( 'title', tip );
				link.style.cursor = 'not-allowed';
				link.style.opacity = '0.5';
				link.addEventListener( 'click', function( e ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					window.location.href = url;
				}, true );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Whether WPML (or a compatible multilingual plugin implementing the
	 * same API) is active - checked via the hooks this page needs rather
	 * than a hard class/constant dependency, matching how the rest of the
	 * plugin gates optional integrations (see the ACF-gated states/*
	 * classes in RYV_Plugin::includes()).
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return has_action( 'wpml_set_element_language_details' )
			&& has_filter( 'wpml_element_trid' )
			&& has_filter( 'wpml_post_language_details' )
			&& has_filter( 'wpml_active_languages' );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu - only when
	 * WPML's API is actually present, so this page (and its menu entry)
	 * simply doesn't exist on a site without WPML rather than erroring.
	 */
	public function register_menu() {
		if ( ! self::is_available() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Translations', 'ryv-eligibility-tool' ),
			__( 'Translations', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle any of this page's three form submissions (Post/Redirect/Get,
	 * same pattern as RYV_Import_Page::handle_submission()) - link two
	 * forms, unlink one form from its group, or bulk-backfill every form
	 * that's still missing a WPML language assignment.
	 */
	public function handle_submission() {
		if ( isset( $_POST['ryv_link_submit'] ) ) {
			$this->do_link_submission();
		} elseif ( isset( $_POST['ryv_unlink_submit'] ) ) {
			$this->do_unlink_submission();
		} elseif ( isset( $_POST['ryv_backfill_submit'] ) ) {
			$this->do_backfill_submission();
		}
	}

	/**
	 * Redirect back to this page carrying $notice as the `ryv_notice` query arg.
	 *
	 * @param array{success: bool, message: string} $notice Notice to display.
	 */
	private function redirect_with_notice( array $notice ) {
		wp_safe_redirect(
			add_query_arg(
				'ryv_notice',
				rawurlencode( wp_json_encode( $notice ) ),
				admin_url( 'edit.php?post_type=' . RYV_CPT::POST_TYPE . '&page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Handle the "Link as Translations" form.
	 */
	private function do_link_submission() {
		if ( ! self::is_available() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage form translations.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_link_nonce' );

		$original_id    = isset( $_POST['ryv_original_form'] ) ? absint( wp_unslash( $_POST['ryv_original_form'] ) ) : 0;
		$translation_id = isset( $_POST['ryv_translation_form'] ) ? absint( wp_unslash( $_POST['ryv_translation_form'] ) ) : 0;

		$this->redirect_with_notice( $this->link_forms( $original_id, $translation_id ) );
	}

	/**
	 * Handle a group row's "Unlink" button.
	 */
	private function do_unlink_submission() {
		if ( ! self::is_available() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage form translations.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION_UNLINK, 'ryv_unlink_nonce' );

		$form_id = isset( $_POST['ryv_unlink_form'] ) ? absint( wp_unslash( $_POST['ryv_unlink_form'] ) ) : 0;

		$this->redirect_with_notice( $this->unlink_form( $form_id ) );
	}

	/**
	 * Handle the "Assign Default Language" bulk backfill button.
	 */
	private function do_backfill_submission() {
		if ( ! self::is_available() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage form translations.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION_BACKFILL, 'ryv_backfill_nonce' );

		$this->redirect_with_notice( $this->backfill_default_languages() );
	}

	/**
	 * Validate and perform the link, entirely through WPML's own API.
	 *
	 * @param int $original_id    "Anchor" form - the translation joins this
	 *                            form's existing translation group.
	 * @param int $translation_id Form to (re)assign as a translation of
	 *                            $original_id.
	 * @return array{success: bool, message: string}
	 */
	private function link_forms( int $original_id, int $translation_id ): array {
		if ( ! $original_id || ! $translation_id ) {
			return array(
				'success' => false,
				'message' => __( 'Choose both a form and a translation.', 'ryv-eligibility-tool' ),
			);
		}

		if ( $original_id === $translation_id ) {
			return array(
				'success' => false,
				'message' => __( 'Choose two different forms.', 'ryv-eligibility-tool' ),
			);
		}

		$original_post    = get_post( $original_id );
		$translation_post = get_post( $translation_id );

		if ( ! $original_post || RYV_CPT::POST_TYPE !== $original_post->post_type
			|| ! $translation_post || RYV_CPT::POST_TYPE !== $translation_post->post_type
		) {
			return array(
				'success' => false,
				'message' => __( 'One of the selected forms was not found.', 'ryv-eligibility-tool' ),
			);
		}

		$original_lang    = apply_filters( 'wpml_post_language_details', null, $original_id );
		$translation_lang = apply_filters( 'wpml_post_language_details', null, $translation_id );

		if ( empty( $original_lang['language_code'] ) || empty( $translation_lang['language_code'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not determine the language of one of the selected forms.', 'ryv-eligibility-tool' ),
			);
		}

		if ( $original_lang['language_code'] === $translation_lang['language_code'] ) {
			return array(
				'success' => false,
				'message' => __( 'Both forms are in the same language - pick one in a different language to link as its translation.', 'ryv-eligibility-tool' ),
			);
		}

		$trid = apply_filters( 'wpml_element_trid', false, $original_id, 'post_' . RYV_CPT::POST_TYPE );

		if ( ! $trid ) {
			return array(
				'success' => false,
				'message' => __( 'Could not determine a translation group for the anchor form.', 'ryv-eligibility-tool' ),
			);
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $translation_id,
				'element_type'         => 'post_' . RYV_CPT::POST_TYPE,
				'trid'                 => $trid,
				'language_code'        => $translation_lang['language_code'],
				'source_language_code' => $original_lang['language_code'],
			)
		);

		return array(
			/* translators: 1: translation form title, 2: original form title */
			'success' => true,
			'message' => sprintf(
				__( '"%1$s" is now linked as the %2$s translation of "%3$s".', 'ryv-eligibility-tool' ),
				$translation_post->post_title,
				strtoupper( $translation_lang['language_code'] ),
				$original_post->post_title
			),
		);
	}

	/**
	 * Remove one form from its current translation group by giving it a
	 * brand-new trid of its own (`trid => false` tells WPML to generate a
	 * fresh one) - it keeps its own language, it just stops being grouped
	 * with whatever it was linked to. WPML has no separate "delete this
	 * relationship" API; re-assigning a fresh trid via the same
	 * `wpml_set_element_language_details` action used to link forms is the
	 * documented way to detach one.
	 *
	 * A single-member group (nothing to unlink) is guarded against here too
	 * even though the view only renders the button for groups with more
	 * than one member, since this still runs through the same POST handler
	 * as everything else on the page.
	 *
	 * @param int $form_id Form to detach from its current group.
	 * @return array{success: bool, message: string}
	 */
	private function unlink_form( int $form_id ): array {
		if ( ! $form_id ) {
			return array(
				'success' => false,
				'message' => __( 'Choose a form to unlink.', 'ryv-eligibility-tool' ),
			);
		}

		$form = get_post( $form_id );

		if ( ! $form || RYV_CPT::POST_TYPE !== $form->post_type ) {
			return array(
				'success' => false,
				'message' => __( 'That form was not found.', 'ryv-eligibility-tool' ),
			);
		}

		$lang = apply_filters( 'wpml_post_language_details', null, $form_id );

		if ( empty( $lang['language_code'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not determine the language of that form.', 'ryv-eligibility-tool' ),
			);
		}

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $form_id,
				'element_type'  => 'post_' . RYV_CPT::POST_TYPE,
				'trid'          => false,
				'language_code' => $lang['language_code'],
			)
		);

		return array(
			/* translators: %s: form title */
			'success' => true,
			'message' => sprintf(
				__( '"%s" is no longer linked to any other language.', 'ryv-eligibility-tool' ),
				$form->post_title
			),
		);
	}

	/**
	 * Bulk-assign every `ryv_forms` post that has no WPML language at all
	 * yet to the site's default language - the same check-then-assign
	 * `RYV_CPT::assign_default_language_if_missing()` also runs
	 * automatically on every save, but a form created before that hook
	 * existed (or on a request where WPML's own default-language-on-create
	 * behavior and this plugin's hook both missed it) needs a one-time
	 * catch-up pass across everything already in the database.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function backfill_default_languages(): array {
		// Every RYV post type wpml-config.xml declares translatable, not
		// just forms: `ryv_resources`/`ryv_stories` became translatable
		// later, which means every one of them that already existed at that
		// moment has no language row and is therefore invisible to any
		// language-filtered query - including the state-page lists that
		// render them on the frontend (see RYV_State_Queries).
		$post_types = array( RYV_CPT::POST_TYPE, RYV_States_CPT::POST_TYPE, RYV_Resources_CPT::POST_TYPE, RYV_Stories_CPT::POST_TYPE );
		$posts      = get_posts(
			array(
				'post_type'        => $post_types,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'suppress_filters' => true,
			)
		);
		$fixed      = array();

		foreach ( $posts as $post ) {
			if ( RYV_CPT::assign_default_language_if_missing( $post->ID ) ) {
				$fixed[] = $post->post_title;
			}
		}

		if ( ! $fixed ) {
			return array(
				'success' => true,
				'message' => __( 'Every form, state, resource and story already has a language assigned - nothing to do.', 'ryv-eligibility-tool' ),
			);
		}

		return array(
			/* translators: 1: number of posts fixed, 2: comma-separated list of their titles */
			'success' => true,
			'message' => sprintf(
				__( 'Assigned a default language to %1$d post(s): %2$s.', 'ryv-eligibility-tool' ),
				count( $fixed ),
				implode( ', ', $fixed )
			),
		);
	}

	/**
	 * Every `ryv_forms` post, any status/language - `get_posts()` already
	 * defaults `suppress_filters` to true, which is what keeps this from
	 * being silently narrowed to just the admin's current WPML content
	 * language (the same reason RYV_Import_Page's own form-picker query
	 * already sees every form regardless of language).
	 *
	 * @return WP_Post[]
	 */
	private function get_all_forms(): array {
		return get_posts(
			array(
				'post_type'      => RYV_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Group every form by its WPML translation group (trid) for the
	 * "Current Groups" table - forms with no other translations still get
	 * their own single-row group, so the table is a complete picture, not
	 * just the already-linked ones.
	 *
	 * @param WP_Post[] $forms From get_all_forms().
	 * @return array<int, array{language_code: string, post: WP_Post}[]> trid => rows.
	 */
	private function group_by_trid( array $forms ): array {
		$groups = array();

		foreach ( $forms as $form ) {
			$lang = apply_filters( 'wpml_post_language_details', null, $form->ID );
			$trid = apply_filters( 'wpml_element_trid', false, $form->ID, 'post_' . RYV_CPT::POST_TYPE );

			if ( ! $trid ) {
				$trid = 'untranslated_' . $form->ID;
			}

			$groups[ $trid ][] = array(
				'language_code' => ! empty( $lang['language_code'] ) ? $lang['language_code'] : '?',
				'post'          => $form,
			);
		}

		return $groups;
	}

	/**
	 * Render the submenu page: current groups + the "link" form.
	 */
	public function render_page() {
		if ( ! self::is_available() ) {
			wp_die( esc_html__( 'WPML is not active.', 'ryv-eligibility-tool' ) );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$notice = null;
		if ( isset( $_GET['ryv_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, just decoding the redirect payload.
			$decoded = json_decode( wp_unslash( $_GET['ryv_notice'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- json_decode() into a fixed {success,message} shape, escaped on output below.
			if ( is_array( $decoded ) && isset( $decoded['success'], $decoded['message'] ) ) {
				$notice = $decoded;
			}
		}

		$forms      = $this->get_all_forms();
		$languages  = apply_filters( 'wpml_active_languages', null );
		$languages  = is_array( $languages ) ? $languages : array();
		$groups     = $this->group_by_trid( $forms );

		$form_options = array();
		foreach ( $forms as $form ) {
			$lang                     = apply_filters( 'wpml_post_language_details', null, $form->ID );
			$code                     = ! empty( $lang['language_code'] ) ? strtoupper( $lang['language_code'] ) : '?';
			$form_options[ $form->ID ] = sprintf( '%s (%s)', $form->post_title, $code );
		}

		include RYV_PLUGIN_DIR . 'admin/views/translations-page.php';
	}
}
