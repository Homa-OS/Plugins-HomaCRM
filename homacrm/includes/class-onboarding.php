<?php
/**
 * شروعِ بی‌ریزش: بعد از فعال‌سازی کاربر مستقیم به صفحهٔ «HomaCRM» می‌رود و تا وقتی توکن وصل نشده،
 * در پیشخوان و صفحهٔ افزونه‌ها یک اعلانِ کوتاهِ «شروع» می‌بیند.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Onboarding {

	const FLAG = 'homacrm_go_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** از قلاب فعال‌سازی صدا زده می‌شود. */
	public static function activated() {
		set_transient( self::FLAG, 1, 60 );
	}

	public static function maybe_redirect() {
		if ( ! get_transient( self::FLAG ) ) {
			return;
		}
		delete_transient( self::FLAG );
		// فعال‌سازیِ گروهی یا شبکه‌ای را به صفحهٔ دیگری نمی‌بریم.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=homacrm' ) );
		exit;
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || '' !== Settings::token() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-info is-dismissible"><p><b>HomaCRM</b> — ' . esc_html__( 'برای شروع، توکنِ رایگان بگیرید و پیامک و ورود با موبایل را روشن کنید.', 'homacrm' ) . ' <a class="button button-primary" style="margin-inline-start:8px" href="' . esc_url( admin_url( 'admin.php?page=homacrm' ) ) . '">' . esc_html__( 'شروع', 'homacrm' ) . '</a></p></div>';
	}
}
