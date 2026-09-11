<?php
/**
 * Single "Tools" submenu page under RYV States, hosting every admin tool
 * that used to be its own top-level submenu item (Step Names, Generate
 * States, Migrate Partners, Repair State Slugs) as tabs on one page instead
 * - the RYV States menu had grown one line per one-off/maintenance tool,
 * most of which an admin opens rarely.
 *
 * Each tool's own class is completely unchanged otherwise: same
 * constants, same nonce actions, same `admin_init`/`admin_post_*` submit
 * handlers, same `render_page()` method. The only thing that moved is
 * *which* class calls `add_submenu_page()` - each tool now calls
 * `RYV_States_Tools_Page::add_tab()` instead of registering its own menu
 * entry, and this class is the only one that actually calls
 * `add_submenu_page()`. `add_tab()` just appends to a static array, so
 * call order across the tools' own constructors doesn't matter - they all
 * run during `plugins_loaded`, well before this class's own `admin_menu`
 * hook reads the accumulated list.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_States_Tools_Page
 */
class RYV_States_Tools_Page {

	const CAPABILITY = 'manage_options';
	const PAGE_SLUG   = 'ryv-states-tools';

	/**
	 * @var array<string, array{label: string, render: callable}>
	 */
	private static $tabs = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Registers one tab. Called by each individual tool's own class instead
	 * of that class calling `add_submenu_page()` itself.
	 *
	 * @param string   $slug   Unique tab slug (that tool's own PAGE_SLUG).
	 * @param string   $label  Tab label.
	 * @param callable $render That tool's own `render_page()` method.
	 */
	public static function add_tab( string $slug, string $label, callable $render ) {
		self::$tabs[ $slug ] = array(
			'label'  => $label,
			'render' => $render,
		);
	}

	/**
	 * Register the single "Tools" submenu page - only if at least one tool
	 * actually registered a tab (e.g. every ACF-gated tool skips this
	 * entirely when ACF isn't active).
	 */
	public function register_page() {
		if ( empty( self::$tabs ) ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . ( class_exists( 'RYV_States_CPT' ) ? RYV_States_CPT::POST_TYPE : 'ryv_states' ),
			__( 'Tools', 'ryv-eligibility-tool' ),
			__( 'Tools', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the tab nav plus the active tab's own render callback.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$tabs = self::$tabs;
		if ( empty( $tabs ) ) {
			return;
		}

		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active    = isset( $tabs[ $requested ] ) ? $requested : array_key_first( $tabs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RYV States Tools', 'ryv-eligibility-tool' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'ryv_states', 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'edit.php' ) ) ); ?>"
						class="nav-tab <?php echo $slug === $active ? 'nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="ryv-tools-tab-panel" style="margin-top: 1.5em;">
				<?php call_user_func( $tabs[ $active ]['render'] ); ?>
			</div>
		</div>
		<?php
	}
}
