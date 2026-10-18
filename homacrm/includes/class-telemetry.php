<?php
/**
 * خبرِ نصب: افزونه هنگامِ فعال‌شدن، غیرفعال‌شدن و روزی یک بار یک خبرِ کوتاه به هما می‌فرستد
 * تا نصب‌های بی‌توکن هم دیده شوند و پشتیبانی بتواند کمک کند. چه چیزی: نشانیِ سایت، نسخه‌ها،
 * زبان، این‌که توکن وصل است یا نه، کدِ پرتال و ایمیلِ مدیرِ سایت. در تبِ About همین گفته شده.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Telemetry {

	const URL = 'https://plugin.homacrm.com/ping';

	public static function init() {
		add_action( 'homacrm_daily', array( __CLASS__, 'daily' ) );
		add_action( 'update_option_' . Settings::TOKEN, array( __CLASS__, 'token_changed' ) );
		add_action( 'add_option_' . Settings::TOKEN, array( __CLASS__, 'token_changed' ) );
		// نصب‌هایی که با به‌روزرسانی به این نسخه رسیده‌اند فعال‌سازیِ تازه ندارند؛ یک بار خبر بده.
		if ( get_option( 'homacrm_telemetry_v' ) !== HOMACRM_VERSION ) {
			update_option( 'homacrm_telemetry_v', HOMACRM_VERSION, false );
			add_action( 'init', static function () {
				self::send( 'update' );
			} );
		}
	}

	public static function activated() {
		self::send( 'activate' );
	}

	public static function deactivated() {
		self::send( 'deactivate' );
	}

	public static function daily() {
		self::send( 'daily' );
	}

	/** هر رویدادِ مهمِ مسیر (مثلاً اولین بازشدنِ تنظیمات) فقط یک بار برای هر نصب. */
	public static function once( $event ) {
		$done = (array) get_option( 'homacrm_tm_once', array() );
		if ( in_array( $event, $done, true ) ) {
			return;
		}
		$done[] = $event;
		update_option( 'homacrm_tm_once', $done, false );
		self::send( $event );
	}

	public static function token_changed() {
		self::send( 'token' );
	}

	private static function send( $event ) {
		$token  = class_exists( __NAMESPACE__ . '\\Settings' ) ? Settings::token() : '';
		$portal = (string) get_transient( 'homacrm_pay_portal' );
		$body   = array(
			'e'      => $event,
			'site'   => home_url(),
			'name'   => get_bloginfo( 'name' ),
			'email'  => (string) get_option( 'admin_email' ),
			'v'      => HOMACRM_VERSION,
			'wp'     => get_bloginfo( 'version' ),
			'wc'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php'    => PHP_VERSION,
			'locale' => get_locale(),
			'token'  => '' !== $token ? 1 : 0,
			'portal' => $portal,
			'id'     => self::site_id(),
		);
		wp_remote_post(
			self::URL,
			array(
				'timeout'  => 3,
				'blocking' => false,
				'body'     => $body,
				'headers'  => array( 'User-Agent' => 'HomaCRM-WordPress/' . HOMACRM_VERSION . '; ' . home_url() ),
			)
		);
	}

	/** شناسهٔ ثابت و بی‌معنای این نصب، تا تغییرِ دامنه نصبِ تازه حساب نشود. */
	private static function site_id() {
		$id = (string) get_option( 'homacrm_site_id', '' );
		if ( '' === $id ) {
			$id = wp_generate_uuid4();
			update_option( 'homacrm_site_id', $id, false );
		}
		return $id;
	}
}
