<?php
/**
 * Plugin Name:       Bomedia Quote Wizard
 * Plugin URI:        https://bomedia.net/
 * Description:       Multi-step quote request wizard for UV-LED printers and lasers. Brand-agnostic, multi-site, integrated with AgileCRM.
 * Version:           1.6.1
 * Author:            Bomedia SL
 * Author URI:        https://bomedia.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bomedia-quote-wizard
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 *
 * @package Bomedia\QuoteWizard
 */

declare( strict_types=1 );

namespace Bomedia\QuoteWizard;

defined( 'ABSPATH' ) || exit;

define( 'BQW_VERSION', '1.6.1' );
define( 'BQW_PLUGIN_FILE', __FILE__ );
define( 'BQW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BQW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BQW_TEXT_DOMAIN', 'bomedia-quote-wizard' );

require_once BQW_PLUGIN_DIR . 'includes/class-logger.php';
require_once BQW_PLUGIN_DIR . 'includes/class-settings.php';
require_once BQW_PLUGIN_DIR . 'includes/class-agilecrm-client.php';
require_once BQW_PLUGIN_DIR . 'includes/class-mailer.php';
require_once BQW_PLUGIN_DIR . 'includes/class-lead-cpt.php';
require_once BQW_PLUGIN_DIR . 'includes/class-captcha.php';
require_once BQW_PLUGIN_DIR . 'includes/class-openai-client.php';
require_once BQW_PLUGIN_DIR . 'includes/class-catalog-client.php';
require_once BQW_PLUGIN_DIR . 'includes/class-icons.php';
require_once BQW_PLUGIN_DIR . 'includes/class-product-meta.php';
require_once BQW_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once BQW_PLUGIN_DIR . 'includes/class-ajax.php';
require_once BQW_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	Plugin::instance()->boot();
} );
