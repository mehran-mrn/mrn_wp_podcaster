<?php
/**
 * Pure normalization helpers shared by sync and UI.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

/**
 * Normalize cross-feed identities, titles and durations.
 */
final class Normalizer {
	/**
	 * Normalize titles for cross-feed matching.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function title_key( string $title ): string {
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
		$title = preg_replace( '/[\p{P}\p{S}\s]+/u', '', $title );
		return (string) $title;
	}

	/**
	 * Convert iTunes duration formats to seconds.
	 *
	 * @param string|int $duration Duration.
	 * @return int
	 */
	public static function duration( $duration ): int {
		if ( is_numeric( $duration ) ) {
			return max( 0, (int) $duration );
		}

		$parts = array_map( 'intval', explode( ':', trim( (string) $duration ) ) );
		if ( 3 === count( $parts ) ) {
			return max( 0, $parts[0] * HOUR_IN_SECONDS + $parts[1] * MINUTE_IN_SECONDS + $parts[2] );
		}
		if ( 2 === count( $parts ) ) {
			return max( 0, $parts[0] * MINUTE_IN_SECONDS + $parts[1] );
		}
		return 0;
	}

	/**
	 * Human-readable duration.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return '—';
		}
		$hours = intdiv( $seconds, HOUR_IN_SECONDS );
		$mins  = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$secs  = $seconds % MINUTE_IN_SECONDS;
		return $hours > 0
			? sprintf( '%d:%02d:%02d', $hours, $mins, $secs )
			: sprintf( '%02d:%02d', $mins, $secs );
	}

	/**
	 * Build a stable external identifier.
	 *
	 * @param string $guid GUID.
	 * @param string $audio Audio URL.
	 * @param string $title Title.
	 * @param int    $published Published timestamp.
	 * @return string
	 */
	public static function external_id( string $guid, string $audio, string $title, int $published ): string {
		$seed = trim( $guid ) ? trim( $guid ) : trim( $audio );
		$seed = $seed ? $seed : self::title_key( $title ) . '|' . $published;
		return hash( 'sha256', $seed );
	}
}
