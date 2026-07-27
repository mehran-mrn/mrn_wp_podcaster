<?php
/**
 * Theme-independent presentation shortcodes.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Provide portable podcast presentation components.
 */
final class Shortcodes {
	/**
	 * Register public shortcodes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'mrnp_episode_carousel', array( $this, 'carousel' ) );
		add_shortcode( 'mrnp_listener_comments', array( $this, 'comments' ) );
		add_shortcode( 'mrnp_player', array( $this, 'player' ) );
	}

	/**
	 * Latest episode carousel.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function carousel( array $atts ): string {
		$atts  = shortcode_atts(
			array(
				'count'       => 8,
				'heading'     => __( 'آخرین اپیزودها', 'mrn-podcaster' ),
				'show_arrows' => 'yes',
				'class'       => '',
			),
			$atts,
			'mrnp_episode_carousel'
		);
		$count = min( 20, max( 1, absint( $atts['count'] ) ) );
		$query = new \WP_Query(
			array(
				'post_type'           => Post_Type::POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => $count,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( ! $query->have_posts() ) {
			return current_user_can( 'edit_posts' )
				? '<p class="mrnp-empty">' . esc_html__( 'هنوز اپیزودی همگام نشده است.', 'mrn-podcaster' ) . '</p>'
				: '';
		}

		Player::enqueue();
		ob_start();
		?>
		<section class="mrnp-carousel <?php echo esc_attr( sanitize_html_class( (string) $atts['class'] ) ); ?>" data-mrnp-carousel>
			<header class="mrnp-carousel__header">
				<h2><?php echo esc_html( (string) $atts['heading'] ); ?></h2>
				<?php if ( 'yes' === $atts['show_arrows'] ) : ?>
					<div class="mrnp-carousel__arrows">
						<button type="button" data-mrnp-carousel-next aria-label="<?php esc_attr_e( 'اپیزود بعدی', 'mrn-podcaster' ); ?>">→</button>
						<button type="button" data-mrnp-carousel-prev aria-label="<?php esc_attr_e( 'اپیزود قبلی', 'mrn-podcaster' ); ?>">←</button>
					</div>
				<?php endif; ?>
			</header>
			<div class="mrnp-carousel__track" data-mrnp-carousel-track>
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$image = get_the_post_thumbnail_url( get_the_ID(), 'medium_large' );
					/**
					 * Filter the episode artwork used by public player surfaces.
					 *
					 * @param string|false $image   Featured image URL, or false when absent.
					 * @param int          $post_id Episode post ID.
					 * @param string       $context Presentation context.
					 */
					$image = apply_filters( 'mrnp_episode_image_url', $image, get_the_ID(), 'carousel' );
					?>
					<article class="mrnp-episode-card">
						<div class="mrnp-episode-card__media">
							<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
								<?php
								if ( $image ) :
									?>
									<img src="<?php echo esc_url( (string) $image ); ?>" alt="" loading="lazy" decoding="async">
									<?php
									else :
										?>
									<span class="mrnp-episode-card__placeholder">MRN</span><?php endif; ?>
							</a>
							<?php echo Player::button( get_the_ID(), '', 'mrnp-episode-card__play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="mrnp-episode-card__duration"><?php echo esc_html( Normalizer::format_duration( (int) get_post_meta( get_the_ID(), '_mrnp_duration', true ) ) ); ?></span>
						</div>
						<div class="mrnp-episode-card__body">
							<?php $number = (int) get_post_meta( get_the_ID(), '_mrnp_episode_number', true ); ?>
							<?php /* translators: %d: episode number. */ ?>
							<p class="mrnp-episode-card__eyebrow"><?php echo esc_html( $number ? sprintf( __( 'اپیزود %d', 'mrn-podcaster' ), $number ) : get_the_date() ); ?></p>
							<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?></p>
						</div>
					</article>
				<?php endwhile; ?>
			</div>
		</section>
		<?php
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	/**
	 * Approved listener comments discovered by importers.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function comments( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'   => 3,
				'heading' => __( 'از شنوندگان', 'mrn-podcaster' ),
				'episode' => 0,
			),
			$atts,
			'mrnp_listener_comments'
		);
		$args = array(
			'status'     => 'approve',
			'type'       => 'comment',
			'number'     => min( 12, max( 1, absint( $atts['count'] ) ) ),
			'orderby'    => 'comment_date_gmt',
			'order'      => 'DESC',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to select imported listener comments.
				array(
					'key'     => '_mrnp_external_hash',
					'compare' => 'EXISTS',
				),
			),
		);
		if ( absint( $atts['episode'] ) ) {
			$args['post_id'] = absint( $atts['episode'] );
		} else {
			$args['post_type'] = array( Post_Type::POST_TYPE, Post_Type::SHOW_TYPE );
		}
		$comments = get_comments( $args );
		if ( ! $comments ) {
			return '';
		}

		ob_start();
		?>
		<section class="mrnp-listeners">
			<header><span aria-hidden="true">“</span><h2><?php echo esc_html( (string) $atts['heading'] ); ?></h2></header>
			<div class="mrnp-listeners__grid">
				<?php foreach ( $comments as $comment ) : ?>
					<figure class="mrnp-listener">
						<blockquote><?php echo wp_kses_post( wpautop( $comment->comment_content ) ); ?></blockquote>
						<figcaption>
							<strong><?php echo esc_html( $comment->comment_author ); ?></strong>
							<a href="<?php echo esc_url( get_comment_link( $comment ) ); ?>"><?php echo esc_html( get_the_title( $comment->comment_post_ID ) ); ?></a>
							<?php $source = get_comment_meta( $comment->comment_ID, '_mrnp_external_source', true ); ?>
							<?php
							if ( $source ) :
								?>
								<small><?php echo esc_html( $source ); ?></small><?php endif; ?>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Explicit player placement; global shell remains the single controller.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function player( array $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'mrnp_player' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : ( is_singular( Post_Type::POST_TYPE ) ? get_the_ID() : 0 );
		if ( ! $id ) {
			return '';
		}
		return Player::button( $id, __( 'پخش اپیزود', 'mrn-podcaster' ), 'mrnp-play-button--inline' );
	}
}
