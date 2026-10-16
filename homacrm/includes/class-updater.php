<?php
/**
 * به‌روزرسانیِ خودکارِ افزونه از GitHub Releases (Homa-OS/homacrm-wordpress) و خبردادن به مدیرِ سایت.
 *
 * - هر ۱۲ ساعت آخرین نسخه را می‌خواند؛ نسخهٔ تازه در «به‌روزرسانی‌ها»ی وردپرس با یک کلیک نصب می‌شود.
 * - اعلانِ پیشخوان با خلاصهٔ تغییرات تا وقتی نصب نشده.
 * - یک بار برای هر نسخه: ایمیل به ایمیلِ مدیرِ سایت و (اگر روشن باشد) پیامک به موبایلِ مدیر از خطِ همین پرتال.
 * - «به‌روزرسانیِ خودکار» (اختیاری): نسخهٔ تازه بی‌کلیک نصب می‌شود.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const REPO      = 'Homa-OS/homacrm-wordpress';
	const CACHE     = 'homacrm_latest_release';
	const NOTIFIED  = 'homacrm_notified_version';
	const SLUG      = 'homacrm';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_dir' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'homacrm_daily', array( __CLASS__, 'check_and_notify' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/** آخرین انتشار: ['version','zip','notes','url','date'] یا null. */
	public static function latest( $force = false ) {
		$c = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $c ) ) {
			return $c ? $c : null;
		}
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'HomaCRM-WordPress/' . HOMACRM_VERSION ) ) );
		$out = array();
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			$zip = '';
			foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
				if ( 'homacrm.zip' === ( $a['name'] ?? '' ) ) {
					$zip = (string) $a['browser_download_url'];
				}
			}
			$ver = ltrim( (string) ( $j['tag_name'] ?? '' ), 'vV' );
			if ( $ver && $zip ) {
				$out = array( 'version' => $ver, 'zip' => $zip, 'notes' => (string) ( $j['body'] ?? '' ), 'url' => (string) ( $j['html_url'] ?? '' ), 'date' => substr( (string) ( $j['published_at'] ?? '' ), 0, 10 ) );
			}
		}
		set_site_transient( self::CACHE, $out, 12 * HOUR_IN_SECONDS );
		return $out ? $out : null;
	}

	private static function newer() {
		$l = self::latest();
		return ( $l && version_compare( $l['version'], HOMACRM_VERSION, '>' ) ) ? $l : null;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$l    = self::newer();
		$file = plugin_basename( HOMACRM_FILE );
		$item = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => self::SLUG,
			'plugin'      => $file,
			'new_version' => $l ? $l['version'] : HOMACRM_VERSION,
			'url'         => 'https://homacrm.com',
			'package'     => $l ? $l['zip'] : '',
			'icons'       => array( 'svg' => HOMACRM_URL . 'assets/icon.svg' ),
		);
		if ( $l ) {
			$transient->response[ $file ] = $item;
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$l = self::latest();
		return (object) array(
			'name'          => 'HomaCRM — هما',
			'slug'          => self::SLUG,
			'version'       => $l ? $l['version'] : HOMACRM_VERSION,
			'author'        => '<a href="https://homacrm.com">HomaCRM</a>',
			'homepage'      => 'https://homacrm.com',
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $l ? $l['date'] : HOMACRM_RELEASED,
			'download_link' => $l ? $l['zip'] : '',
			'sections'      => array(
				'description' => esc_html__( 'افزونهٔ رسمیِ هما برای وردپرس و ووکامرس.', 'homacrm' ),
				'changelog'   => $l ? nl2br( esc_html( $l['notes'] ) ) : '',
			),
		);
	}

	public static function auto( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( HOMACRM_FILE ) === $item->plugin && Settings::get( 'auto_update' ) ) {
			return true;
		}
		return $update;
	}

	/** zipِ GitHub همیشه پوشهٔ homacrm/ دارد؛ این فقط مطمئن می‌شود نامِ پوشه عوض نشود. */
	public static function fix_dir( $source, $remote_source, $upgrader, $extra = array() ) {
		if ( empty( $extra['plugin'] ) || plugin_basename( HOMACRM_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) !== $want && is_dir( $source ) ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
				return $want;
			}
		}
		return $source;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	public static function notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$l = self::newer();
		if ( ! $l ) {
			return;
		}
		$url   = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( plugin_basename( HOMACRM_FILE ) ) ), 'upgrade-plugin_' . plugin_basename( HOMACRM_FILE ) );
		$notes = wp_trim_words( wp_strip_all_tags( $l['notes'] ), 40 );
		echo '<div class="notice notice-info"><p><b>' . esc_html( sprintf( __( 'هما: نسخهٔ تازه %s آماده است.', 'homacrm' ), $l['version'] ) ) . '</b> ' . esc_html( $notes ) . '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'به‌روزرسانی', 'homacrm' ) . '</a> <a href="' . esc_url( $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'همهٔ تغییرات', 'homacrm' ) . '</a></p></div>';
	}

	/** روزانه: اگر نسخهٔ تازه‌ای آمده و هنوز خبر نداده‌ایم، ایمیل و پیامک به مدیرِ سایت. */
	public static function check_and_notify() {
		$l = self::latest( true );
		if ( ! $l || ! version_compare( $l['version'], HOMACRM_VERSION, '>' ) || get_option( self::NOTIFIED ) === $l['version'] ) {
			return;
		}
		update_option( self::NOTIFIED, $l['version'], false );
		$site    = get_bloginfo( 'name' );
		$subject = sprintf( __( '[%1$s] نسخهٔ تازهٔ افزونهٔ هما: %2$s', 'homacrm' ), $site, $l['version'] );
		$body    = sprintf( __( "سلام،\n\nنسخهٔ %1\$s افزونهٔ هما منتشر شد (نسخهٔ نصب‌شده: %2\$s).\n\nتغییرات:\n%3\$s\n\nبه‌روزرسانی از پیشخوانِ وردپرس: %4\$s\n\nHomaCRM — https://homacrm.com", 'homacrm' ), $l['version'], HOMACRM_VERSION, wp_strip_all_tags( $l['notes'] ), admin_url( 'plugins.php' ) );
		if ( Settings::get( 'notify_email' ) ) {
			wp_mail( get_option( 'admin_email' ), $subject, $body );
		}
		$mobile = Util::mobile( Settings::get( 'notify_mobile' ) );
		if ( '' !== $mobile && Settings::get( 'notify_sms' ) ) {
			Sms::send( $mobile, sprintf( __( 'هما: نسخهٔ تازهٔ افزونهٔ وردپرس (%1$s) برای %2$s آماده است؛ از پیشخوانِ وردپرس به‌روزرسانی کنید.', 'homacrm' ), $l['version'], $site ), 'update' );
		}
	}
}
