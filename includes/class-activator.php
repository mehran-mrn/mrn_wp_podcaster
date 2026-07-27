<?php
/**
 * Activation and schema lifecycle.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Own plugin activation, deactivation and schema upgrades.
 */
final class Activator {
	private const DB_VERSION = '1';

	/**
	 * Activate defaults, schema and scheduler.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults() );
		self::install_schema();
		( new Post_Type() )->register_type();
		flush_rewrite_rules();
		update_option( 'mrnp_db_version', self::DB_VERSION );
	}

	/**
	 * Clear the scheduled event.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Scheduler::HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Apply schema upgrades lazily after plugin updates.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== (string) get_option( 'mrnp_db_version', '' ) ) {
			self::install_schema();
			update_option( 'mrnp_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Create the operational log table.
	 *
	 * @return void
	 */
	private static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'mrnp_sync_log';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'running',
			triggered_by varchar(20) NOT NULL DEFAULT 'cron',
			episodes_found int(10) unsigned NOT NULL DEFAULT 0,
			episodes_created int(10) unsigned NOT NULL DEFAULT 0,
			episodes_updated int(10) unsigned NOT NULL DEFAULT 0,
			comments_created int(10) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
