<?php
/**
 * Recurring feed synchronization.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Keep the recurring sync event aligned with settings.
 */
final class Scheduler {
	public const HOOK = 'mrnp_sync_feeds';

	/**
	 * Create the scheduler.
	 *
	 * @param Sync_Service $sync Synchronization service.
	 */
	public function __construct( private Sync_Service $sync ) {}

	/**
	 * Register intervals and event.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'intervals' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Add a useful 15-minute option.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function intervals( array $schedules ): array {
		$schedules['mrnp_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۱۵ دقیقه', 'mrn-podcaster' ),
		);
		return $schedules;
	}

	/**
	 * Keep the recurrence aligned with the saved setting.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$desired = (string) Settings::get( 'sync_interval', 'hourly' );
		$event   = wp_get_scheduled_event( self::HOOK );

		if ( $event && $event->schedule === $desired ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, $desired, self::HOOK );
	}

	/**
	 * Run the cron synchronizer.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->sync->run( 'cron' );
	}
}
