<?php
/**
 * Podcast synchronization orchestrator.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrate canonical and backup feed synchronization.
 */
final class Sync_Service {
	private const LOCK = 'mrnp_sync_lock';

	/**
	 * Create the synchronization service.
	 *
	 * @param Feed_Client      $feeds Feed client.
	 * @param Comment_Importer $comments Comment importer.
	 */
	public function __construct(
		private Feed_Client $feeds,
		private Comment_Importer $comments
	) {}

	/**
	 * Synchronize primary and optional backup feeds.
	 *
	 * @param string $trigger Trigger source.
	 * @return array<string, mixed>|\WP_Error
	 * @throws \RuntimeException When the canonical feed cannot be read.
	 */
	public function run( string $trigger = 'manual' ) {
		if ( get_transient( self::LOCK ) ) {
			return new \WP_Error( 'mrnp_sync_locked', __( 'یک همگام‌سازی دیگر در حال اجراست.', 'mrn-podcaster' ) );
		}

		$primary_url = (string) Settings::get( 'primary_feed_url', '' );
		if ( ! $primary_url ) {
			return new \WP_Error( 'mrnp_missing_primary_feed', __( 'ابتدا نشانی فید اصلی را ذخیره کنید.', 'mrn-podcaster' ) );
		}

		set_transient( self::LOCK, time(), 10 * MINUTE_IN_SECONDS );
		$log_id = $this->start_log( $trigger );
		$result = array(
			'found'    => 0,
			'created'  => 0,
			'updated'  => 0,
			'comments' => 0,
			'errors'   => array(),
		);

		try {
			$primary = $this->feeds->fetch( $primary_url );
			if ( is_wp_error( $primary ) ) {
				throw new \RuntimeException( $primary->get_error_message() );
			}

			$backup     = array();
			$backup_url = (string) Settings::get( 'backup_feed_url', '' );
			if ( $backup_url ) {
				$backup = $this->feeds->fetch( $backup_url );
				if ( is_wp_error( $backup ) ) {
					/* translators: %s: backup feed error message. */
					$result['errors'][] = sprintf( __( 'فید پشتیبان: %s', 'mrn-podcaster' ), $backup->get_error_message() );
					$backup             = array();
				}
			}

			$this->save_podcast_info( $primary, $backup );
			$show_id = $this->upsert_show( $primary );
			if ( $show_id && Settings::get( 'import_comments', true ) ) {
				$result['comments'] += $this->comments->import_show( $show_id, $primary );
			}
			$backup_index    = $this->backup_index( (array) ( $backup['episodes'] ?? array() ) );
			$result['found'] = count( (array) $primary['episodes'] );

			foreach ( (array) $primary['episodes'] as $episode ) {
				$match                   = $this->match_backup( $episode, $backup_index );
				$episode['backup_audio'] = (string) ( $match['audio'] ?? '' );
				$episode['backup_guid']  = (string) ( $match['guid'] ?? '' );
				$episode['comments']     = array_merge( (array) $episode['comments'], (array) ( $match['comments'] ?? array() ) );
				$episode['comment_urls'] = array_values( array_unique( array_merge( (array) $episode['comment_urls'], (array) ( $match['comment_urls'] ?? array() ) ) ) );
				$sync                    = $this->upsert_episode( $episode );

				if ( is_wp_error( $sync ) ) {
					$result['errors'][] = $sync->get_error_message();
					continue;
				}

				++$result[ $sync['created'] ? 'created' : 'updated' ];
				if ( Settings::get( 'import_comments', true ) ) {
					$result['comments'] += $this->comments->import( (int) $sync['post_id'], $episode );
				}
			}

			update_option( 'mrnp_last_sync', time(), false );
			update_option( 'mrnp_last_sync_result', $result, false );
			$this->finish_log( $log_id, 'success', $result, $result['errors'] ? implode( "\n", $result['errors'] ) : __( 'همگام‌سازی کامل شد.', 'mrn-podcaster' ) );
		} catch ( \Throwable $error ) {
			$result['errors'][] = $error->getMessage();
			$this->finish_log( $log_id, 'failed', $result, $error->getMessage() );
			return new \WP_Error( 'mrnp_sync_failed', $error->getMessage(), $result );
		} finally {
			delete_transient( self::LOCK );
		}

		return $result;
	}

