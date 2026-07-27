<?php
/**
 * Plugin composition root.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Compose all plugin services and public APIs.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Synchronization service.
	 *
	 * @var Sync_Service
	 */
	private Sync_Service $sync;

	/**
	 * Return the singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Compose services and register hooks.
	 *
	 * @return void
	 */
	private function boot(): void {
		Activator::maybe_upgrade();

		$post_type  = new Post_Type();
		$feeds      = new Feed_Client();
		$comments   = new Comment_Importer();
		$this->sync = new Sync_Service( $feeds, $comments );

		$post_type->register();
		( new Scheduler( $this->sync ) )->register();
		( new Player() )->register();
		( new Shortcodes() )->register();

		if ( is_admin() ) {
			( new Admin( $this->sync ) )->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'mrn-podcaster', false, dirname( plugin_basename( MRNP_FILE ) ) . '/languages' );
	}

	/**
	 * Register the small public interaction API.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'mrn-podcaster/v1',
			'/episodes/(?P<id>\d+)/like',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'like_episode' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
					),
				),
			)
		);
	}

	/**
	 * Record one anonymous browser like per episode.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function like_episode( \WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		if ( Post_Type::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'mrnp_missing_episode', __( 'اپیزود پیدا نشد.', 'mrn-podcaster' ), array( 'status' => 404 ) );
		}

		$cookie_key = 'mrnp_liked_' . $post_id;
		if ( isset( $_COOKIE[ $cookie_key ] ) ) {
			return rest_ensure_response(
				array(
					'liked' => true,
					'count' => (int) get_post_meta( $post_id, '_mrnp_local_likes', true ),
				)
			);
		}

		$count = (int) get_post_meta( $post_id, '_mrnp_local_likes', true ) + 1;
		update_post_meta( $post_id, '_mrnp_local_likes', $count );
		setcookie( $cookie_key, '1', time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );

		return rest_ensure_response(
			array(
				'liked' => true,
				'count' => $count,
			)
		);
	}

	/**
	 * Expose the synchronizer to integrations and WP-CLI.
	 *
	 * @return Sync_Service
	 */
	public function sync(): Sync_Service {
		return $this->sync;
	}
}
