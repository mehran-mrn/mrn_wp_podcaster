<?php
/**
 * Global podcast player.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Render and hydrate the persistent multi-source audio player.
 */
final class Player {
	/**
	 * Whether frontend assets have been queued.
	 *
	 * @var bool
	 */
	private static bool $assets = false;

	/**
	 * Whether the shared player shell has been rendered.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Whether an inline play button needs the shared shell.
	 *
	 * @var bool
	 */
	private static bool $requested = false;

	/**
	 * Register frontend hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'wp_footer', array( $this, 'global_player' ), 5 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Queue global-player assets before footer scripts are printed.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( Settings::get( 'global_player', true ) ) {
			self::enqueue();
		}
	}

	/**
	 * Mark sites with the global player enabled.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		if ( Settings::get( 'global_player', true ) ) {
			$classes[] = 'mrnp-has-player';
		}
		return $classes;
	}

	/**
	 * Enqueue player assets once.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;

		wp_enqueue_style( 'mrnp-player', MRNP_URL . 'assets/css/player.css', array(), MRNP_VERSION );
		wp_enqueue_script( 'mrnp-player', MRNP_URL . 'assets/js/player.js', array(), MRNP_VERSION, true );
		wp_script_add_data( 'mrnp-player', 'strategy', 'defer' );
		wp_localize_script(
			'mrnp-player',
			'mrnpPlayerConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'mrn-podcaster/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'labels'  => array(
					'play'        => __( 'پخش', 'mrn-podcaster' ),
					'pause'       => __( 'مکث', 'mrn-podcaster' ),
					'minimize'    => __( 'کوچک‌کردن پلیر', 'mrn-podcaster' ),
					'expand'      => __( 'بازکردن پلیر', 'mrn-podcaster' ),
					'primary'     => __( 'فید اصلی', 'mrn-podcaster' ),
					'backup'      => __( 'فید پشتیبان', 'mrn-podcaster' ),
					'local'       => __( 'نسخه محلی', 'mrn-podcaster' ),
					'unavailable' => __( 'منبع صوت در دسترس نیست.', 'mrn-podcaster' ),
				),
			)
		);
	}

	/**
	 * Render the shared bottom player.
	 *
	 * @return void
	 */
	public function global_player(): void {
		if ( ( ! Settings::get( 'global_player', true ) && ! self::$requested ) || self::$rendered ) {
			return;
		}

		self::enqueue();
		$post_id = is_singular( Post_Type::POST_TYPE ) ? get_queried_object_id() : 0;
		self::render( $post_id );
	}

