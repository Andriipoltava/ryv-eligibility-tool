<?php
/**
 * Embeddable, iframe-ready edition of an eligibility form.
 *
 * Serves a bare HTML document (no theme header/footer/menus) at a clean,
 * shareable URL - `/embed/{form-slug}/` - that partners can drop into their own
 * site with an `<iframe>`. It mounts the exact same `.ryv-app-wrapper` the
 * Elementor widget and `[ryv_form]` shortcode do, so there is still only one
 * frontend engine; this class only adds the route, the framing-security
 * headers, and a handful of presentation options read from the query string.
 *
 * Security model (see send_headers()):
 * - Framing is governed by a `Content-Security-Policy: frame-ancestors` header
 *   built from the admin-managed allow-list (RYV Forms -> Embed & Share), the
 *   modern replacement for X-Frame-Options. Every allow-list entry is
 *   re-validated to a strict origin grammar before it can reach a header, so
 *   header injection through the setting is structurally impossible.
 * - The route only ever exposes what the public REST route already does:
 *   published forms (drafts 404 for everyone except users who can edit them).
 * - The page is `noindex` (the canonical content lives on the main site) and
 *   sends `nosniff` + a conservative Referrer-Policy.
 * - Query-string options are whitelisted and individually sanitized (booleans,
 *   enums, hex colors); nothing from the URL is echoed raw into markup or CSS.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Embed
 */
class RYV_Embed {

	/**
	 * Query var the rewrite rule fills with the form slug (or numeric ID).
	 */
	const QUERY_VAR = 'ryv_embed';

	/**
	 * First URL segment: `/embed/{form}/`. Filterable via `ryv_embed_base`.
	 */
	const BASE_SLUG = 'embed';

	/**
	 * Option holding the admin-managed access settings.
	 */
	const OPTION = 'ryv_embed_settings';

	/**
	 * Option remembering which rewrite-rule revision was last flushed, so the
	 * new route works after a plain plugin update (no deactivate/reactivate).
	 */
	const REWRITE_FLAG = 'ryv_embed_rewrite_version';

	/**
	 * Bump when the rewrite rule's pattern/base changes.
	 */
	const REWRITE_VERSION = '1';

	/**
	 * Handle of the iframe-side auto-resize script (runs inside the embed).
	 */
	const CHILD_HANDLE = 'ryv-embed-child';

	/**
	 * Handle of the host-side loader partners include on their own page. Only
	 * registered here so the admin preview can reuse it; partners load it by URL.
	 */
	const LOADER_HANDLE = 'ryv-embed-loader';

	/**
	 * Sanitized presentation options for the request being rendered.
	 *
	 * @var array<string,mixed>
	 */
	private $args = array();

