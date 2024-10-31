<?php
/**
 * Plugin Name: ProBoast
 * Description: Receives images uploaded to ProBoast and creates image galleries.
 * Version: 1.0.0
 * Author: ProBoast.com
 * Author URI: https://proboast.com/
 *
 * @package           ProBoast
 * @author            ProBoast
 * @copyright         2021 ProBoast
 * @license           GPL-2.0-or-later
 */

define( 'PROBOAST__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROBOAST__MINIMUM_WP_VERSION', '5.0' );
define( 'PROBOAST_VERSION', '1.0.0' );

/**
 * ProBoast class.
 *
 * @category Class
 * @package  ProBoast
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     http://www.hashbangcode.com/
 */
require_once PROBOAST__PLUGIN_DIR . 'class-proboast.php';

register_activation_hook( __FILE__, array( 'ProBoast', 'plugin_activation' ) );

add_action( 'admin_init', array( 'ProBoast', 'check_version' ) );
add_action( 'init', array( 'ProBoast', 'custom_post_type' ) );
add_action( 'rest_api_init', array( 'ProBoast', 'rest_api_init' ) );
add_action( 'admin_menu', array( 'ProBoast', 'add_settings_page' ) );
add_action( 'admin_init', array( 'ProBoast', 'register_settings' ) );
add_action( 'admin_notices', array( 'ProBoast', 'general_admin_notice' ) );
