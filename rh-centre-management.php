<?php
/**
 * Plugin Name: Centre Management
 * Description: Course calendar and booking system with [rhcm_schedule] and [rhcm_course] shortcodes.
 * Version:     1.4.4
 * Author:      Queen Mary Sailing Club
 * Text Domain: rh-centre-management
 */

defined( 'ABSPATH' ) || exit;

define( 'RHCM_VERSION', '1.4.4' );
define( 'RHCM_PATH',    plugin_dir_path( __FILE__ ) );
define( 'RHCM_URL',     plugin_dir_url( __FILE__ ) );

require_once RHCM_PATH . 'includes/class-db.php';
require_once RHCM_PATH . 'includes/class-admin.php';
require_once RHCM_PATH . 'includes/class-shortcodes.php';

// Auto-update via GitHub Releases (Plugin Update Checker)
$puc_path = RHCM_PATH . 'plugin-update-checker-5.6/plugin-update-checker.php';
if ( file_exists( $puc_path ) ) {
    require_once $puc_path;
    $checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/henryed07/centremanagement',
        __FILE__,
        'rh-centre-management'
    );
    $checker->setBranch( 'main' );
    // Uncomment and add token if the GitHub repo is private:
    // $checker->setAuthentication( 'YOUR-GITHUB-TOKEN' );
}

register_activation_hook( __FILE__, [ 'RHCM_DB', 'install' ] );

add_action( 'plugins_loaded', function () {
    if ( version_compare( get_option( 'rhcm_db_version', '0' ), RHCM_VERSION, '<' ) ) {
        RHCM_DB::install();
    }
    new RHCM_Admin();
    new RHCM_Shortcodes();
} );