	/**
	 * The form being embedded for the request being rendered.
	 *
	 * @var WP_Post|null
	 */
	private $form = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		// After every other plugin/CPT has registered its own rules.
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		// Priority 1: before redirect_canonical() (priority 10) gets a chance
		// to "correct" a URL that, to core, looks like an unknown home query.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
		// A page's Elementor data changed: the cached "which widget settings
		// does the site look come from" lookup is stale.
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_bump_source_cache' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_bump_source_cache' ), 10, 3 );
	}

	/**
	 * Option holding the cache generation for the site-look source lookup.
	 */
	const SOURCE_CACHE_OPTION = 'ryv_embed_source_gen';

	/**
	 * Source widget (page id, widget id, settings) for the "site" look, or
	 * null to use the minimal look.
	 *
	 * @var array|null
	 */
	private $site_src = null;

	/**
	 * Invalidate the source lookup when any page's Elementor data is saved.
	 *
	 * @param int    $meta_id    Unused.
	 * @param int    $object_id  Unused.
	 * @param string $meta_key   Meta key.
	 */
	public static function maybe_bump_source_cache( $meta_id, $object_id, $meta_key ) {
		if ( '_elementor_data' === $meta_key ) {
			update_option( self::SOURCE_CACHE_OPTION, (string) microtime( true ), false );
		}
	}

	/**
	 * Base path segment, filterable for sites where `/embed/` is taken.
	 *
	 * @return string
	 */
	public static function base_slug(): string {
		$base = sanitize_title( (string) apply_filters( 'ryv_embed_base', self::BASE_SLUG ) );

		return '' !== $base ? $base : self::BASE_SLUG;
	}

	/**
	 * Register the `/embed/{form}/` rule.
	 */
	public function add_rewrite() {
		add_rewrite_rule(
			'^' . preg_quote( self::base_slug(), '#' ) . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush once per REWRITE_VERSION (+ base slug) - cheap option compare on
	 * every other request.
	 */
	public function maybe_flush_rewrite() {
		$wanted = self::REWRITE_VERSION . ':' . self::base_slug();

		if ( get_option( self::REWRITE_FLAG ) === $wanted ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, $wanted, true );
	}

	/**
	 * Whitelist the query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/**
	 * Stored access settings with defaults applied.
	 *
	 * Defaults to "enabled, any website" so a first share link works the
	 * moment it's copied; the admin page nudges toward an allow-list.
	 *
	 * @return array{enabled:bool,mode:string,domains:string[]}
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$domains = array();
		if ( isset( $stored['domains'] ) && is_array( $stored['domains'] ) ) {
			foreach ( $stored['domains'] as $domain ) {
				// Re-validated on read too: the option is also writable via
				// WP-CLI/another plugin, and this is what feeds a header.
				$clean = is_string( $domain ) ? self::normalize_origin( $domain ) : '';
				if ( '' !== $clean ) {
					$domains[] = $clean;
				}
			}
		}

		return array(
			'enabled' => ! array_key_exists( 'enabled', $stored ) || ! empty( $stored['enabled'] ),
			'mode'    => ( isset( $stored['mode'] ) && 'allowlist' === $stored['mode'] ) ? 'allowlist' : 'any',
			'domains' => array_values( array_unique( $domains ) ),
		);
	}

	/**
	 * Turn one admin-typed entry into a CSP-safe origin source, or '' if it
	 * isn't one. Accepts `partner.org`, `https://partner.org`,
	 * `*.partner.org`, `http://localhost:3000` - any path/query is dropped.
	 *
	 * The grammar is deliberately narrow (letters, digits, hyphens, dots, an
	 * optional leading `*.`, an optional port): no whitespace, semicolons or
	 * quotes can survive, so the value can never break out of the header.
	 *
	 * @param string $raw Raw entry.
	 * @return string Normalized source, or ''.
	 */
	public static function normalize_origin( string $raw ): string {
		$value = strtolower( trim( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		$scheme = 'https://';

		if ( preg_match( '#^(https?)://#', $value, $m ) ) {
			$scheme = $m[1] . '://';
			$value  = substr( $value, strlen( $scheme ) );
		}

		// Drop any path, query or fragment someone pasted along with the URL.
		$value = (string) preg_replace( '#[/?\#].*$#', '', $value );

		$label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

		if ( ! preg_match( '/^(\*\.)?(' . $label . '(?:\.' . $label . ')*)(:\d{1,5})?$/', $value, $m ) ) {
			return '';
		}

		$wildcard = '*.' === $m[1];
		$host     = $m[2];

		// `*.com` would allow an entire TLD; a wildcard needs a real domain
		// under it. (A bare single label is only sensible for localhost.)
		if ( $wildcard && substr_count( $host, '.' ) < 1 ) {
			return '';
		}

		if ( ! $wildcard && false === strpos( $host, '.' ) && 'localhost' !== $host ) {
			return '';
		}

		return $scheme . ( $wildcard ? '*.' : '' ) . $host . ( $m[3] ?? '' );
	}

	/**
	 * The `frame-ancestors` source list for the current settings.
	 *
	 * @param bool $preview_only True when embedding is switched off but an
	 *                           administrator is still previewing the page.
	 * @return string
	 */
	public static function frame_ancestors( bool $preview_only = false ): string {
		$settings = self::get_settings();

		if ( $preview_only || ! $settings['enabled'] ) {
			$sources = "'self'";
		} elseif ( 'any' === $settings['mode'] ) {
			$sources = '*';
		} else {
			$sources = trim( "'self' " . implode( ' ', $settings['domains'] ) );
		}

		/**
		 * Filters the frame-ancestors source list. Return a space-separated
		 * list of CSP sources; unsafe values are the caller's responsibility.
		 */
		return (string) apply_filters( 'ryv_embed_frame_ancestors', $sources, $settings );
	}

	// ---------------------------------------------------------------------
	// URLs
	// ---------------------------------------------------------------------

	/**
	 * The URL path segment identifying a form: its slug when that's unique
	 * across languages (WPML lets translations share one), else its ID.
	 *
	 * @param WP_Post $form Form post.
	 * @return string
	 */
	public static function form_segment( WP_Post $form ): string {
		if ( '' === $form->post_name ) {
			return (string) $form->ID;
		}

		$same = get_posts(
			array(
				'post_type'        => RYV_CPT::POST_TYPE,
				'name'             => $form->post_name,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 2,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		return count( $same ) > 1 ? (string) $form->ID : $form->post_name;
	}

	/**
	 * The shareable embed URL for a form, optionally with presentation args.
	 *
	 * @param WP_Post             $form Form post.
	 * @param array<string,string> $args Query args (layout, title, ...).
	 * @return string
	 */
	public static function build_url( WP_Post $form, array $args = array() ): string {
		$segment = self::form_segment( $form );

		if ( get_option( 'permalink_structure' ) ) {
			$url = trailingslashit( home_url( self::base_slug() . '/' . rawurlencode( $segment ) ) );
		} else {
			$url = add_query_arg( self::QUERY_VAR, rawurlencode( $segment ), home_url( '/' ) );
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Public URL of the host-side auto-resize loader partners include.
	 *
	 * @return string
	 */
	public static function loader_url(): string {
		return add_query_arg( 'ver', RYV_VERSION, RYV_PLUGIN_URL . 'assets/js/embed.js' );
	}

	/**
	 * Resolve a URL key (slug or numeric ID) to a form post.
	 *
	 * @param string $key Slug or ID from the URL.
	 * @return WP_Post|null
	 */
	public static function resolve_form( string $key ): ?WP_Post {
		if ( '' === $key ) {
			return null;
		}

		if ( ctype_digit( $key ) ) {
			$post = get_post( (int) $key );

			return ( $post && RYV_CPT::POST_TYPE === $post->post_type ) ? $post : null;
		}

		$found = get_posts(
			array(
				'post_type'        => RYV_CPT::POST_TYPE,
				'name'             => sanitize_title( $key ),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 5,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		// A slug shared across WPML languages: prefer a published edition.
		foreach ( $found as $post ) {
			if ( 'publish' === $post->post_status ) {
				return $post;
			}
		}

		return $found ? $found[0] : null;
	}

	// ---------------------------------------------------------------------
	// Request handling
	// ---------------------------------------------------------------------

	/**
	 * Serve the embed document when the request matched our rewrite rule.
	 */
	public function maybe_render() {
		$key = get_query_var( self::QUERY_VAR );

		if ( ! is_string( $key ) || '' === $key ) {
			return;
		}

		$settings    = self::get_settings();
		$is_admin    = current_user_can( 'manage_options' );
		$form        = self::resolve_form( sanitize_text_field( rawurldecode( $key ) ) );
		$visible     = $form && ( 'publish' === $form->post_status || current_user_can( 'edit_post', $form->ID ) );
		$embed_is_on = $settings['enabled'] || $is_admin;

		if ( ! $form || ! $visible || ! $embed_is_on ) {
			// A real 404 through the theme - same response whether the form
			// never existed, is a draft, or embedding is off, so the route
			// can't be used to probe which forms exist.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$this->form = $form;
		$this->args = $this->read_args();

		$this->send_headers( ! $settings['enabled'] );

		// Bare document: none of the site chrome, no admin bar, not indexed.
		add_filter( 'show_admin_bar', '__return_false' );
		add_filter(
			'wp_robots',
			static function ( $robots ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				unset( $robots['max-image-preview'] );

				return $robots;
			}
		);
		add_theme_support( 'title-tag' );
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 99 );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_inline_styles' ), 11 );
		// The widget's Style-tab values use the Elementor Kit's global fonts/colors,
		// which are defined under `.elementor-kit-{id}` on <body>.
		add_filter(
			'body_class',
			static function ( $classes ) {
				if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
					$kit = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
					if ( $kit ) {
						$classes[] = 'elementor-kit-' . $kit;
					}
				}

				return $classes;
			}
		);
		$this->site_src = ( 'site' === $this->args['look'] ) ? self::find_site_source( (int) $form->ID ) : null;
		$this->isolate_document();

		$this->render_document();
		exit;
	}

	/**
	 * Keep third-party plugins out of the bare embed document.
	 *
	 * The page runs the normal wp_head/wp_footer so core, Elementor and the
	 * RYV tool still print their own styles/scripts, but everything else that
	 * hooks in (cookie banner, language switcher, events calendar, popups,
	 * SEO/analytics snippets, admin bar) is dropped: it has no business inside
	 * a partner's iframe, and a cookie banner in particular would show up on
	 * someone else's site. Everything here is a plain allow/deny list that a
	 * site can extend with the filters below.
	 */
	private function isolate_document() {
		show_admin_bar( false );

		// Scripts: only the tool itself (+ the resize helper) may load.
		add_action( 'wp_enqueue_scripts', array( $this, 'prune_assets' ), PHP_INT_MAX - 1 );
		add_action( 'wp_head', array( $this, 'prune_head' ), 0 );
		add_action( 'wp_footer', array( $this, 'prune_footer' ), 0 );
	}

	/**
	 * Dequeue every script except the allow-list, and styles belonging to
	 * known non-essential plugins.
	 */
	public function prune_assets() {
		$scripts = wp_scripts();
		/**
		 * Script handles that may load on an embed page.
		 *
		 * @param string[] $handles Handles.
		 */
		$allowed = (array) apply_filters( 'ryv_embed_allowed_script_handles', array( RYV_Assets::SCRIPT_HANDLE, self::CHILD_HANDLE ) );

		foreach ( (array) $scripts->queue as $handle ) {
			if ( ! in_array( $handle, $allowed, true ) ) {
				wp_dequeue_script( $handle );
			}
		}

		/**
		 * Style handle prefixes dropped from embed pages.
		 *
		 * @param string[] $prefixes Prefixes.
		 */
		$blocked = (array) apply_filters(
			'ryv_embed_blocked_style_prefixes',
			array( 'cky', 'cookie-law', 'wpml', 'sitepress', 'tribe', 'tec-', 'stellarwp', 'gform', 'yoast', 'wp-stream', 'admin-bar' )
		);
		// Elementor Theme Builder header/footer/page CSS is not needed, but the
		// active Kit's sheet (global fonts/colors) is.
		$kit_handle = '';
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			$kit_handle = 'elementor-post-' . (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		}
		$blocked[] = 'widget-';
		$blocked[] = 'e-apple-webkit';
		$source_handle = $this->site_src ? 'elementor-post-' . (int) $this->site_src['post_id'] : '';
		foreach ( (array) wp_styles()->queue as $handle ) {
			if ( '' !== $source_handle && $handle === $source_handle ) {
				continue;
			}
			if ( 0 === strpos( $handle, 'elementor-post-' ) && $handle !== $kit_handle ) {
				wp_dequeue_style( $handle );
				continue;
			}
			foreach ( $blocked as $prefix ) {
				if ( 0 === strpos( $handle, $prefix ) ) {
					wp_dequeue_style( $handle );
					break;
				}
			}
		}
	}

	/**
	 * Drop head output from known non-essential plugins and core's discovery
	 * links (feeds, REST/oEmbed, generator tags).
	 */
	public function prune_head() {
		/**
		 * Callback owners (class-name prefixes) removed from wp_head.
		 *
		 * @param string[] $prefixes Prefixes.
		 */
		$blocked = (array) apply_filters(
			'ryv_embed_blocked_head_owners',
			array( 'CookieYes\\', 'WP_Stream\\', 'Tribe', 'TEC\\', 'SitePress', 'WPML', 'GFForms', 'Yoast\\', 'ASENHA\\', 'ElementorPro\\', 'Elementor\\Modules\\GeneratorTag' )
		);
		self::prune_hook(
			'wp_head',
			static function ( $callback ) use ( $blocked ) {
				$owner = self::callback_owner( $callback );
				foreach ( $blocked as $prefix ) {
					if ( '' !== $owner && 0 === strpos( $owner, $prefix ) ) {
						return true;
					}
				}

				return in_array( $owner, array( 'feed_links', 'feed_links_extra', 'rest_output_link_wp_head', 'rsd_link', 'wp_generator', 'wp_shortlink_wp_head', 'wp_oembed_add_discovery_links', 'wp_oembed_add_host_js', 'wp_resource_hints' ), true );
			}
		);
	}

	/**
	 * Footer is allow-list only: core's own style/script printers stay,
	 * everything else (banners, popups, switchers, analytics) goes.
	 */
	public function prune_footer() {
		/**
		 * Callbacks that may run on wp_footer of an embed page.
		 *
		 * @param string[] $allowed Function names.
		 */
		$allowed = (array) apply_filters(
			'ryv_embed_allowed_footer_callbacks',
			array( 'wp_print_footer_scripts', 'wp_enqueue_global_styles', 'wp_enqueue_stored_styles', 'wp_maybe_inline_styles', 'WP_Duotone::output_footer_assets', 'WP_Script_Modules::print_import_map', 'WP_Script_Modules::print_enqueued_script_modules', 'WP_Script_Modules::print_script_module_preloads', 'WP_Script_Modules::print_script_module_data' )
		);
		self::prune_hook(
			'wp_footer',
			static function ( $callback ) use ( $allowed ) {
				return ! in_array( self::callback_owner( $callback ), $allowed, true );
			}
		);
		// Plugins also print inline CSS/JS through the footer-scripts hook.
		self::prune_hook(
			'wp_print_footer_scripts',
			static function ( $callback ) {
				return '_wp_footer_scripts' !== self::callback_owner( $callback );
			}
		);
	}

	/**
	 * Remove callbacks from a hook for which $should_remove returns true.
	 * Skips priority 0 (this class's own pruning hooks run there).
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $should_remove fn( callable ): bool.
	 */
	private static function prune_hook( string $hook, callable $should_remove ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			if ( 0 === (int) $priority ) {
				continue;
			}
			foreach ( $callbacks as $id => $cb ) {
				if ( $should_remove( $cb['function'] ) ) {
					unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
				}
			}
		}
	}

	/**
	 * Printable owner of a callback: "func", "Class::method" or '' for
	 * closures. Closures have no owner, so they are never matched by a deny
	 * list (and are always removed by an allow list).
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_owner( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return $class . '::' . $callback[1];
		}

		return '';
	}

	/**
	 * Find the Elementor widget the site itself uses for this form, so the
	 * embed can render with that placement's own settings (fonts, sizes, card,
	 * step sidebar) and look like the website, without anyone re-styling it.
	 *
	 * Prefers a widget whose form is this one (or a linked translation), then
	 * any widget (its form swapped for this one). The home page wins ties. The
	 * state-page template (auto_state_entry) is skipped. Cached; flushed when
	 * any page's Elementor data is saved.
	 *
	 * @param int $form_id Form post ID.
	 * @return array{post_id:int,widget_id:string,settings:array}|null
	 */
	public static function find_site_source( int $form_id ): ?array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! class_exists( 'Elementor\\Core\\Files\\CSS\\Post' ) ) {
			return null;
		}

		$key    = 'ryv_embed_src_' . $form_id . '_' . md5( (string) get_option( self::SOURCE_CACHE_OPTION, '0' ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		global $wpdb;
		$front = (int) get_option( 'page_on_front' );
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				 WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
				   AND m.meta_value LIKE %s
				 ORDER BY (p.ID = %d) DESC, p.ID ASC LIMIT 40",
				'%ryv_eligibility_tool%',
				$front
			)
		);

		// A widget's form_id is often the *original-language* form even on a
		// translated page (WPML re-points it at render time), so "same form"
		// alone would pick the English page for the Spanish form. Prefer, in
		// order: same form, same language as the form, anything.
		$lang  = RYV_Form_Translations::get_language( $form_id );
		$exact = null;
		$same  = null;
		$any   = null;
		foreach ( (array) $ids as $post_id ) {
			$data = json_decode( (string) get_post_meta( (int) $post_id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$post_lang = '';
			if ( '' !== $lang && has_filter( 'wpml_post_language_details' ) ) {
				$details   = apply_filters( 'wpml_post_language_details', null, (int) $post_id );
				$post_lang = is_array( $details ) && ! empty( $details['language_code'] ) ? (string) $details['language_code'] : '';
			}
			self::walk_for_widget(
				$data,
				static function ( array $el ) use ( $post_id, $form_id, $lang, $post_lang, &$exact, &$same, &$any ) {
					$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
					if ( 'yes' === ( $settings['auto_state_entry'] ?? '' ) ) {
						return;
					}
					$found = array(
						'post_id'   => (int) $post_id,
						'widget_id' => (string) $el['id'],
						'settings'  => $settings,
					);
					if ( null === $any ) {
						$any = $found;
					}
					if ( null === $same && '' !== $lang && $post_lang === $lang ) {
						$same = $found;
					}
					if ( null === $exact && (int) ( $settings['form_id'] ?? 0 ) === $form_id && ( '' === $lang || '' === $post_lang || $post_lang === $lang ) ) {
						$exact = $found;
					}
				}
			);
			if ( $exact ) {
				break;
			}
		}

		$result = $exact ?: ( $same ?: $any );
		set_transient( $key, $result ?: array(), 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Call $fn for every ryv_eligibility_tool widget in an Elementor tree.
	 *
	 * @param array    $nodes Elements.
	 * @param callable $fn    fn( array $element ): void.
	 */
	private static function walk_for_widget( array $nodes, callable $fn ) {
		foreach ( $nodes as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'], $el['id'] ) && 'ryv_eligibility_tool' === $el['widgetType'] ) {
				$fn( $el );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_for_widget( $el['elements'], $fn );
			}
		}
	}

	/**
	 * Render the site's own widget for this form, then apply the partner's
	 * options to the markup. '' means "could not render" (caller falls back to
	 * the minimal look).
	 *
	 * @param int    $form_id    Form ID.
	 * @param string $entry_node Entry node for ?state=, or ''.
	 * @param string $state_slug State slug for the language editions, or ''.
	 * @return string
	 */
	private function render_site_widget( int $form_id, string $entry_node, string $state_slug ): string {
		$settings = $this->site_src['settings'];

		$settings['form_id']          = (string) $form_id;
		$settings['auto_state_entry'] = '';
		$settings['show_title']       = $this->args['title'] ? 'yes' : '';
		$settings['show_description'] = $this->args['description'] ? 'yes' : '';
		$settings['show_start_over']  = $this->args['restart'] ? 'yes' : '';
		if ( '' !== $this->args['layout'] ) {
			$settings['question_layout'] = $this->args['layout'];
		}

		// The widget re-points a form to the page's language; this document is
		// served in the site default, so pin the language to the form's own.
		$lang   = RYV_Form_Translations::get_language( $form_id );
		$filter = static function ( $current ) use ( $lang ) {
			return '' !== $lang ? $lang : $current;
		};
		add_filter( 'wpml_current_language', $filter, 99 );

		$html = '';
		try {
			$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
				array(
					'id'         => $this->site_src['widget_id'],
					'elType'     => 'widget',
					'widgetType' => 'ryv_eligibility_tool',
					'settings'   => $settings,
					'elements'   => array(),
				)
			);
			if ( $widget ) {
				ob_start();
				$widget->print_element();
				$html = (string) ob_get_clean();
			}
		} catch ( \Throwable $e ) {
			$html = '';
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		remove_filter( 'wpml_current_language', $filter, 99 );

		if ( '' === $html || false === strpos( $html, 'ryv-app-wrapper' ) ) {
			return '';
		}

		return $this->adjust_site_markup( $html, $form_id, $entry_node, $state_slug );
	}

	/**
	 * Apply ?state=, copy and label overrides to the widget's markup.
	 *
	 * @param string $html       Widget HTML.
	 * @param int    $form_id    Form ID.
	 * @param string $entry_node Entry node.
	 * @param string $state_slug State slug.
	 * @return string
	 */
	private function adjust_site_markup( string $html, int $form_id, string $entry_node, string $state_slug ): string {
		if ( '' !== $entry_node ) {
			$html = (string) preg_replace( '/data-entry-node="[^"]*"/', 'data-entry-node="' . esc_attr( $entry_node ) . '"', $html, 1 );
			$html = (string) preg_replace(
				'/data-language-editions="[^"]*"/',
				'data-language-editions="' . esc_attr( wp_json_encode( RYV_Shortcode::get_language_editions( $form_id, $state_slug ) ) ) . '"',
				$html,
				1
			);
		}

		if ( $this->args['labels'] ) {
			$args = $this->args['labels'];
			$html = (string) preg_replace_callback(
				'/data-labels="([^"]*)"/',
				static function ( $m ) use ( $args ) {
					$current = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
					$current = is_array( $current ) ? $current : array();

					return 'data-labels="' . esc_attr( wp_json_encode( array_merge( $current, $args ) ) ) . '"';
				},
				$html,
				1
			);
		}

		if ( '' !== $this->args['title_text'] ) {
			$text = esc_html( $this->args['title_text'] );
			$html = (string) preg_replace_callback( '/(<h2 class="ryv-header-title">).*?(<\/h2>)/s', static function ( $m ) use ( $text ) { return $m[1] . $text . $m[2]; }, $html, 1 );
		}
		if ( '' !== $this->args['desc_text'] ) {
			$text = esc_html( $this->args['desc_text'] );
			$html = (string) preg_replace_callback( '/(<p class="ryv-header-description">).*?(<\/p>)/s', static function ( $m ) use ( $text ) { return $m[1] . $text . $m[2]; }, $html, 1 );
		}

		// Note under the step list (the website prints its disclaimer there,
		// from a separate text block that is not part of the widget).
		$note = $this->args['note'];
		if ( null === $note ) {
			$note = self::default_note( RYV_Form_Translations::get_language( $form_id ) );
		}
		if ( '' !== $note && false !== strpos( $html, 'ryv-step-sidebar-slot' ) ) {
			$html = str_replace(
				'<div class="ryv-step-sidebar-slot"></div>',
				'<div class="ryv-step-sidebar-slot"></div><div class="ryv-embed-note"><p>' . esc_html( $note ) . '</p></div>',
				$html
			);
		}

		return $html;
	}

	/**
	 * The disclaimer the website shows under the step list.
	 *
	 * @return string
	 */
	public static function default_note( string $lang = '' ): string {
		/**
		 * Filters the default note shown under the steps in the embed.
		 *
		 * @param string $note Text.
		 */
		$text = 'es' === $lang
			? 'Restore Your Vote no proporciona asesoramiento jurídico. El uso de este sitio no crea una relación abogado-cliente.'
			: __( 'Restore Your Vote does not provide legal advice. Using this site does not create an attorney-client relationship.', 'ryv-eligibility-tool' );

		return (string) apply_filters( 'ryv_embed_default_note', $text, $lang );
	}

	/**
	 * Document <title>.
	 *
	 * @return string
	 */
	public function document_title(): string {
		return $this->form ? wp_strip_all_tags( $this->form->post_title ) : '';
	}

	/**
	 * Framing + hardening headers. Must run before any output.
	 *
	 * @param bool $preview_only Embedding is off; only an admin preview is served.
	 */
	private function send_headers( bool $preview_only ) {
		if ( headers_sent() ) {
			return;
		}

		// A server-wide or plugin-added X-Frame-Options would otherwise veto
		// the CSP allow-list below (browsers honor the stricter of the two).
		header_remove( 'X-Frame-Options' );

		// replace=false: never clobber a site-wide CSP some other layer set;
		// multiple CSP headers combine as an intersection, so this can only
		// ever be tightened further, not loosened, by them.
		header( 'Content-Security-Policy: frame-ancestors ' . self::frame_ancestors( $preview_only ), false );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Engine label keys a partner may reword, mapped to their query params.
	 */
	const LABEL_PARAMS = array(
		'label_start_over'  => 'startOver',
		'label_continue'    => 'continue',
		'label_submit'      => 'submit',
		'label_placeholder' => 'selectPlaceholder',
	);

	/**
	 * Plain text only, trimmed and length-capped (no markup, no control chars).
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Max characters.
	 * @return string
	 */
	private static function sanitize_copy( string $value, int $max ): string {
		$value = trim( sanitize_text_field( $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * Whitelisted label overrides from the query string.
	 *
	 * @param callable $text fn( string $key ): string.
	 * @return array<string,string> engine label key => text
	 */
	private static function read_labels( callable $text ): array {
		$out = array();
		foreach ( self::LABEL_PARAMS as $param => $key ) {
			$val = self::sanitize_copy( $text( $param ), 60 );
			if ( '' !== $val ) {
				$out[ $key ] = $val;
			}
		}

		return $out;
	}

	/**
	 * Whitelisted, sanitized presentation options from the query string.
	 *
	 * Read-only GET: nothing here changes state, so no nonce is involved.
	 *
	 * @return array<string,mixed>
	 */
	private function read_args(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only presentation options.
		$get = isset( $_GET ) ? wp_unslash( $_GET ) : array();

		$text = static function ( string $key ) use ( $get ): string {
			return ( isset( $get[ $key ] ) && is_string( $get[ $key ] ) ) ? $get[ $key ] : '';
		};

		$flag = static function ( string $key ) use ( $text ): bool {
			return ! in_array( strtolower( $text( $key ) ), array( '0', 'false', 'no', 'off' ), true );
		};

		return array(
			// '' = take it from the site's own widget (site look) / stacked (minimal).
			'layout'      => in_array( $text( 'layout' ), array( 'wizard', 'stacked' ), true ) ? $text( 'layout' ) : '',
			'look'        => 'plain' === $text( 'look' ) ? 'plain' : 'site',
			// null = the site's default note; '' = none; text = custom.
			'note'        => array_key_exists( 'note', $get ) ? ( '0' === $text( 'note' ) ? '' : self::sanitize_copy( $text( 'note' ), 400 ) ) : null,
			'title'       => $flag( 'title' ),
			'description' => $flag( 'description' ),
			'restart'     => $flag( 'restart' ),
			'steps'       => $flag( 'steps' ),
			'pad'         => $flag( 'pad' ),
			'fit'         => 'full' === $text( 'fit' ) ? 'full' : 'compact',
			'align'       => 'center' === $text( 'align' ) ? 'center' : 'left',
			'state'       => sanitize_title( $text( 'state' ) ),
			// Plain-text copy overrides. Length-capped; escaped on output.
			'title_text'  => self::sanitize_copy( $text( 'title_text' ), 160 ),
			'desc_text'   => self::sanitize_copy( $text( 'description_text' ), 400 ),
			'labels'      => self::read_labels( $text ),
			'primary'     => self::sanitize_color( $text( 'primary' ) ),
			'secondary'   => self::sanitize_color( $text( 'secondary' ) ),
			'text'        => self::sanitize_color( $text( 'text' ) ),
			'bg'          => self::sanitize_color( $text( 'bg' ) ),
		);
	}

	/**
	 * A hex color (with or without `#`, 3/4/6/8 digits) as `#rrggbb`, else ''.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_color( string $value ): string {
		$value = ltrim( trim( $value ), '#' );

		return preg_match( '/^(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value )
			? '#' . strtolower( $value )
			: '';
	}

	/**
	 * Enqueue the shared engine assets plus the iframe-side resize script.
	 * Same registered handles as the widget/shortcode - never separate copies.
	 */
	public function enqueue() {
		wp_enqueue_style( RYV_Assets::STYLE_HANDLE );
		wp_enqueue_script( RYV_Assets::SCRIPT_HANDLE );

		wp_enqueue_script(
			self::CHILD_HANDLE,
			RYV_PLUGIN_URL . 'assets/js/embed-child.js',
			array(),
			RYV_VERSION,
			true
		);

		// Site look: the source page's generated Elementor CSS carries every
		// Style-tab choice of that widget (fonts, sizes, card, steps...).
		if ( $this->site_src && class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( (int) $this->site_src['post_id'] )->enqueue();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$this->site_src = null;
			}
		}
	}

	/**
	 * Per-embed color overrides, appended after Global Styles (priority 6) so
	 * a partner-chosen accent wins over the site default.
	 */
	public function add_inline_styles() {
		$vars = array();

		if ( '' !== $this->args['primary'] ) {
			$vars[] = '--ryv-primary-color:' . $this->args['primary'];
		}
		if ( '' !== $this->args['secondary'] ) {
			$vars[] = '--ryv-secondary-color:' . $this->args['secondary'];
		}
		if ( '' !== $this->args['text'] ) {
			$vars[] = '--ryv-text-color:' . $this->args['text'];
		}
		if ( 'full' === $this->args['fit'] && ! $this->site_src ) {
			// Form and its title/description fill the frame together; the header
			// has its own width cap (--ryv-header-width) that would otherwise
			// stay at its narrow default above a full-width form.
			$vars[] = '--ryv-wrapper-max-width:100%';
			$vars[] = '--ryv-header-width:100%';
		}

		if ( $vars ) {
			// Values are whitelisted above (hex colors / fixed literals).
			if ( $this->site_src ) {
				// The widget's own Style-tab values are set on the widget
				// wrapper, so a partner's color has to out-rank them.
				wp_add_inline_style( RYV_Assets::STYLE_HANDLE, '.ryv-embed .elementor-element,.ryv-embed .ryv-app-wrapper{' . str_replace( ';', '!important;', implode( ';', $vars ) ) . '!important}' );
			} else {
				wp_add_inline_style( RYV_Assets::STYLE_HANDLE, ':root,.ryv-app-wrapper{' . implode( ';', $vars ) . '}' );
			}
		}
	}

	/**
	 * Reset theme chrome on the bare document. High specificity + printed last
	 * in <head> so whatever the active theme sets on html/body can't win.
	 */
	public function print_base_styles() {
		$padding = $this->args['pad'] ? '16px' : '0';
		$bg      = '' !== $this->args['bg'] ? $this->args['bg'] : 'transparent';

		// Title, description and questions share one axis: flush left by
		// default, or all centered. (Left-aligned header over a centered form
		// reads as two columns.)
		$align = 'center' === $this->args['align']
			? '.ryv-embed .ryv-header-align{text-align:center!important}.ryv-embed .ryv-app-wrapper{margin-left:auto!important;margin-right:auto!important}'
			: '.ryv-embed .ryv-header-align{text-align:left!important}.ryv-embed .ryv-app-wrapper{margin-left:0!important;margin-right:auto!important}';

		if ( $this->site_src ) {
			// Sidebar is sticky + height-capped for a full page; in a frame that
			// only fights the auto-height, so let it flow.
			$align = '.ryv-embed .ryv-layout-sidebar{position:static!important;max-height:none!important;overflow:visible!important}'
				. ( $this->args['steps'] ? '' : '.ryv-embed .ryv-step-sidebar-slot{display:none!important}' )
				. '.ryv-embed-note{margin:1.5rem 0 0;padding:0 0 0 1rem;border-left:4px solid #ec2927;color:#4a6577;font:400 15px/1.5 "District Pro",sans-serif}'
				. '.ryv-embed-note p{margin:0;font:inherit;color:inherit}';
		}

		printf(
			'<style id="ryv-embed-base">html,html body.ryv-embed-body{margin:0;padding:0;height:auto;min-height:0;background:%1$s!important}html body.ryv-embed-body{overflow:visible!important}.ryv-embed{box-sizing:border-box;padding:%2$s}%3$s</style>' . "\n",
			esc_html( $bg ),
			esc_html( $padding ),
			$align // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal, one of two branches above.
		);
	}

	/**
	 * Print the document.
	 */
	private function render_document() {
		add_action( 'wp_head', array( $this, 'print_base_styles' ), 999 );

		$form_id = (int) $this->form->ID;

		// `?state=alabama` starts straight on that state's first question,
		// skipping the picker. Unknown/unsupported state = the normal picker.
		$state_slug = '';
		$entry_node = '';
		if ( '' !== $this->args['state'] && class_exists( 'RYV_States_Sync' ) ) {
			$schema     = RYV_CPT::get_schema( $form_id );
			$entry_node = (string) RYV_States_Sync::find_start_node( $schema['nodes'] ?? array(), $schema['entry_node'] ?? null, $this->args['state'] );
			$state_slug = '' !== $entry_node ? $this->args['state'] : '';
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php // Any link inside the tool (help tooltips, result pages) must leave the frame, never load the full site inside a partner's page. ?>
<base target="_blank">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'ryv-embed-body' ); ?>>
<?php wp_body_open(); ?>
<main class="ryv-embed<?php echo $this->site_src ? ' ryv-embed--site' : ''; ?>">
		<?php
		$site_html = $this->site_src ? $this->render_site_widget( $form_id, $entry_node, $state_slug ) : '';
		if ( '' !== $site_html ) {
			echo '<div data-elementor-type="wp-page" class="elementor elementor-' . (int) $this->site_src['post_id'] . '">' . $site_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by Elementor/our widget, already escaped there.
		} else {
			// Minimal look (or no site widget to copy): our own markup.
			$this->render_header();

			printf(
				'<div class="ryv-app-wrapper" data-form-id="%d" data-show-start-over="%d" data-question-layout="%s" data-entry-node="%s" data-labels="%s" data-language-editions="%s"></div>',
				$form_id,
				$this->args['restart'] ? 1 : 0,
				esc_attr( '' !== $this->args['layout'] ? $this->args['layout'] : 'stacked' ),
				esc_attr( $entry_node ),
				esc_attr( wp_json_encode( array_merge( RYV_Form_Translations::ui_labels( RYV_Form_Translations::get_language( $form_id ) ), $this->args['labels'] ) ) ),
				esc_attr( wp_json_encode( RYV_Shortcode::get_language_editions( $form_id, $state_slug ) ) )
			);
		}
		?>
</main>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Optional title/description block - same markup (and therefore the same
	 * styling) as the Elementor widget's header.
	 */
	private function render_header() {
		$title_src = '' !== $this->args['title_text'] ? $this->args['title_text'] : $this->form->post_title;
		$desc_src  = '' !== $this->args['desc_text'] ? $this->args['desc_text'] : $this->form->post_excerpt;

		$title = ( $this->args['title'] && '' !== trim( $title_src ) )
			? sprintf( '<h2 class="ryv-header-title">%s</h2>', esc_html( $title_src ) )
			: '';

		$description = ( $this->args['description'] && '' !== trim( $desc_src ) )
			? sprintf( '<p class="ryv-header-description">%s</p>', esc_html( $desc_src ) )
			: '';

		if ( '' === $title && '' === $description ) {
			return;
		}

		printf( '<div class="ryv-header-align"><div class="ryv-header">%s%s</div></div>', $title, $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both pieces escaped above.
	}
}
