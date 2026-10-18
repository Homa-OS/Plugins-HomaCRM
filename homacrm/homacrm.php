<?php
/**
 * Plugin Name:       HomaCRM — هما
 * Plugin URI:        https://homacrm.com
 * Description:       افزونهٔ رسمیِ هما برای وردپرس و ووکامرس: پیامک، ورود و ثبت‌نام با پیامک، اطلاع‌رسانیِ سفارش، همگام‌سازیِ مشتری و سفارش با CRMِ هما، فرمِ سرنخ و تیکت، و ابزارهای MCP — همه با یک توکنِ API.
 * Version:           3.13.10
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            HomaCRM (فناوران نگین شهر نوین)
 * Author URI:        https://homacrm.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       homacrm
 * Domain Path:       /languages
 *
 * @package HomaCRM
 */

defined( 'ABSPATH' ) || exit;

define( 'HOMACRM_VERSION', '3.13.10' );
define( 'HOMACRM_RELEASED', '2026-10-18' );
define( 'HOMACRM_FILE', __FILE__ );
define( 'HOMACRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOMACRM_URL', plugin_dir_url( __FILE__ ) );
define( 'HOMACRM_DEFAULT_API', 'https://homacrm.com/api/v1/external' );

spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'HomaCRM\\' ) !== 0 ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, 8 ) ) );
		$file = HOMACRM_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// سازگاری با ذخیره‌سازیِ سفارشِ جدیدِ ووکامرس (HPOS).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'HomaCRM\\Log', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'homacrm_daily' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'homacrm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		HomaCRM\Plugin::instance()->boot();
	}
);
