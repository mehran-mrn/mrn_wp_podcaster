<?php
/**
 * Standalone normalization tests.
 *
 * @package MRN_Podcaster
 */

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

require_once dirname( __DIR__ ) . '/includes/class-normalizer.php';

use MRN\Podcaster\Normalizer;

$failures = array();

$assert = static function ( $expected, $actual, string $message ) use ( &$failures ): void {
	if ( $expected !== $actual ) {
		$failures[] = $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	}
};

$assert( 3723, Normalizer::duration( '1:02:03' ), 'Three-part duration' );
$assert( 754, Normalizer::duration( '12:34' ), 'Two-part duration' );
$assert( 91, Normalizer::duration( 91 ), 'Numeric duration' );
$assert( '1:02:03', Normalizer::format_duration( 3723 ), 'Long duration format' );
$assert( '12:34', Normalizer::format_duration( 754 ), 'Short duration format' );
$assert( 'اپیزود۴۲', Normalizer::title_key( 'اپیزود ۴۲؛' ), 'Persian title normalization' );
$assert(
	hash( 'sha256', 'episode-guid' ),
	Normalizer::external_id( 'episode-guid', 'https://example.test/audio.mp3', 'Title', 1 ),
	'GUID has identity priority'
);

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Normalizer tests passed.\n";
