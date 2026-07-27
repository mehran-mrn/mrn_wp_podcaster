<?php
/**
 * Optional complete data removal.
 *
 * @package MRN_Podcaster
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = (array) get_option( 'mrnp_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$post_ids = get_posts(
	array(
		'post_type'      => array( 'mrnp_episode', 'mrnp_show' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $post_ids as $episode_post_id ) {
	$attachments = array_filter(
		array(
			(int) get_post_thumbnail_id( $episode_post_id ),
			(int) get_post_meta( $episode_post_id, '_mrnp_audio_local_attachment', true ),
		)
	);
	wp_delete_post( (int) $episode_post_id, true );
	foreach ( array_unique( $attachments ) as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
}

wp_clear_scheduled_hook( 'mrnp_sync_feeds' );
delete_option( 'mrnp_settings' );
delete_option( 'mrnp_db_version' );
delete_option( 'mrnp_last_sync' );
delete_option( 'mrnp_last_sync_result' );
delete_option( 'mrnp_podcast_info' );
delete_transient( 'mrnp_sync_lock' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mrnp_sync_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit user-selected uninstall cleanup.
