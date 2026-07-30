<?php
/**
 * "Activity Log" admin submenu page: lists recent visitor answer-trail
 * submissions (RYV_Submission_Log) and recent builder saves (RYV_Edit_Log),
 * across every form. Read-only - manage_options only, since submission
 * answers can include sensitive felony-conviction history.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Logs_Page
 */
class RYV_Logs_Page {

	/**
	 * Capability required to view.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook slug for this submenu page.
	 */
	const PAGE_SLUG = 'ryv-activity-log';

	/**
	 * Rows per page, for both tables. Two independent paginated tables share
	 * one screen, so each gets its own query arg (sub_page / edit_page)
	 * rather than a single `paged`.
	 */
	const PER_PAGE = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu page under the ryv_forms CPT menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . RYV_CPT::POST_TYPE,
			__( 'Activity Log', 'ryv-eligibility-tool' ),
			__( 'Activity Log', 'ryv-eligibility-tool' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the submenu page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$sub_page  = isset( $_GET['sub_page'] ) ? max( 1, absint( $_GET['sub_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
		$edit_page = isset( $_GET['edit_page'] ) ? max( 1, absint( $_GET['edit_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$submissions_total = RYV_Submission_Log::count_all();
		$edits_total       = RYV_Edit_Log::count_all();

		$submissions_pages = max( 1, (int) ceil( $submissions_total / self::PER_PAGE ) );
		$edits_pages       = max( 1, (int) ceil( $edits_total / self::PER_PAGE ) );

		// Clamp rather than 404 - a stale bookmarked/shared link to a page
		// that no longer exists (rows deleted since) should just show the
		// last real page instead of erroring.
		$sub_page  = min( $sub_page, $submissions_pages );
		$edit_page = min( $edit_page, $edits_pages );

		$submissions = RYV_Submission_Log::recent( self::PER_PAGE, ( $sub_page - 1 ) * self::PER_PAGE );
		$edits       = RYV_Edit_Log::recent( self::PER_PAGE, ( $edit_page - 1 ) * self::PER_PAGE );

		require RYV_PLUGIN_DIR . 'admin/views/logs-page.php';
	}

	/**
	 * Render a Previous/Next pager for one of the two tables. Uses
	 * add_query_arg() against the current URL, so the other table's page
	 * param (and any other query string) is preserved untouched.
	 *
	 * @param string $query_arg    'sub_page' or 'edit_page'.
	 * @param int    $current_page Current 1-indexed page.
	 * @param int    $total_pages  Total page count.
	 * @return string HTML, or '' if there's only one page.
	 */
	public static function pagination_control( string $query_arg, int $current_page, int $total_pages ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$prev_page = max( 1, $current_page - 1 );
		$next_page = min( $total_pages, $current_page + 1 );

		ob_start();
		?>
		<div class="tablenav-pages" style="margin: 0.75em 0;">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'ryv-eligibility-tool' ),
					$current_page,
					$total_pages
				);
				?>
			</span>
			<?php if ( $current_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $query_arg, $prev_page ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'ryv-eligibility-tool' ); ?></a>
			<?php else : ?>
				<span class="button disabled">&laquo; <?php esc_html_e( 'Previous', 'ryv-eligibility-tool' ); ?></span>
			<?php endif; ?>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( $query_arg, $next_page ) ); ?>"><?php esc_html_e( 'Next', 'ryv-eligibility-tool' ); ?> &raquo;</a>
			<?php else : ?>
				<span class="button disabled"><?php esc_html_e( 'Next', 'ryv-eligibility-tool' ); ?> &raquo;</span>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * A form's title, tolerant of the post having since been deleted (a
	 * submission/edit log row can outlive the form it was recorded against).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function form_title( int $post_id ): string {
		$title = get_the_title( $post_id );

		if ( '' === $title ) {
			/* translators: %d: post ID */
			return sprintf( __( '(deleted form #%d)', 'ryv-eligibility-tool' ), $post_id );
		}

		return $title;
	}

	/**
	 * A user's display name, tolerant of the account having since been deleted.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			/* translators: %d: user ID */
			return sprintf( __( '(deleted user #%d)', 'ryv-eligibility-tool' ), $user_id );
		}

		return $user->display_name;
	}

	/**
	 * Format a UTC `Y-m-d H:i:s` timestamp (as stored via current_time( 'mysql', true ))
	 * in the site's local timezone/format.
	 *
	 * @param string $mysql_datetime_gmt UTC datetime string.
	 * @return string
	 */
	public static function format_datetime( string $mysql_datetime_gmt ): string {
		$timestamp = strtotime( $mysql_datetime_gmt . ' UTC' );

		if ( ! $timestamp ) {
			return $mysql_datetime_gmt;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
