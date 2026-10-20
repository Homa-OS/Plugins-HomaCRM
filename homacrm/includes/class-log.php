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
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/** برچسبِ فارسیِ هر بخش در لاگ. */
	public static function label( $context ) {
		$l = array(
			'otp'      => __( 'کدِ ورود', 'homacrm' ),
			'woo'      => __( 'وضعیتِ سفارش', 'homacrm' ),
			'admin'    => __( 'سفارشِ تازه به مدیر', 'homacrm' ),
			'cart'     => __( 'سبدِ رهاشده', 'homacrm' ),
			'stock'    => __( 'موجود شد', 'homacrm' ),
			'birthday' => __( 'تولد', 'homacrm' ),
			'welcome'  => __( 'تشکرِ اولین خرید', 'homacrm' ),
			'tracking' => __( 'کدِ رهگیری', 'homacrm' ),
			'review'   => __( 'درخواستِ نظر', 'homacrm' ),
			'track'    => __( 'پیگیریِ سفارش', 'homacrm' ),
			'shop'     => __( 'انبار و فاکتور', 'homacrm' ),
			'test'     => __( 'آزمایشی', 'homacrm' ),
			'update'   => __( 'نسخهٔ تازه', 'homacrm' ),
		);
		return $l[ $context ] ?? $context;
	}

	/** خلاصهٔ ۷ روزِ اخیر: هر بخش چند موفق و چند ناموفق، با اعتبارِ فعلیِ پیامک. */
	public static function summary( $days = 7 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT context, SUM(ok) AS ok, SUM(1-ok) AS bad FROM ' . self::table() . ' WHERE created >= %s GROUP BY context ORDER BY COUNT(*) DESC', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$c    = '' !== Settings::token() ? Api::credit() : array( 'ok' => false );
		echo '<h2>' . esc_html( sprintf( __( 'خلاصهٔ %s روزِ اخیر', 'homacrm' ), number_format_i18n( $days ) ) ) . '</h2>';
		if ( $c['ok'] && isset( $c['data']['balance'] ) ) {
			echo '<p>' . esc_html( sprintf( __( 'اعتبارِ پیامک: %s ریال', 'homacrm' ), number_format_i18n( (float) $c['data']['balance'] ) ) ) . '</p>';
		}
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'بخش', 'homacrm' ) . '</th><th>' . esc_html__( 'موفق', 'homacrm' ) . '</th><th>' . esc_html__( 'ناموفق', 'homacrm' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="3">' . esc_html__( 'در این بازه پیامکی فرستاده نشده است.', 'homacrm' ) . '</td></tr>';
		}
		$tok = 0;
		$tbad = 0;
		foreach ( (array) $rows as $r ) {
			$tok  += (int) $r->ok;
			$tbad += (int) $r->bad;
			echo '<tr><td>' . esc_html( self::label( $r->context ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->ok ) ) . '</td><td>' . ( (int) $r->bad ? '<b class="homacrm-bad">' . esc_html( number_format_i18n( (int) $r->bad ) ) . '</b>' : '0' ) . '</td></tr>';
		}
		if ( $rows ) {
			echo '<tr><th>' . esc_html__( 'جمع', 'homacrm' ) . '</th><th>' . esc_html( number_format_i18n( $tok ) ) . '</th><th>' . esc_html( number_format_i18n( $tbad ) ) . '</th></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'ارسال از این سایت؛ گزارشِ تحویلِ واقعی به گوشی در پنلِ هما است.', 'homacrm' ) . '</p>';
	}

	public static function render() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 50' ); // phpcs:ignore WordPress.DB
		echo '<h2>' . esc_html__( 'آخرین پیامک‌ها', 'homacrm' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'زمان', 'homacrm' ) . '</th><th>' . esc_html__( 'بخش', 'homacrm' ) . '</th><th>' . esc_html__( 'موبایل', 'homacrm' ) . '</th><th>' . esc_html__( 'نتیجه', 'homacrm' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'هنوز پیامکی فرستاده نشده است.', 'homacrm' ) . '</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			echo '<tr><td dir="ltr">' . esc_html( Util::jdate( $r->created ) ) . '</td><td>' . esc_html( self::label( $r->context ) ) . '</td><td dir="ltr">' . esc_html( Util::mask( $r->mobile ) ) . '</td><td>' . ( $r->ok ? '✓' : '✗ ' . esc_html( $r->error ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