	/**
	 * Insert a new episode or update only source-owned fields.
	 *
	 * @param array<string, mixed> $episode Episode.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function upsert_episode( array $episode ) {
		$post_id      = $this->find_episode( (string) $episode['external_id'] );
		$created      = ! $post_id;
		$content      = wp_kses_post( (string) $episode['description'] );
		$excerpt      = wp_strip_all_tags( (string) $episode['excerpt'] );
		$title        = sanitize_text_field( (string) $episode['title'] );
		$postarr      = array(
			'post_type'      => Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'post_title'     => $title,
			'post_content'   => $content,
			'post_excerpt'   => $excerpt,
			'comment_status' => 'open',
		);
		$source_owned = true;

		if ( $episode['published'] ) {
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) $episode['published'] );
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
		}

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$current       = get_post( $post_id );
			$previous_hash = (string) get_post_meta( $post_id, '_mrnp_imported_content_hash', true );
			$current_hash  = hash( 'sha256', (string) $current->post_title . "\n" . (string) $current->post_content . "\n" . (string) $current->post_excerpt );
			if ( $previous_hash && ! hash_equals( $previous_hash, $current_hash ) ) {
				unset( $postarr['post_title'], $postarr['post_content'], $postarr['post_excerpt'] );
				$source_owned = false;
			}
		}

		$saved_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}
		$post_id = (int) $saved_id;

		$meta = array(
			'_mrnp_external_id'    => $episode['external_id'],
			'_mrnp_guid'           => $episode['guid'],
			'_mrnp_backup_guid'    => $episode['backup_guid'],
			'_mrnp_source_link'    => $episode['link'],
			'_mrnp_audio_primary'  => $episode['audio'],
			'_mrnp_audio_backup'   => $episode['backup_audio'],
			'_mrnp_duration'       => $episode['duration'],
			'_mrnp_episode_number' => $episode['episode_number'],
			'_mrnp_season_number'  => $episode['season_number'],
			'_mrnp_explicit'       => $episode['explicit'],
			'_mrnp_like_count'     => $episode['like_count'],
			'_mrnp_published_at'   => $episode['published'],
			'_mrnp_synced_at'      => time(),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $source_owned ) {
			$saved = get_post( $post_id );
			update_post_meta(
				$post_id,
				'_mrnp_imported_content_hash',
				hash( 'sha256', (string) $saved->post_title . "\n" . (string) $saved->post_content . "\n" . (string) $saved->post_excerpt )
			);
		}

		if ( ! has_post_thumbnail( $post_id ) && ! empty( $episode['image'] ) ) {
			$this->sideload_image( $post_id, (string) $episode['image'], $title );
		}
		if ( Settings::get( 'download_audio', false ) && ! empty( $episode['audio'] ) ) {
			$this->sideload_audio( $post_id, (string) $episode['audio'], $title );
		}

		return array(
			'post_id' => $post_id,
			'created' => $created,
		);
	}

	/**
	 * Find an episode by stable external identity.
	 *
	 * @param string $external_id External ID.
	 * @return int
	 */
	private function find_episode( string $external_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_mrnp_external_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Canonical identity lookup.
				'meta_value'     => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Canonical identity lookup.
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Maintain one editable show-level post for podcast information and comments.
	 *
	 * @param array<string, mixed> $feed Primary feed.
	 * @return int
	 */
	private function upsert_show( array $feed ): int {
		$ids          = get_posts(
			array(
				'post_type'      => Post_Type::SHOW_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		$post_id      = $ids ? (int) $ids[0] : 0;
		$title        = sanitize_text_field( (string) ( $feed['title'] ?? __( 'پادکست', 'mrn-podcaster' ) ) );
		$content      = wp_kses_post( (string) ( $feed['description'] ?? '' ) );
		$source_owned = true;
		$postarr      = array(
			'post_type'      => Post_Type::SHOW_TYPE,
			'post_status'    => 'publish',
			'post_title'     => $title,
			'post_content'   => $content,
			'post_excerpt'   => wp_trim_words( wp_strip_all_tags( $content ), 45, '' ),
			'comment_status' => 'open',
		);

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$current       = get_post( $post_id );
			$previous_hash = (string) get_post_meta( $post_id, '_mrnp_imported_content_hash', true );
			$current_hash  = hash( 'sha256', (string) $current->post_title . "\n" . (string) $current->post_content . "\n" . (string) $current->post_excerpt );
			if ( $previous_hash && ! hash_equals( $previous_hash, $current_hash ) ) {
				unset( $postarr['post_title'], $postarr['post_content'], $postarr['post_excerpt'] );
				$source_owned = false;
			}
		}

		$saved_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $saved_id ) ) {
			return 0;
		}
		$post_id = (int) $saved_id;
		update_post_meta( $post_id, '_mrnp_source_link', esc_url_raw( (string) ( $feed['link'] ?? '' ) ) );
		update_post_meta( $post_id, '_mrnp_synced_at', time() );

		if ( $source_owned ) {
			$saved = get_post( $post_id );
			update_post_meta( $post_id, '_mrnp_imported_content_hash', hash( 'sha256', (string) $saved->post_title . "\n" . (string) $saved->post_content . "\n" . (string) $saved->post_excerpt ) );
		}
		if ( ! has_post_thumbnail( $post_id ) && ! empty( $feed['image'] ) ) {
			$this->sideload_image( $post_id, (string) $feed['image'], $title );
		}
		return $post_id;
	}

