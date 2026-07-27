<?php
/**
 * External listener comment collection and deduplication.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Collect external comments and insert them into WordPress moderation.
 */
final class Comment_Importer {
	/**
	 * Discover and import comments for an episode.
	 *
	 * Imported comments are intentionally pending until a site moderator
	 * approves them. Providers may add comments through mrnp_external_comments.
	 *
	 * @param int                  $post_id Episode post.
	 * @param array<string, mixed> $episode Normalized episode.
	 * @return int Number created.
	 */
	public function import( int $post_id, array $episode ): int {
		$comments = (array) ( $episode['comments'] ?? array() );

		foreach ( (array) ( $episode['comment_urls'] ?? array() ) as $url ) {
			$comments = array_merge( $comments, $this->fetch_comment_feed( (string) $url ) );
		}

		/**
		 * Extend comment discovery for platform-specific APIs.
		 *
		 * Provider callbacks receive normalized episode data and the list of
		 * platform name/URL pairs saved by the administrator.
		 *
		 * @param array<int, array<string, string>> $comments Comments.
		 * @param array<string, mixed>               $episode Episode.
		 * @param array<int, array<string, string>>  $platforms Platforms.
		 */
		$comments = apply_filters( 'mrnp_external_comments', $comments, $episode, (array) Settings::get( 'platforms', array() ) );
		return $this->insert_comments( $post_id, $comments );
	}

	/**
	 * Import show-level feed comments and discover JSON-LD reviews on saved
	 * platform pages. This runs once per synchronization, not once per episode.
	 *
	 * @param int                  $post_id Show post.
	 * @param array<string, mixed> $show Normalized show.
	 * @return int Number created.
	 */
	public function import_show( int $post_id, array $show ): int {
		$comments = (array) ( $show['comments'] ?? array() );
		foreach ( (array) ( $show['comment_urls'] ?? array() ) as $url ) {
			$comments = array_merge( $comments, $this->fetch_comment_feed( (string) $url ) );
		}
		foreach ( array_slice( (array) Settings::get( 'platforms', array() ), 0, 8 ) as $platform ) {
			$comments = array_merge( $comments, $this->fetch_platform_reviews( (array) $platform ) );
		}

		/**
		 * Extend show-level comment discovery using authenticated provider APIs.
		 *
		 * @param array<int, array<string, string>> $comments Comments.
		 * @param array<string, mixed>               $show Show data.
		 * @param array<int, array<string, string>>  $platforms Platforms.
		 */
		$comments = apply_filters( 'mrnp_external_show_comments', $comments, $show, (array) Settings::get( 'platforms', array() ) );
		return $this->insert_comments( $post_id, $comments );
	}

