<?php
/**
 * Standalone feed parser test.
 *
 * @package MRN_Podcaster
 */

define( 'ABSPATH', __DIR__ );
define( 'MRNP_VERSION', '0.2.5' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function __( string $value ): string {
	return $value;
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function esc_url_raw( string $value ): string {
	return filter_var( $value, FILTER_SANITIZE_URL );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function absint( $value ): int {
	return abs( (int) $value );
}

require_once dirname( __DIR__ ) . '/includes/class-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/class-feed-client.php';

use MRN\Podcaster\Feed_Client;

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
	xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
	xmlns:content="http://purl.org/rss/1.0/modules/content/">
	<channel>
		<title>MRN Test Podcast</title>
		<description>Show description</description>
		<language>fa-IR</language>
		<itunes:author>MRN</itunes:author>
		<itunes:image href="https://example.test/show.jpg"/>
		<item>
			<title>Episode 42</title>
			<guid>mrn-42</guid>
			<pubDate>Mon, 27 Jul 2026 10:00:00 +0000</pubDate>
			<content:encoded><![CDATA[<p>Episode <strong>body</strong>.</p>]]></content:encoded>
			<enclosure url="https://example.test/42.mp3" type="audio/mpeg" length="123"/>
			<itunes:duration>01:02:03</itunes:duration>
			<itunes:episode>42</itunes:episode>
			<itunes:season>2</itunes:season>
			<itunes:explicit>no</itunes:explicit>
		</item>
	</channel>
</rss>
XML;

$result = ( new Feed_Client() )->parse( $xml, 'https://example.test/feed.xml' );
if ( $result instanceof WP_Error ) {
	fwrite( STDERR, $result->get_error_message() . PHP_EOL );
	exit( 1 );
}

$episode = $result['episodes'][0] ?? array();
$checks  = array(
	'Show title'     => array( 'MRN Test Podcast', $result['title'] ?? null ),
	'Episode count'  => array( 1, count( $result['episodes'] ?? array() ) ),
	'Audio URL'      => array( 'https://example.test/42.mp3', $episode['audio'] ?? null ),
	'Duration'       => array( 3723, $episode['duration'] ?? null ),
	'Episode number' => array( 42, $episode['episode_number'] ?? null ),
	'Season number'  => array( 2, $episode['season_number'] ?? null ),
);

foreach ( $checks as $label => $values ) {
	if ( $values[0] !== $values[1] ) {
		fwrite( STDERR, "{$label} failed.\n" );
		exit( 1 );
	}
}

echo "Feed client tests passed.\n";
