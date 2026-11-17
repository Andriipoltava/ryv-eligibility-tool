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

		$this->render_document();
		exit;
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
			'layout'      => 'wizard' === $text( 'layout' ) ? 'wizard' : 'stacked',
			'title'       => $flag( 'title' ),
			'description' => $flag( 'description' ),
			'restart'     => $flag( 'restart' ),
			'pad'         => $flag( 'pad' ),
			'fit'         => 'full' === $text( 'fit' ) ? 'full' : 'compact',
			'state'       => sanitize_title( $text( 'state' ) ),
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
		if ( 'full' === $this->args['fit'] ) {
			$vars[] = '--ryv-wrapper-max-width:100%';
		}

		if ( $vars ) {
			// Values are whitelisted above (hex colors / fixed literals).
			wp_add_inline_style( RYV_Assets::STYLE_HANDLE, ':root,.ryv-app-wrapper{' . implode( ';', $vars ) . '}' );
		}
	}

	/**
	 * Reset theme chrome on the bare document. High specificity + printed last
	 * in <head> so whatever the active theme sets on html/body can't win.
	 */
	public function print_base_styles() {
		$padding = $this->args['pad'] ? '16px' : '0';
		$bg      = '' !== $this->args['bg'] ? $this->args['bg'] : 'transparent';

		printf(
			'<style id="ryv-embed-base">html,html body.ryv-embed-body{margin:0;padding:0;height:auto;min-height:0;background:%1$s!important}.ryv-embed{box-sizing:border-box;padding:%2$s}</style>' . "\n",
			esc_html( $bg ),
			esc_html( $padding )
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
<main class="ryv-embed">
		<?php
		$this->render_header();

		printf(
			'<div class="ryv-app-wrapper" data-form-id="%d" data-show-start-over="%d" data-question-layout="%s" data-entry-node="%s" data-labels="%s" data-language-editions="%s"></div>',
			$form_id,
			$this->args['restart'] ? 1 : 0,
			esc_attr( $this->args['layout'] ),
			esc_attr( $entry_node ),
			esc_attr( wp_json_encode( RYV_Form_Translations::ui_labels( RYV_Form_Translations::get_language( $form_id ) ) ) ),
			esc_attr( wp_json_encode( RYV_Shortcode::get_language_editions( $form_id, $state_slug ) ) )
		);
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
		$title = ( $this->args['title'] && '' !== trim( $this->form->post_title ) )
			? sprintf( '<h2 class="ryv-header-title">%s</h2>', esc_html( $this->form->post_title ) )
			: '';

		$description = ( $this->args['description'] && '' !== trim( $this->form->post_excerpt ) )
			? sprintf( '<p class="ryv-header-description">%s</p>', esc_html( $this->form->post_excerpt ) )
			: '';

		if ( '' === $title && '' === $description ) {
			return;
		}

		printf( '<div class="ryv-header-align"><div class="ryv-header">%s%s</div></div>', $title, $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both pieces escaped above.
	}
}
