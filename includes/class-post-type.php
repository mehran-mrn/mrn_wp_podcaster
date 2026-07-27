<?php
/**
 * Episode content model.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Register the episode and podcast show content models.
 */
final class Post_Type {
	public const POST_TYPE = 'mrnp_episode';
	public const SHOW_TYPE = 'mrnp_show';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'template_include', array( $this, 'template' ) );
	}

	/**
	 * Register the public episode post type.
	 *
	 * @return void
	 */
	public function register_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'اپیزودها', 'mrn-podcaster' ),
					'singular_name' => __( 'اپیزود', 'mrn-podcaster' ),
					'add_new_item'  => __( 'افزودن اپیزود', 'mrn-podcaster' ),
					'edit_item'     => __( 'ویرایش اپیزود', 'mrn-podcaster' ),
					'view_item'     => __( 'نمایش اپیزود', 'mrn-podcaster' ),
					'search_items'  => __( 'جست‌وجوی اپیزودها', 'mrn-podcaster' ),
					'not_found'     => __( 'اپیزودی پیدا نشد.', 'mrn-podcaster' ),
					'menu_name'     => __( 'اپیزودها', 'mrn-podcaster' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => 'podcast',
				'rewrite'         => array(
					'slug'       => 'episode',
					'with_front' => false,
				),
				'menu_icon'       => 'dashicons-microphone',
				'menu_position'   => 21,
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields', 'revisions', 'author' ),
				'taxonomies'      => array( 'category', 'post_tag' ),
				'show_in_menu'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		register_post_type(
			self::SHOW_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'پادکست', 'mrn-podcaster' ),
					'singular_name' => __( 'اطلاعات پادکست', 'mrn-podcaster' ),
					'edit_item'     => __( 'ویرایش اطلاعات پادکست', 'mrn-podcaster' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => array(
					'slug'       => 'podcast-about',
					'with_front' => false,
				),
				'show_in_menu'       => false,
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions' ),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Register public-safe metadata with REST schemas.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$definitions = array(
			'_mrnp_duration'       => 'integer',
			'_mrnp_episode_number' => 'integer',
			'_mrnp_season_number'  => 'integer',
			'_mrnp_like_count'     => 'integer',
			'_mrnp_local_likes'    => 'integer',
			'_mrnp_audio_primary'  => 'string',
			'_mrnp_audio_backup'   => 'string',
			'_mrnp_audio_local'    => 'string',
			'_mrnp_explicit'       => 'boolean',
		);

		foreach ( $definitions as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'string' === $type ? 'esc_url_raw' : null,
					'auth_callback'     => static fn(): bool => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/**
	 * Add operational columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$columns['mrnp_duration'] = __( 'مدت', 'mrn-podcaster' );
		$columns['mrnp_sources']  = __( 'منابع صوت', 'mrn-podcaster' );
		$columns['mrnp_sync']     = __( 'آخرین همگام‌سازی', 'mrn-podcaster' );
		return $columns;
	}

	/**
	 * Render operational columns.
	 *
	 * @param string $column Column.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function column_content( string $column, int $post_id ): void {
		if ( 'mrnp_duration' === $column ) {
			echo esc_html( Normalizer::format_duration( (int) get_post_meta( $post_id, '_mrnp_duration', true ) ) );
		}
		if ( 'mrnp_sources' === $column ) {
			$sources = array_filter(
				array(
					get_post_meta( $post_id, '_mrnp_audio_primary', true ),
					get_post_meta( $post_id, '_mrnp_audio_backup', true ),
					get_post_meta( $post_id, '_mrnp_audio_local', true ),
				)
			);
			/* translators: %d: number of available audio sources. */
			echo esc_html( sprintf( __( '%d منبع', 'mrn-podcaster' ), count( $sources ) ) );
		}
		if ( 'mrnp_sync' === $column ) {
			$timestamp = (int) get_post_meta( $post_id, '_mrnp_synced_at', true );
			echo esc_html( $timestamp ? human_time_diff( $timestamp, time() ) . ' ' . __( 'پیش', 'mrn-podcaster' ) : '—' );
		}
	}

	/**
	 * Use the plugin single template when the active theme has no specialized one.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public function template( string $template ): string {
		if ( is_singular( self::POST_TYPE ) ) {
			$theme_template = locate_template( array( 'single-' . self::POST_TYPE . '.php' ) );
			return $theme_template ? $theme_template : MRNP_PATH . 'templates/single-episode.php';
		}
		return $template;
	}
}