	/**
	 * Render a player shell.
	 *
	 * @param int $post_id Optional initial episode.
	 * @return void
	 */
	public static function render( int $post_id = 0 ): void {
		if ( self::$rendered ) {
			return;
		}
		self::$rendered = true;
		self::enqueue();
		$data = $post_id ? self::episode_data( $post_id ) : array();
		?>
		<section class="mrnp-player<?php echo $data ? ' is-ready' : ''; ?>" data-mrnp-player data-initial="<?php echo esc_attr( wp_json_encode( $data ) ); ?>" aria-label="<?php esc_attr_e( 'پلیر پادکست', 'mrn-podcaster' ); ?>">
			<audio data-mrnp-audio preload="metadata"></audio>
			<div class="mrnp-player__progress">
				<input data-mrnp-seek type="range" min="0" max="1000" value="0" aria-label="<?php esc_attr_e( 'موقعیت پخش', 'mrn-podcaster' ); ?>">
			</div>
			<div class="mrnp-player__main">
				<img class="mrnp-player__cover" data-mrnp-cover src="" alt="" width="64" height="64">
				<div class="mrnp-player__identity">
					<strong data-mrnp-title><?php esc_html_e( 'یک اپیزود را انتخاب کنید', 'mrn-podcaster' ); ?></strong>
					<span data-mrnp-meta><?php esc_html_e( 'MRN Podcaster', 'mrn-podcaster' ); ?></span>
				</div>
				<div class="mrnp-player__transport">
					<button type="button" data-mrnp-skip="-15" aria-label="<?php esc_attr_e( '۱۵ ثانیه عقب', 'mrn-podcaster' ); ?>"><span aria-hidden="true">↶</span><small>15</small></button>
					<button class="mrnp-player__play" type="button" data-mrnp-toggle aria-label="<?php esc_attr_e( 'پخش', 'mrn-podcaster' ); ?>">
						<svg class="mrnp-icon-play" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 11 7-11 7Z"/></svg>
						<svg class="mrnp-icon-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>
					</button>
					<button type="button" data-mrnp-skip="30" aria-label="<?php esc_attr_e( '۳۰ ثانیه جلو', 'mrn-podcaster' ); ?>"><span aria-hidden="true">↷</span><small>30</small></button>
				</div>
				<div class="mrnp-player__time" aria-live="off"><span data-mrnp-current>00:00</span><i>/</i><span data-mrnp-duration>00:00</span></div>
				<div class="mrnp-player__options">
					<label class="mrnp-player__source"><span><?php esc_html_e( 'منبع', 'mrn-podcaster' ); ?></span><select data-mrnp-source aria-label="<?php esc_attr_e( 'منبع صوت', 'mrn-podcaster' ); ?>"></select></label>
					<label class="mrnp-player__speed"><span><?php esc_html_e( 'سرعت', 'mrn-podcaster' ); ?></span><select data-mrnp-speed aria-label="<?php esc_attr_e( 'سرعت پخش', 'mrn-podcaster' ); ?>"><option value=".75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option><option value="1.75">1.75×</option><option value="2">2×</option></select></label>
					<label class="mrnp-player__volume"><span aria-hidden="true">◖</span><input data-mrnp-volume type="range" min="0" max="1" step=".05" value="1" aria-label="<?php esc_attr_e( 'صدا', 'mrn-podcaster' ); ?>"></label>
					<a data-mrnp-download href="#" download aria-label="<?php esc_attr_e( 'دریافت فایل صوتی', 'mrn-podcaster' ); ?>">↓</a>
					<button type="button" data-mrnp-minimize aria-label="<?php esc_attr_e( 'کوچک‌کردن پلیر', 'mrn-podcaster' ); ?>">⌄</button>
					<button type="button" data-mrnp-close aria-label="<?php esc_attr_e( 'بستن پلیر', 'mrn-podcaster' ); ?>">×</button>
				</div>
			</div>
			<button class="mrnp-player__mini" type="button" data-mrnp-expand aria-label="<?php esc_attr_e( 'بازکردن پلیر', 'mrn-podcaster' ); ?>"><span class="mrnp-player__mini-bars" aria-hidden="true"><i></i><i></i><i></i></span><strong data-mrnp-mini-title><?php esc_html_e( 'پادکست', 'mrn-podcaster' ); ?></strong></button>
		</section>
		<?php
	}

	/**
	 * Serialize one published episode for the player.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function episode_data( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return array();
		}

			$sources = array_filter(
				array(
					'primary' => esc_url_raw( (string) get_post_meta( $post_id, '_mrnp_audio_primary', true ) ),
					'backup'  => esc_url_raw( (string) get_post_meta( $post_id, '_mrnp_audio_backup', true ) ),
					'local'   => esc_url_raw( (string) get_post_meta( $post_id, '_mrnp_audio_local', true ) ),
				)
			);
			$cover   = get_the_post_thumbnail_url( $post, 'thumbnail' );
			/**
			 * Filter the episode artwork used by public player surfaces.
			 *
			 * @param string|false $cover   Featured image URL, or false when absent.
			 * @param int          $post_id Episode post ID.
			 * @param string       $context Presentation context.
			 */
			$cover = apply_filters( 'mrnp_episode_image_url', $cover, $post_id, 'player' );

			return array(
				'id'       => $post_id,
				'title'    => get_the_title( $post ),
				'url'      => get_permalink( $post ),
				'cover'    => $cover ? esc_url_raw( (string) $cover ) : '',
				'duration' => (int) get_post_meta( $post_id, '_mrnp_duration', true ),
				'episode'  => (int) get_post_meta( $post_id, '_mrnp_episode_number', true ),
				'sources'  => $sources,
			);
	}

	/**
	 * A reusable play button carrying episode JSON.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $label Accessible label.
	 * @param string $css_class Extra CSS class.
	 * @return string
	 */
	public static function button( int $post_id, string $label = '', string $css_class = '' ): string {
		$data = self::episode_data( $post_id );
		if ( ! $data || empty( $data['sources'] ) ) {
			return '';
		}
		self::$requested = true;
		self::enqueue();
		/* translators: %s: episode title. */
		$label = $label ? $label : sprintf( __( 'پخش %s', 'mrn-podcaster' ), $data['title'] );
		return sprintf(
			'<button type="button" class="mrnp-play-button %1$s" data-mrnp-play="%2$s" aria-label="%3$s"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 11 7-11 7Z"/></svg><span>%4$s</span></button>',
			esc_attr( $css_class ),
			esc_attr( wp_json_encode( $data ) ),
			esc_attr( $label ),
			esc_html__( 'پخش', 'mrn-podcaster' )
		);
	}
}
