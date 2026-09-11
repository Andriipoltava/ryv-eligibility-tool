<?php
/**
 * "Repair State Slugs" admin submenu page: fronts
 * RYV_State_Slug_Repair::repair() - see that class's own docblock for what
 * it fixes and why. Same button/report/PRG pattern as
 * admin/class-ryv-partners-migration-page.php.
 *
 * Deliberately its own page so it (and RYV_State_Slug_Repair) can be
 * deleted as one unit once the live site has been repaired and QA'd.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_State_Slug_Repair_Page
 */
class RYV_State_Slug_Repair_Page {

	const CAPABILITY              = 'manage_options';
	const PAGE_SLUG                = 'ryv-repair-state-slugs';
	const NONCE_ACTION             = 'ryv_repair_state_slugs';
	const RESULT_TRANSIENT_PREFIX  = 'ryv_repair_state_slugs_result_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		RYV_States_Tools_Page::add_tab( self::PAGE_SLUG, __( 'Repair State Slugs', 'ryv-eligibility-tool' ), array( $this, 'render_page' ) );
		add_action( 'admin_post_ryv_repair_state_slugs', array( $this, 'handle_submission' ) );
	}

	/**
	 * Handle either button's submission, same Post/Redirect/Get + per-user
	 * transient pattern as RYV_Partners_Migration_Page.
	 */
	public function handle_submission() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to run this.', 'ryv-eligibility-tool' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'ryv_repair_state_slugs_nonce' );

		$dry_run = isset( $_POST['ryv_repair_state_slugs_dry_run'] );

		$result = class_exists( 'RYV_State_Slug_Repair' )
			? RYV_State_Slug_Repair::repair( $dry_run )
			: array( 'forms' => array(), 'states' => array(), 'totals' => array() );

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
	 * Pop (read + delete) this user's pending repair result.
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
			<h1><?php esc_html_e( 'Repair State Slugs', 'ryv-eligibility-tool' ); ?></h1>
			<p>
				<?php esc_html_e( 'One-time repair: 13 states (DC, NH, NJ, NM, NY, NC, ND, PR, RI, SC, SD, VA, WV) use a 2-letter abbreviation instead of the full state name as their form state-picker value and their State post\'s own State Slug field, in both the English and Spanish forms. This breaks the Form Node Editor (\"No nodes found\") and the widget\'s Auto-Detect State From Page feature for those 13 states. This tool changes both sides to the same full-name value every other state already uses - safe to run repeatedly, an already-correct value is left alone.', 'ryv-eligibility-tool' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, 'ryv_repair_state_slugs_nonce' ); ?>
				<input type="hidden" name="action" value="ryv_repair_state_slugs" />

				<p class="submit">
					<button type="submit" name="ryv_repair_state_slugs_dry_run" value="1" class="button button-secondary">
						<?php esc_html_e( 'Preview (dry run)', 'ryv-eligibility-tool' ); ?>
					</button>
					<button type="submit" class="button button-primary" onclick="return window.confirm('<?php echo esc_js( __( 'This will write to the database. Continue?', 'ryv-eligibility-tool' ) ); ?>');">
						<?php esc_html_e( 'Run Repair', 'ryv-eligibility-tool' ); ?>
					</button>
				</p>
			</form>

			<?php if ( null !== $result ) : ?>
				<h2>
					<?php echo $result['dry_run'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static strings only.
						? esc_html__( 'Dry Run Report', 'ryv-eligibility-tool' )
						: esc_html__( 'Repair Report', 'ryv-eligibility-tool' ); ?>
				</h2>
				<?php if ( $result['dry_run'] ) : ?>
					<p><em><?php esc_html_e( 'Nothing was written - this is a preview only.', 'ryv-eligibility-tool' ); ?></em></p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Forms', 'ryv-eligibility-tool' ); ?></h3>
				<?php if ( empty( $result['forms'] ) ) : ?>
					<p><?php esc_html_e( 'No forms had abbreviated state options.', 'ryv-eligibility-tool' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Form', 'ryv-eligibility-tool' ); ?></th>
								<th><?php esc_html_e( 'Changed Options', 'ryv-eligibility-tool' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['forms'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['title'] . ' (#' . $row['form_id'] . ')' ); ?></td>
									<td>
										<?php
										echo esc_html(
											implode(
												', ',
												array_map(
													static fn( $o ) => $o['from'] . ' → ' . $o['to'],
													$row['changed_options']
												)
											)
										);
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h3><?php esc_html_e( 'States', 'ryv-eligibility-tool' ); ?></h3>
				<?php if ( empty( $result['states'] ) ) : ?>
					<p><?php esc_html_e( 'No State posts had an abbreviated State Slug.', 'ryv-eligibility-tool' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'ryv-eligibility-tool' ); ?></th>
								<th><?php esc_html_e( 'Title', 'ryv-eligibility-tool' ); ?></th>
								<th><?php esc_html_e( 'Slug Change', 'ryv-eligibility-tool' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['states'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['post_id'] ); ?></td>
									<td><?php echo esc_html( $row['title'] ); ?></td>
									<td><?php echo esc_html( $row['from'] . ' → ' . $row['to'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Totals', 'ryv-eligibility-tool' ); ?></h3>
				<ul>
					<li><?php echo esc_html( sprintf( 'Forms changed: %d', $result['totals']['forms_changed'] ?? 0 ) ); ?></li>
					<li><?php echo esc_html( sprintf( 'Options changed: %d', $result['totals']['options_changed'] ?? 0 ) ); ?></li>
					<li><?php echo esc_html( sprintf( 'State posts changed: %d', $result['totals']['states_changed'] ?? 0 ) ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
