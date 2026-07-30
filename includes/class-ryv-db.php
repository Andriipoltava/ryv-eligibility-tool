<?php
/**
 * Custom tables for visitor-submission and admin-edit logging. Kept out of
 * postmeta because both grow unbounded over time and are queried/listed
 * independently of any single form's schema.
 *
 * @package RYV_Eligibility_Tool
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RYV_DB
 */
class RYV_DB {

	/**
	 * Option key storing the currently-installed schema version, so an
	 * in-place plugin update (no deactivate/reactivate) still gets new/changed
	 * tables via maybe_upgrade() on the next request.
	 */
	const DB_VERSION_OPTION = 'ryv_db_version';

	/**
	 * Bump this whenever install() changes.
	 */
	const DB_VERSION = '1.1';

	/**
	 * @return string
	 */
	public static function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ryv_submissions';
	}

	/**
	 * @return string
	 */
	public static function edit_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ryv_edit_log';
	}

	/**
	 * Run install() only if the stored version is out of date.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create (or update, via dbDelta's diffing) both tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$submissions     = self::submissions_table();
		$edit_log        = self::edit_log_table();

		// One row per (form_id, session_id): logProgress() upserts into this as
		// the visitor answers questions, so a browse-away-mid-questionnaire
		// still leaves their partial trail on record, not just completions.
		dbDelta(
			"CREATE TABLE {$submissions} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id BIGINT UNSIGNED NOT NULL,
				session_id VARCHAR(64) NOT NULL,
				state_id VARCHAR(100) NULL,
				current_node VARCHAR(191) NULL,
				answers LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY session_id (session_id)
			) {$charset_collate};"
		);

		// One row per builder Save - who/when, plus a structural JSON diff of
		// what changed in the schema (RYV_Schema_Diff), nullable since a save
		// with no actual change still records who/when.
		dbDelta(
			"CREATE TABLE {$edit_log} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id BIGINT UNSIGNED NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL,
				diff LONGTEXT NULL,
				edited_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id)
			) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
