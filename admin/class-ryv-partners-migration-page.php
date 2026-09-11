<?php
/**
 * "Migrate Partners" admin submenu page: WP Engine fallback for
 * `wp ryv migrate-partners` (includes/class-ryv-partners-migration-cli.php)
 * in case WP-CLI isn't reachable on that environment. Same
 * RYV_Partners_Migration logic underneath, same report either way.
 *
 * Deliberately its own page/menu item so it (and the migration class and
 * CLI command it fronts) can be deleted as one unit after launch - see
 * class-ryv-partners-migration.php's own docblock. Nothing here runs
 * automatically: only a `manage_options` admin clicking one of the two
 * nonce-protected buttons below triggers anything.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_Partners_Migration_Page
 */
class RYV_Partners_Migration_Page {

	const CAPABILITY          = 'manage_options';
	const PAGE_SLUG           = 'ryv-migrate-partners';
	const NONCE_ACTION        = 'ryv_migrate_partners';
	const RESULT_TRANSIENT_PREFIX = 'ryv_migrate_partners_result_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		RYV_States_Tools_Page::add_tab( self::PAGE_SLUG, __( 'Migrate Partners', 'ryv-eligibility-tool' ), array( $this, 'render_page' ) );
		add_action( 'admin_post_ryv_migrate_partners', array( $this, 'handle_submission' ) );
	}

	/**
	 * Handle either button's submission (dry run or real run), same
	 * Post/Redirect/Get + per-user transient pattern
	 * RYV_States_Generate_Page uses for its own repair buttons.
	 */
	public function handle_submission() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to run this.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_migrate_partners_nonce' );

		$dry_run = isset( $_POST['ryv_migrate_partners_dry_run'] );
		$post_id = isset( $_POST['ryv_migrate_partners_post_id'] ) ? absint( wp_unslash( $_POST['ryv_migrate_partners_post_id'] ) ) : 0;

		$result = class_exists( 'RYV_Partners_Migration' )
			? ( new RYV_Partners_Migration() )->run( $dry_run, $post_id ? $post_id : null )
			: array(
				'rows'   => array(),
				'totals' => array(),
			);

		$result['dry_run'] = $dry_run;

		set_transient(
			self::RESULT_TRANSIENT_PREFIX . get_current_user_id(),
			$result,
			MINUTE_IN_SECONDS * 5
		);

		wp_safe_redirect( admin_url( 'edit.php?post_type=ryv_states&page=' . RYV_States_Tools_Page::PAGE_SLUG . '&tab=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Pop (read + delete) this user's pending migration result.
	 *
	 * @return array|null
	 */
	private function consume_result(): ?array {
		$key    = self::RESULT_TRANSIENT_PREFIX . get_current_user_id();
		$result = get_transient( $key );

		if ( false === $result ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Render the page: the two action buttons, plus the last run's report
	 * (if any) below them.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'ryv-eligibility-tool' ) );
		}

		$result = $this->consume_result();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migrate Partners', 'ryv-eligibility-tool' ); ?></h1>
			<p>
				<?php esc_html_e( 'One-time migration: copies each state\'s legacy single-partner fields (Partner Name / Partner Link URL) into the new "Featured Partners" repeater. Safe to run repeatedly - a post whose repeater already has rows is skipped, and the old fields are never changed or cleared.', 'ryv-eligibility-tool' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'This is a fallback for WP-CLI (wp ryv migrate-partners) in case it isn\'t reachable on this environment - both run the exact same migration.', 'ryv-eligibility-tool' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, 'ryv_migrate_partners_nonce' ); ?>
				<input type="hidden" name="action" value="ryv_migrate_partners" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="ryv_migrate_partners_post_id"><?php esc_html_e( 'Single post ID (optional)', 'ryv-eligibility-tool' ); ?></label></th>
						<td>
							<input type="number" min="1" id="ryv_migrate_partners_post_id" name="ryv_migrate_partners_post_id" class="small-text" />
							<p class="description"><?php esc_html_e( 'Leave empty to run against every State post.', 'ryv-eligibility-tool' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="ryv_migrate_partners_dry_run" value="1" class="button button-secondary">
						<?php esc_html_e( 'Preview (dry run)', 'ryv-eligibility-tool' ); ?>
					</button>
					<button type="submit" class="button button-primary" onclick="return window.confirm('<?php echo esc_js( __( 'This will write to the database. Continue?', 'ryv-eligibility-tool' ) ); ?>');">
						<?php esc_html_e( 'Run Migration', 'ryv-eligibility-tool' ); ?>
					</button>
				</p>
			</form>

			<?php if ( null !== $result ) : ?>
				<h2>
					<?php echo $result['dry_run'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static strings only.
						? esc_html__( 'Dry Run Report', 'ryv-eligibility-tool' )
						: esc_html__( 'Migration Report', 'ryv-eligibility-tool' ); ?>
				</h2>
				<?php if ( $result['dry_run'] ) : ?>
					<p><em><?php esc_html_e( 'Nothing was written - this is a preview only.', 'ryv-eligibility-tool' ); ?></em></p>
				<?php endif; ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Title', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Status', 'ryv-eligibility-tool' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'ryv-eligibility-tool' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['id'] ); ?></td>
								<td><?php echo esc_html( $row['title'] ); ?></td>
								<td><?php echo esc_html( RYV_Partners_Migration::status_label( $row['status'] ) ); ?></td>
								<td><?php echo esc_html( $row['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Totals', 'ryv-eligibility-tool' ); ?></h3>
				<ul>
					<?php foreach ( $result['totals'] as $status => $count ) : ?>
						<?php if ( $count > 0 ) : ?>
							<li><?php echo esc_html( RYV_Partners_Migration::status_label( $status ) . ': ' . $count ); ?></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
