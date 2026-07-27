<?php
/**
 * Safe RSS/Atom podcast feed reader.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Safely fetch and normalize common podcast feed formats.
 */
final class Feed_Client {

	/**
	 * Fetch and parse a podcast feed.
	 *
	 * @param string $url Feed URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function fetch( string $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'mrnp_invalid_feed_url', __( 'نشانی فید معتبر نیست.', 'mrn-podcaster' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 25,
				'redirection'         => 3,
				'limit_response_size' => 8 * MB_IN_BYTES,
				'user-agent'          => 'MRN-Podcaster/' . MRNP_VERSION . '; ' . home_url( '/' ),
				'headers'             => array(
					'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: remote HTTP status code. */
			return new \WP_Error( 'mrnp_feed_http', sprintf( __( 'فید با کد HTTP %d پاسخ داد.', 'mrn-podcaster' ), $code ) );
		}

		return $this->parse( wp_remote_retrieve_body( $response ), $url );
	}

	/**
	 * Parse XML into normalized podcast data.
	 *
	 * @param string $xml XML.
	 * @param string $source_url Source URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $xml, string $source_url = '' ) {
		if ( '' === trim( $xml ) ) {
			return new \WP_Error( 'mrnp_empty_feed', __( 'فید خالی است.', 'mrn-podcaster' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$root     = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $root ) {
			$message = $errors ? trim( $errors[0]->message ) : __( 'ساختار XML قابل خواندن نیست.', 'mrn-podcaster' );
			return new \WP_Error( 'mrnp_invalid_xml', $message );
		}

		if ( isset( $root->channel ) ) {
			return $this->parse_rss( $root->channel, $source_url );
		}

		if ( 'feed' === $root->getName() ) {
			return $this->parse_atom( $root, $source_url );
		}

		return new \WP_Error( 'mrnp_unknown_feed', __( 'این XML یک فید RSS یا Atom شناخته‌شده نیست.', 'mrn-podcaster' ) );
	}

	/**
	 * Parse RSS 2.x.
	 *
	 * @param \SimpleXMLElement $channel Channel.
	 * @param string            $source_url Source URL.
	 * @return array<string, mixed>
	 */
	private function parse_rss( \SimpleXMLElement $channel, string $source_url ): array {
		$itunes            = $channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$content           = $channel->children( 'http://purl.org/rss/1.0/modules/content/' );
		$image             = $this->attribute( $itunes->image ?? null, 'href' );
		$image             = $image ? $image : (string) ( $channel->image->url ?? '' );
		$items             = array();
		$show_comment_urls = array_filter(
			array(
				(string) ( $channel->comments ?? '' ),
				(string) ( $channel->children( 'http://wellformedweb.org/CommentAPI/' )->commentRss ?? '' ),
			)
		);

		foreach ( $channel->item as $item ) {
			$item_itunes  = $item->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
			$item_content = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$podcast      = $item->children( 'https://podcastindex.org/namespace/1.0' );
			$media        = $item->children( 'http://search.yahoo.com/mrss/' );
			$audio        = $this->rss_audio( $item );
			$title        = trim( (string) $item->title );
			$published    = $this->timestamp( (string) ( $item->pubDate ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RSS element name.
			$guid         = trim( (string) ( $item->guid ?? '' ) );
			$description  = trim( (string) ( $item_content->encoded ?? $item->description ?? '' ) );
			$item_image   = $this->attribute( $item_itunes->image ?? null, 'href' );
			$item_image   = $item_image ? $item_image : $this->attribute( $media->thumbnail ?? null, 'url' );
			$comment_urls = array_filter(
				array(
					(string) ( $item->comments ?? '' ),
					(string) ( $item->children( 'http://wellformedweb.org/CommentAPI/' )->commentRss ?? '' ),
				)
			);

			$items[] = array(
				'external_id'    => Normalizer::external_id( $guid, $audio, $title, $published ),
				'guid'           => $guid,
				'title'          => $title ? $title : __( 'اپیزود بدون عنوان', 'mrn-podcaster' ),
				'description'    => $description,
				'excerpt'        => trim( (string) ( $item_itunes->summary ?? wp_strip_all_tags( $description ) ) ),
				'link'           => esc_url_raw( (string) ( $item->link ?? '' ) ),
				'audio'          => esc_url_raw( $audio ),
				'image'          => esc_url_raw( $item_image ? $item_image : $image ),
				'published'      => $published,
				'duration'       => Normalizer::duration( (string) ( $item_itunes->duration ?? '' ) ),
				'episode_number' => absint( (string) ( $item_itunes->episode ?? '' ) ),
				'season_number'  => absint( (string) ( $item_itunes->season ?? '' ) ),
				'explicit'       => $this->to_bool( (string) ( $item_itunes->explicit ?? '' ) ),
				'like_count'     => absint( (string) ( $podcast->likeCount ?? 0 ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Podcast namespace element.
				'comment_urls'   => array_values( array_map( 'esc_url_raw', $comment_urls ) ),
				'comments'       => $this->inline_comments( $item ),
			);
		}

		return array(
			'source_url'   => $source_url,
			'title'        => trim( (string) $channel->title ),
			'description'  => trim( (string) ( $content->encoded ?? $channel->description ?? '' ) ),
			'link'         => esc_url_raw( (string) ( $channel->link ?? '' ) ),
			'image'        => esc_url_raw( $image ),
			'author'       => trim( (string) ( $itunes->author ?? $channel->managingEditor ?? '' ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RSS element name.
			'language'     => sanitize_text_field( (string) ( $channel->language ?? '' ) ),
			'last_build'   => $this->timestamp( (string) ( $channel->lastBuildDate ?? '' ) ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RSS element name.
			'comment_urls' => array_values( array_map( 'esc_url_raw', $show_comment_urls ) ),
			'comments'     => $this->inline_comments( $channel ),
			'episodes'     => $items,
		);
	}

	/**
	 * Parse a practical subset of Atom podcast feeds.
	 *
	 * @param \SimpleXMLElement $feed Feed.
	 * @param string            $source_url Source URL.
	 * @return array<string, mixed>
	 */
	private function parse_atom( \SimpleXMLElement $feed, string $source_url ): array {
		$itunes            = $feed->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$items             = array();
		$image             = $this->attribute( $itunes->image ?? null, 'href' );
		$show_comment_urls = array();
		foreach ( $feed->link as $candidate ) {
			if ( 'replies' === $this->attribute( $candidate, 'rel' ) ) {
				$show_comment_urls[] = esc_url_raw( $this->attribute( $candidate, 'href' ) );
			}
		}

		foreach ( $feed->entry as $entry ) {
			$entry_itunes = $entry->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
			$audio        = '';
			$link         = '';
			foreach ( $entry->link as $candidate ) {
				$rel  = $this->attribute( $candidate, 'rel' );
				$type = $this->attribute( $candidate, 'type' );
				$href = $this->attribute( $candidate, 'href' );
				if ( 'enclosure' === $rel && str_starts_with( $type, 'audio/' ) ) {
					$audio = $href;
				} elseif ( ! $link && ( ! $rel || 'alternate' === $rel ) ) {
					$link = $href;
				}
			}
			$title       = trim( (string) $entry->title );
			$published   = $this->timestamp( (string) ( $entry->published ?? $entry->updated ?? '' ) );
			$guid        = trim( (string) $entry->id );
			$description = trim( (string) ( $entry->content ?? $entry->summary ?? '' ) );

			$items[] = array(
				'external_id'    => Normalizer::external_id( $guid, $audio, $title, $published ),
				'guid'           => $guid,
				'title'          => $title ? $title : __( 'اپیزود بدون عنوان', 'mrn-podcaster' ),
				'description'    => $description,
				'excerpt'        => wp_strip_all_tags( (string) ( $entry->summary ?? $description ) ),
				'link'           => esc_url_raw( $link ),
				'audio'          => esc_url_raw( $audio ),
				'image'          => esc_url_raw( $this->attribute( $entry_itunes->image ?? null, 'href' ) ? $this->attribute( $entry_itunes->image ?? null, 'href' ) : $image ),
				'published'      => $published,
				'duration'       => Normalizer::duration( (string) ( $entry_itunes->duration ?? '' ) ),
				'episode_number' => absint( (string) ( $entry_itunes->episode ?? '' ) ),
				'season_number'  => absint( (string) ( $entry_itunes->season ?? '' ) ),
				'explicit'       => $this->to_bool( (string) ( $entry_itunes->explicit ?? '' ) ),
				'like_count'     => 0,
				'comment_urls'   => array(),
				'comments'       => array(),
			);
		}

		return array(
			'source_url'   => $source_url,
			'title'        => trim( (string) $feed->title ),
			'description'  => trim( (string) ( $feed->subtitle ?? '' ) ),
			'link'         => '',
			'image'        => esc_url_raw( $image ),
			'author'       => trim( (string) ( $feed->author->name ?? '' ) ),
			'language'     => sanitize_text_field( (string) ( $feed['lang'] ?? '' ) ),
			'last_build'   => $this->timestamp( (string) ( $feed->updated ?? '' ) ),
			'comment_urls' => array_filter( $show_comment_urls ),
			'comments'     => $this->inline_comments( $feed ),
			'episodes'     => $items,
		);
	}

	/**
	 * Select the first audio enclosure.
	 *
	 * @param \SimpleXMLElement $item Item.
	 * @return string
	 */
	private function rss_audio( \SimpleXMLElement $item ): string {
		foreach ( $item->enclosure as $enclosure ) {
			$url  = $this->attribute( $enclosure, 'url' );
			$type = $this->attribute( $enclosure, 'type' );
			if ( $url && ( ! $type || str_starts_with( $type, 'audio/' ) ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Read a SimpleXML attribute safely.
	 *
	 * @param \SimpleXMLElement|null $node Node.
	 * @param string                 $name Attribute.
	 * @return string
	 */
	private function attribute( ?\SimpleXMLElement $node, string $name ): string {
		return $node ? trim( (string) ( $node[ $name ] ?? '' ) ) : '';
	}

	/**
	 * Normalize a date.
	 *
	 * @param string $date Date.
	 * @return int
	 */
	private function timestamp( string $date ): int {
		$timestamp = strtotime( $date );
		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Normalize podcast booleans.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function to_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', '1', 'explicit' ), true );
	}

	/**
	 * Read optional custom inline comments without requiring one feed vendor.
	 *
	 * @param \SimpleXMLElement $item Item.
	 * @return array<int, array<string, string>>
	 */
	private function inline_comments( \SimpleXMLElement $item ): array {
		$comments = array();
		foreach ( $item->getNamespaces( true ) as $namespace ) {
			$children = $item->children( $namespace );
			foreach ( $children->comment ?? array() as $comment ) {
				$text = trim( (string) $comment );
				if ( $text ) {
					$comments[] = array(
						'id'     => trim( (string) ( $comment['id'] ?? '' ) ),
						'author' => sanitize_text_field( (string) ( $comment['author'] ?? __( 'شنونده پادکست', 'mrn-podcaster' ) ) ),
						'text'   => $text,
						'date'   => sanitize_text_field( (string) ( $comment['date'] ?? '' ) ),
						'source' => sanitize_text_field( (string) ( $comment['source'] ?? 'feed' ) ),
					);
				}
			}
		}
		return $comments;
	}
}