	/**
	 * Insert pending comments with global external deduplication.
	 *
	 * @param int                                   $post_id Destination post.
	 * @param array<int, array<string, string|int>> $comments Comments.
	 * @return int
	 */
	private function insert_comments( int $post_id, array $comments ): int {
		$created = 0;

		foreach ( $comments as $comment ) {
			$text = wp_kses_post( (string) ( $comment['text'] ?? '' ) );
			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			$source = sanitize_text_field( (string) ( $comment['source'] ?? 'feed' ) );
			$key    = (string) ( $comment['id'] ?? '' );
			$hash   = hash( 'sha256', $source . '|' . ( $key ? $key : Normalizer::title_key( wp_strip_all_tags( $text ) ) ) );
			if ( $this->exists( $hash ) ) {
				continue;
			}

			$date = strtotime( (string) ( $comment['date'] ?? '' ) );
			$id   = wp_insert_comment(
				array(
					'comment_post_ID'    => $post_id,
					'comment_author'     => sanitize_text_field( (string) ( $comment['author'] ?? __( 'شنونده پادکست', 'mrn-podcaster' ) ) ),
					'comment_author_url' => esc_url_raw( (string) ( $comment['author_url'] ?? '' ) ),
					'comment_content'    => $text,
					'comment_type'       => '',
					'comment_approved'   => 0,
					'comment_date'       => $date ? wp_date( 'Y-m-d H:i:s', $date ) : current_time( 'mysql' ),
					'comment_date_gmt'   => $date ? gmdate( 'Y-m-d H:i:s', $date ) : current_time( 'mysql', true ),
					'comment_agent'      => 'MRN Podcaster/' . MRNP_VERSION,
				)
			);

			if ( $id ) {
				add_comment_meta( $id, '_mrnp_external_hash', $hash, true );
				add_comment_meta( $id, '_mrnp_external_source', $source, true );
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Extract public Review/Comment schema objects from one configured platform
	 * page. Platforms without structured public comments simply return none.
	 *
	 * @param array<string, string> $platform Platform name and URL.
	 * @return array<int, array<string, string>>
	 */
	private function fetch_platform_reviews( array $platform ): array {
		$url  = (string) ( $platform['url'] ?? '' );
		$name = sanitize_text_field( (string) ( $platform['name'] ?? wp_parse_url( $url, PHP_URL_HOST ) ) );
		if ( ! wp_http_validate_url( $url ) || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 2,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; MRN-Podcaster/' . MRNP_VERSION . ')',
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . wp_remote_retrieve_body( $response ), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return array();
		}

		$comments = array();
		foreach ( $document->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( $script->getAttribute( 'type' ) ) ) ) {
				continue;
			}
			$decoded = json_decode( trim( $script->textContent ), true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
			if ( is_array( $decoded ) ) {
				$this->collect_schema_comments( $decoded, $comments, $name ? $name : $url );
			}
		}
		return array_slice( $comments, 0, 100 );
	}

	/**
	 * Recursively collect Schema.org Review and Comment objects.
	 *
	 * @param array<string|int, mixed>          $node JSON-LD node.
	 * @param array<int, array<string, string>> $comments Results.
	 * @param string                            $source Source label.
	 * @return void
	 */
	private function collect_schema_comments( array $node, array &$comments, string $source ): void {
		$type = (array) ( $node['@type'] ?? array() );
		if ( array_intersect( array( 'Review', 'Comment', 'UserReview' ), $type ) ) {
			$text   = (string) ( $node['reviewBody'] ?? $node['commentText'] ?? $node['text'] ?? $node['description'] ?? '' );
			$author = $node['author'] ?? $node['creator'] ?? '';
			if ( is_array( $author ) ) {
				$author = (string) ( $author['name'] ?? '' );
			}
			if ( $text ) {
				$comments[] = array(
					'id'         => (string) ( $node['@id'] ?? $node['url'] ?? hash( 'sha256', $source . '|' . $text ) ),
					'author'     => sanitize_text_field( (string) $author ),
					'author_url' => '',
					'text'       => $text,
					'date'       => sanitize_text_field( (string) ( $node['datePublished'] ?? $node['dateCreated'] ?? '' ) ),
					'source'     => $source,
				);
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_schema_comments( $value, $comments, $source );
			}
		}
	}

	/**
	 * Check global external identity.
	 *
	 * @param string $hash Hash.
	 * @return bool
	 */
	private function exists( string $hash ): bool {
		$ids = get_comments(
			array(
				'meta_key'       => '_mrnp_external_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- External identity lookup.
					'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- External identity lookup.
				'count'          => false,
				'fields'         => 'ids',
				'status'         => 'all',
				'number'         => 1,
			)
		);
		return ! empty( $ids );
	}

	/**
	 * Read standard RSS comment feeds referenced by an episode.
	 *
	 * @param string $url Comment feed URL.
	 * @return array<int, array<string, string>>
	 */
	private function fetch_comment_feed( string $url ): array {
		if ( ! wp_http_validate_url( $url ) ) {
			return array();
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 2,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'MRN-Podcaster/' . MRNP_VERSION,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( wp_remote_retrieve_body( $response ), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml || ! isset( $xml->channel ) ) {
			return array();
		}

		$comments = array();
		foreach ( $xml->channel->item as $item ) {
			$dc         = $item->children( 'http://purl.org/dc/elements/1.1/' );
			$content    = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$comments[] = array(
				'id'         => trim( (string) ( $item->guid ?? $item->link ?? '' ) ),
				'author'     => sanitize_text_field( (string) ( $dc->creator ?? $item->author ?? __( 'شنونده پادکست', 'mrn-podcaster' ) ) ),
				'text'       => (string) ( $content->encoded ?? $item->description ?? '' ),
				'date'       => sanitize_text_field( (string) ( $item->pubDate ?? '' ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RSS element name.
				'source'     => esc_url_raw( $url ),
				'author_url' => '',
			);
		}
		return $comments;
	}
}
