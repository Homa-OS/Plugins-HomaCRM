<?php
/**
 * لاگِ کوتاهِ پیامک‌ها (جدولِ {prefix}homacrm_log)، با پاک‌سازیِ خودکار.
 * موبایل پوشانده نمایش داده می‌شود و کدِ ورود هرگز ذخیره نمی‌شود.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'homacrm_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			context varchar(20) NOT NULL,
			mobile varchar(20) NOT NULL,
			ok tinyint(1) NOT NULL,
			error varchar(255) NOT NULL DEFAULT '',
			message text NULL,
			PRIMARY KEY  (id),
			KEY created (created)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'homacrm_db_version', HOMACRM_VERSION, false );
	}

	public static function init() {
		if ( get_option( 'homacrm_db_version' ) !== HOMACRM_VERSION ) {
			self::install();
		}
		add_action( 'homacrm_daily', array( __CLASS__, 'purge' ) );
		if ( ! wp_next_scheduled( 'homacrm_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'homacrm_daily' );
		}
	}

	public static function add( $context, $mobile, $ok, $error = '', $message = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created' => current_time( 'mysql', true ),
				'context' => substr( (string) $context, 0, 20 ),
				'mobile'  => substr( (string) $mobile, 0, 20 ),
				'ok'      => $ok ? 1 : 0,
				'error'   => substr( (string) $error, 0, 255 ),
				'message' => (string) $message,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function purge() {
		global $wpdb;
		$days = max( 1, (int) Settings::get( 'log_days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < UTC_TIMESTAMP() - INTERVAL %d DAY', $days ) ); // phpcs:ignore WordPress.DB
	}

	public static function render() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 50' ); // phpcs:ignore WordPress.DB
		echo '<h2>' . esc_html__( 'آخرین پیامک‌ها', 'homacrm' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'زمان', 'homacrm' ) . '</th><th>' . esc_html__( 'بخش', 'homacrm' ) . '</th><th>' . esc_html__( 'موبایل', 'homacrm' ) . '</th><th>' . esc_html__( 'نتیجه', 'homacrm' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'هنوز پیامکی فرستاده نشده است.', 'homacrm' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			echo '<tr><td dir="ltr">' . esc_html( get_date_from_gmt( $r->created, 'Y-m-d H:i' ) ) . '</td><td>' . esc_html( $r->context ) . '</td><td dir="ltr">' . esc_html( Util::mask( $r->mobile ) ) . '</td><td>' . ( $r->ok ? '✓' : '✗ ' . esc_html( $r->error ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
