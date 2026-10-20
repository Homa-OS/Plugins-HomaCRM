<?php
/**
 * راه‌انداز: هر بخش فقط وقتی بار می‌شود که روشن باشد و پیش‌نیازش (مثلاً ووکامرس) نصب باشد.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		Settings::init();
		Log::init();
		Telemetry::init();
		Onboarding::init();

		if ( Settings::get( 'otp_enabled' ) ) {
			Login::init();
		}
		if ( Settings::get( 'crm_sync_users' ) ) {
			Crm_Sync::init();
		}
		if ( Settings::get( 'forms_enabled' ) ) {
			Forms::init();
		}
		if ( class_exists( 'WooCommerce' ) && Settings::get( 'woo_enabled' ) ) {
			Woocommerce::init();
		}
		if ( class_exists( 'WooCommerce' ) && ( Settings::get( 'shop_sync' ) || Settings::get( 'shop_invoice' ) || wp_next_scheduled( Shop::CRON ) ) ) {
			Shop::init();
		}
		if ( class_exists( 'WooCommerce' ) ) {
			Engage::init();
			Track::init();
		}
		if ( class_exists( 'WooCommerce' ) && Settings::get( 'shop_invoice' ) ) {
			require_once HOMACRM_DIR . 'includes/class-gateway.php';
			Gateway_Loader::init();
		}
		Abilities::init();
		Updater::init();

		// برای توسعه‌دهنده‌ها: do_action( 'homacrm_send_sms', $mobile, $message, $context ).
		add_action(
			'homacrm_send_sms',
			static function ( $mobile, $message, $context = 'api' ) {
				Sms::send( $mobile, $message, $context );
			},
			10,
			3
		);

		add_filter( 'plugin_action_links_' . plugin_basename( HOMACRM_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=homacrm' ) ) . '">' . esc_html__( 'تنظیمات', 'homacrm' ) . '</a>' );
		return $links;
	}
}
