<?php
/*
Plugin Name:  PCIO VIS Member Event
Plugin URI:   https://www.pcio.dk/
Description:  Manages members and events for a voluntary organization.
Version:      1.5.0
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pcio-vis-member-event
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCIO_VIS_PLUGIN_FILE', __FILE__ );
define( 'PCIO_VIS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PCIO_VIS_REST_NS',     'pcio-vis/v1' );
define( 'PCIO_VIS_DB_VERSION',  '1.10.0' );

// Vendored libraries (Dompdf + php-qrcode) shared with extensions for PDF generation.
if ( is_readable( PCIO_VIS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once PCIO_VIS_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-caps.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-roles-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-meta-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-events-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-events-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-event-types-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-event-types-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-resources-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-resources-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-recurrence-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-recurrence.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-recurrence-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-workgroups-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-workgroups-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-mails-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-mails-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-pdf.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-newsletters-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-newsletter.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-newsletters-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-docs-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-docs-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-signups-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-signups-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-journal-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-journal-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-statistics-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-statistics-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-sync-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-sync.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-rolling-text-db.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-rolling-text-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-admin.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-installer.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-member-signup.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-member-signup-rest.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-member-signup-fulfillment.php';
require_once PCIO_VIS_PLUGIN_DIR . 'includes/class-pcio-vis-plugin.php';

register_activation_hook( __FILE__, function () {
    // Capabilities first, and deliberately not coupled to the schema migration.
    // The whole Vis admin menu is gated on vis_manage_settings, so if
    // PCIO_VIS_Installer::install() fatals or dies part-way, granting the caps
    // afterwards would never happen and the site would be left with no Vis menu
    // at all — an unusable plugin that still looks activated.
    PCIO_VIS_Caps::activate();
    PCIO_VIS_Installer::install();
    // Register rewrite rules then flush so /events/{slug}/ works immediately.
    PCIO_VIS_Plugin::instance()->register_rewrites();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    PCIO_VIS_Caps::deactivate();
    flush_rewrite_rules();
} );

PCIO_VIS_Plugin::instance();
