<?php
/**
 * Plugin Name:       MRN Podcaster
 * Plugin URI:        https://github.com/mehran-mrn/mrn_wp_podcaster
 * Description:       همگام‌سازی حرفه‌ای فید پادکست، آرشیو اپیزودها، نظرات شنوندگان و پلیر سراسری.
 * Version:           0.2.5
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            MRN
 * Author URI:        https://github.com/mehran-mrn
 * Text Domain:       mrn-podcaster
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package MRN_Podcaster
 */

defined( 'ABSPATH' ) || exit;

define( 'MRNP_VERSION', '0.2.5' );
define( 'MRNP_FILE', __FILE__ );
define( 'MRNP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MRNP_URL', plugin_dir_url( __FILE__ ) );

require_once MRNP_PATH . 'includes/class-autoloader.php';

\MRN\Podcaster\Autoloader::register();

register_activation_hook( __FILE__, array( \MRN\Podcaster\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \MRN\Podcaster\Activator::class, 'deactivate' ) );

/**
 * Access the plugin composition root.
 *
 * @return \MRN\Podcaster\Plugin
 */
function mrn_podcaster(): \MRN\Podcaster\Plugin {
	return \MRN\Podcaster\Plugin::instance();
}

add_action( 'plugins_loaded', 'mrn_podcaster', 5 );