	/**
	 * Build multi-key lookup for backup episodes.
	 *
	 * @param array<int, array<string, mixed>> $episodes Episodes.
	 * @return array<string, array<string, mixed>>
	 */
	private function backup_index( array $episodes ): array {
		$index = array();
		foreach ( $episodes as $episode ) {
			$index[ 'id:' . $episode['external_id'] ]                                = $episode;
			$index[ 'title:' . Normalizer::title_key( (string) $episode['title'] ) ] = $episode;
			if ( ! empty( $episode['episode_number'] ) ) {
				$index[ 'number:' . (int) $episode['season_number'] . ':' . (int) $episode['episode_number'] ] = $episode;
			}
		}
		return $index;
	}

	/**
	 * Match the backup feed without ever overriding primary identity.
	 *
	 * @param array<string, mixed>                $episode Primary episode.
	 * @param array<string, array<string, mixed>> $index Index.
	 * @return array<string, mixed>
	 */
	private function match_backup( array $episode, array $index ): array {
		$keys = array( 'id:' . $episode['external_id'] );
		if ( ! empty( $episode['episode_number'] ) ) {
			$keys[] = 'number:' . (int) $episode['season_number'] . ':' . (int) $episode['episode_number'];
		}
		$keys[] = 'title:' . Normalizer::title_key( (string) $episode['title'] );

		foreach ( $keys as $key ) {
			if ( isset( $index[ $key ] ) ) {
				return $index[ $key ];
			}
		}
		return array();
	}

	/**
	 * Store show-level data for themes and the dashboard.
	 *
	 * @param array<string, mixed> $primary Primary feed.
	 * @param array<string, mixed> $backup Backup feed.
	 * @return void
	 */
	private function save_podcast_info( array $primary, array $backup ): void {
		unset( $primary['episodes'], $backup['episodes'] );
		update_option(
			'mrnp_podcast_info',
			array(
				'primary'   => $primary,
				'backup'    => $backup,
				'synced_at' => time(),
			),
			false
		);
	}

	/**
	 * Download a remote cover only for posts without a manually selected image.
	 *
	 * @param int    $post_id Post.
	 * @param string $url URL.
	 * @param string $title Title.
	 * @return void
	 */
	private function sideload_image( int $post_id, string $url, string $title ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_sideload_image( $url, $post_id, $title, 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}

	/**
	 * Keep one local audio attachment per current primary URL.
	 *
	 * @param int    $post_id Post.
	 * @param string $url Audio URL.
	 * @param string $title Title.
	 * @return void
	 */
	private function sideload_audio( int $post_id, string $url, string $title ): void {
		$stored_source = (string) get_post_meta( $post_id, '_mrnp_audio_local_source', true );
		$stored_url    = (string) get_post_meta( $post_id, '_mrnp_audio_local', true );
		if ( $stored_source === $url && $stored_url ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = download_url( $url, 60 );
		if ( is_wp_error( $temp ) ) {
			return;
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( $path ) ? wp_basename( $path ) : sanitize_title( $title ) . '.mp3' );
		$file     = array(
			'name'     => $filename,
			'tmp_name' => $temp,
		);
		$id       = media_handle_sideload( $file, $post_id, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $temp );
			return;
		}

		update_post_meta( $post_id, '_mrnp_audio_local_attachment', (int) $id );
		update_post_meta( $post_id, '_mrnp_audio_local', wp_get_attachment_url( (int) $id ) );
		update_post_meta( $post_id, '_mrnp_audio_local_source', $url );
	}

	/**
	 * Create a sync log row.
	 *
	 * @param string $trigger Trigger.
	 * @return int
	 */
	private function start_log( string $trigger ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Dedicated operational log table.
			$wpdb->prefix . 'mrnp_sync_log',
			array(
				'started_at'   => current_time( 'mysql', true ),
				'status'       => 'running',
				'triggered_by' => sanitize_key( $trigger ),
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Complete a sync log row.
	 *
	 * @param int                  $id Log ID.
	 * @param string               $status Status.
	 * @param array<string, mixed> $result Result.
	 * @param string               $message Message.
	 * @return void
	 */
	private function finish_log( int $id, string $status, array $result, string $message ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated operational log table.
			$wpdb->prefix . 'mrnp_sync_log',
			array(
				'finished_at'      => current_time( 'mysql', true ),
				'status'           => $status,
				'episodes_found'   => (int) $result['found'],
				'episodes_created' => (int) $result['created'],
				'episodes_updated' => (int) $result['updated'],
				'comments_created' => (int) $result['comments'],
				'message'          => $message,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}
}
