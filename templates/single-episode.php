<?php
/**
 * Fallback single episode template.
 *
 * @package MRN_Podcaster
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="mrnp-single">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'mrnp-single__article' ); ?>>
			<header class="mrnp-single__header">
				<?php
				if ( has_post_thumbnail() ) :
					?>
					<div class="mrnp-single__cover"><?php the_post_thumbnail( 'large' ); ?></div><?php endif; ?>
				<div>
					<?php /* translators: 1: episode number, 2: episode duration. */ ?>
					<p><?php echo esc_html( sprintf( __( 'اپیزود %1$d • %2$s', 'mrn-podcaster' ), (int) get_post_meta( get_the_ID(), '_mrnp_episode_number', true ), \MRN\Podcaster\Normalizer::format_duration( (int) get_post_meta( get_the_ID(), '_mrnp_duration', true ) ) ) ); ?></p>
					<h1><?php the_title(); ?></h1>
					<?php echo \MRN\Podcaster\Player::button( get_the_ID(), __( 'پخش این اپیزود', 'mrn-podcaster' ), 'mrnp-play-button--hero' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</header>
			<div class="mrnp-single__content"><?php the_content(); ?></div>
			<footer class="mrnp-single__footer">
				<?php $source = get_post_meta( get_the_ID(), '_mrnp_source_link', true ); ?>
				<?php
				if ( $source ) :
					?>
					<a href="<?php echo esc_url( $source ); ?>" rel="external nofollow"><?php esc_html_e( 'صفحه اصلی اپیزود', 'mrn-podcaster' ); ?></a><?php endif; ?>
			</footer>
			<?php comments_template(); ?>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
