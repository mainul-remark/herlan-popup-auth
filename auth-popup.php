<?php
/**
 * Plugin Name: Auth Popup
 * Plugin URI:  https://herlan.com/auth-popup
 * Description: SAAS-ready WordPress popup authentication — OTP via SSLCommerce iSMS Plus, Google OAuth, Facebook OAuth. All AJAX-driven.
 * Version:     1.0.6
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Herlan
 * Author URI:  https://herlan.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auth-popup
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AUTH_POPUP_VERSION',  '1.0.6' );
define( 'AUTH_POPUP_FILE',     __FILE__ );
define( 'AUTH_POPUP_PATH',     plugin_dir_path( __FILE__ ) );
define( 'AUTH_POPUP_URL',      plugin_dir_url( __FILE__ ) );
define( 'AUTH_POPUP_BASENAME', plugin_basename( __FILE__ ) );

require_once AUTH_POPUP_PATH . 'includes/class-auth-popup-core.php';
require_once AUTH_POPUP_PATH . 'includes/class-otp-manager.php';
require_once AUTH_POPUP_PATH . 'includes/class-sms-service.php';
require_once AUTH_POPUP_PATH . 'includes/class-user-auth.php';
require_once AUTH_POPUP_PATH . 'includes/class-oauth-handler.php';
require_once AUTH_POPUP_PATH . 'includes/class-address-manager.php';
require_once AUTH_POPUP_PATH . 'includes/class-ajax-handler.php';
require_once AUTH_POPUP_PATH . 'admin/class-admin-settings.php';
require_once AUTH_POPUP_PATH . 'public/class-public-frontend.php';

register_activation_hook( __FILE__,   [ 'Auth_Popup_Core', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'Auth_Popup_Core', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    Auth_Popup_Core::get_instance()->run();
} );
