<?php
/**
 * Typed settings access.
 *
 * @package MRN_Podcaster
 */

namespace MRN\Podcaster;

defined( 'ABSPATH' ) || exit;

/**
 * Central settings schema and sanitization.
 */
final class Settings {
	public const OPTION = 'mrnp_settings';

	/**
	 * Defaults kept in one place for activation, admin and runtime.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'primary_feed_url'    => '',
			'backup_feed_url'     => '',
			'sync_interval'       => 'hourly',
			'download_audio'      => false,
			'import_comments'     => true,
			'global_player'       => true,
			'delete_on_uninstall' => false,
			'platforms'           => array(),
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$settings = self::all();
		return $settings[ $key ] ?? $fallback;
	}

	/**
	 * Sanitize a complete settings payload.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$allowed_intervals = array( 'mrnp_fifteen_minutes', 'hourly', 'twicedaily', 'daily' );
		$platforms         = array();

		foreach ( (array) ( $input['platforms'] ?? array() ) as $platform ) {
			$name = sanitize_text_field( (string) ( $platform['name'] ?? '' ) );
			$url  = esc_url_raw( (string) ( $platform['url'] ?? '' ), array( 'http', 'https' ) );
			if ( $name && $url ) {
				$platforms[] = array(
					'name' => $name,
					'url'  => $url,
				);
			}
		}

		return array(
			'primary_feed_url'    => esc_url_raw( (string) ( $input['primary_feed_url'] ?? '' ), array( 'http', 'https' ) ),
			'backup_feed_url'     => esc_url_raw( (string) ( $input['backup_feed_url'] ?? '' ), array( 'http', 'https' ) ),
			'sync_interval'       => in_array( (string) ( $input['sync_interval'] ?? '' ), $allowed_intervals, true ) ? (string) $input['sync_interval'] : 'hourly',
			'download_audio'      => ! empty( $input['download_audio'] ),
			'import_comments'     => ! empty( $input['import_comments'] ),
			'global_player'       => ! empty( $input['global_player'] ),
			'delete_on_uninstall' => ! empty( $input['delete_on_uninstall'] ),
			'platforms'           => array_slice( $platforms, 0, 20 ),
		);
	}
}
