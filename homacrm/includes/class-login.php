<?php
/**
 * ورود و ثبت‌نام با موبایل و کدِ یک‌بارمصرف (جایگزینِ Digits).
 *
 * امنیت: کد فقط به‌صورتِ HMAC در transient می‌ماند، ۵ تلاشِ غلط کد را باطل می‌کند،
 * هر موبایل حداکثر هر ۶۰ ثانیه یک کد و ۵ کد در ساعت، و هر IP ۱۵ درخواست در ساعت.
 * کاربرانِ قبلیِ Digits و ووکامرس با همان موبایل (digits_phone_no / billing_phone) پیدا می‌شوند.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Login {

	const META = 'homacrm_mobile';

	public static function init() {
		add_shortcode( 'homacrm_login', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_nopriv_homacrm_otp_request', array( __CLASS__, 'ajax_request' ) );
		add_action( 'wp_ajax_nopriv_homacrm_otp_verify', array( __CLASS__, 'ajax_verify' ) );
		add_action( 'wp_ajax_homacrm_otp_request', array( __CLASS__, 'ajax_request' ) );
		add_action( 'wp_ajax_homacrm_otp_verify', array( __CLASS__, 'ajax_verify' ) );
		if ( Settings::get( 'otp_on_wp_login' ) ) {
			add_action( 'login_form', array( __CLASS__, 'echo_form' ) );
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}
		if ( Settings::get( 'otp_on_woo' ) ) {
			add_action( 'woocommerce_login_form_end', array( __CLASS__, 'echo_form' ) );
			add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'checkout_form' ), 5 );
			add_filter( 'body_class', array( __CLASS__, 'checkout_gate_class' ) );
		}
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
	}

	/* ---------- نمایش ---------- */

	public static function enqueue() {
		wp_enqueue_style( 'homacrm-login', HOMACRM_URL . 'assets/login.css', array(), HOMACRM_VERSION );
		wp_enqueue_script( 'homacrm-login', HOMACRM_URL . 'assets/login.js', array(), HOMACRM_VERSION, true );
		wp_localize_script(
			'homacrm-login',
			'HomaCRMLogin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'homacrm_otp' ),
				'i18n'  => array(
					'sending'  => __( 'در حال ارسال…', 'homacrm' ),
					'checking' => __( 'در حال بررسی…', 'homacrm' ),
					'resend'   => __( 'ارسالِ دوباره', 'homacrm' ),
					'send'     => __( 'دریافتِ کد', 'homacrm' ),
					'wait'     => __( 'ارسالِ دوباره تا %s ثانیهٔ دیگر', 'homacrm' ),
					'waitMsg'  => __( '%s ثانیهٔ دیگر دوباره امتحان کنید.', 'homacrm' ),
					'sentTo'   => __( 'کد به %s فرستاده شد.', 'homacrm' ),
					'edit'     => __( 'ویرایشِ شماره', 'homacrm' ),
					'badMobile'=> __( 'شمارهٔ موبایل را کامل وارد کنید (۱۱ رقم، با ۰۹).', 'homacrm' ),
					'error'    => __( 'مشکلی پیش آمد؛ دوباره امتحان کنید.', 'homacrm' ),
				),
			)
		);
	}

	public static function shortcode( $atts = array() ) {
		if ( is_user_logged_in() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'redirect' => '' ), $atts, 'homacrm_login' );
		self::enqueue();
		return self::form_html( $atts['redirect'] );
	}

	public static function echo_form() {
		self::enqueue();
		echo self::form_html( isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '' ); // phpcs:ignore WordPress.Security
	}

	/** صفحهٔ پرداخت: مهمان اول با پیامک وارد می‌شود، بعد فرمِ صورت‌حساب دیده می‌شود. */
	public static function checkout_form() {
		if ( ! is_user_logged_in() ) {
			self::enqueue();
			echo self::form_html( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function checkout_gate_class( $c ) {
		if ( ! is_user_logged_in() && function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url() && Settings::get( 'otp_checkout_gate' ) ) {
			$c[] = 'homacrm-gate';
		}
		return $c;
	}

	private static function form_html( $redirect ) {
		$len = (int) Settings::get( 'otp_length' );
		ob_start();
		?>
		<div class="homacrm-otp" dir="rtl" data-redirect="<?php echo esc_attr( $redirect ); ?>">
			<p class="homacrm-otp-title"><?php esc_html_e( 'ورود یا ثبت‌نام با شمارهٔ موبایل', 'homacrm' ); ?></p>
			<div class="homacrm-step homacrm-step-mobile">
				<input type="tel" inputmode="numeric" autocomplete="tel" class="homacrm-mobile" placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" maxlength="16" aria-label="<?php esc_attr_e( 'شمارهٔ موبایل', 'homacrm' ); ?>">
				<button type="button" class="button homacrm-send"><?php esc_html_e( 'دریافتِ کد', 'homacrm' ); ?></button>
			</div>
			<div class="homacrm-step homacrm-step-code" hidden>
				<input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="<?php echo esc_attr( $len ); ?>" class="homacrm-code" placeholder="<?php echo esc_attr( str_repeat( '•', $len ) ); ?>" dir="ltr" aria-label="<?php esc_attr_e( 'کدِ پیامک‌شده', 'homacrm' ); ?>">
				<button type="button" class="button button-primary homacrm-verify"><?php esc_html_e( 'تأیید و ورود', 'homacrm' ); ?></button>
				<div class="homacrm-row"><button type="button" class="button-link homacrm-edit"><?php esc_html_e( 'ویرایشِ شماره', 'homacrm' ); ?></button><button type="button" class="button-link homacrm-resend" disabled></button></div>
			</div>
			<p class="homacrm-msg" role="status" aria-live="polite"></p>
			<p class="homacrm-brand"><?php esc_html_e( 'ورود امن با', 'homacrm' ); ?> <a href="https://homacrm.com/?utm_source=wp-plugin&amp;utm_medium=login" target="_blank" rel="noopener" dir="ltr">HomaCRM</a></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- درخواستِ کد ---------- */

	public static function ajax_request() {
		self::guard();
		$mobile = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) );
		if ( '' === $mobile ) {
			wp_send_json_error( array( 'message' => __( 'شمارهٔ موبایل درست نیست.', 'homacrm' ) ), 400 );
		}
		if ( ! Settings::get( 'otp_register' ) && ! self::find_user( $mobile ) ) {
			wp_send_json_error( array( 'message' => __( 'کاربری با این شماره پیدا نشد.', 'homacrm' ) ), 404 );
		}
		$ip   = self::ip();
		$wait = max( self::limited( 'ip_' . md5( $ip ), 15 ), self::limited( 'm_' . $mobile, 5 ) );
		if ( $wait > 0 ) {
			wp_send_json_error( array( 'message' => __( 'تعدادِ درخواست‌ها زیاد بود.', 'homacrm' ), 'wait' => $wait ), 429 );
		}
		$last = (int) get_transient( 'homacrm_otp_last_' . $mobile );
		if ( $last && time() - $last < 60 ) {
			wp_send_json_error( array( 'message' => __( 'کد همین الان فرستاده شد.', 'homacrm' ), 'wait' => 60 - ( time() - $last ), 'sent' => 1 ), 429 );
		}
		$len  = max( 4, min( 8, (int) Settings::get( 'otp_length' ) ) );
		$code = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}
		$ttl  = max( 60, min( 600, (int) Settings::get( 'otp_ttl' ) ) );
		$text = Util::render( Settings::get( 'otp_template' ), array( 'code' => $code, 'site' => get_bloginfo( 'name' ) ) );
		$res  = Sms::send( $mobile, $text, 'otp' );
		if ( ! $res['ok'] ) {
			// ارسالِ ناموفق شمرده نمی‌شود و مهلتِ انتظار نمی‌سازد.
			wp_send_json_error( array( 'message' => __( 'ارسالِ پیامک ممکن نشد؛ چند لحظهٔ دیگر دوباره بزنید.', 'homacrm' ) ), 502 );
		}
		set_transient( 'homacrm_otp_' . $mobile, array( 'h' => self::hash( $mobile, $code ), 'tries' => 0 ), $ttl );
		set_transient( 'homacrm_otp_last_' . $mobile, time(), 60 );
		self::hit( 'ip_' . md5( $ip ) );
		self::hit( 'm_' . $mobile );
		wp_send_json_success( array( 'message' => __( 'کد فرستاده شد.', 'homacrm' ), 'wait' => 60 ) );
	}

	/* ---------- تأییدِ کد و ورود ---------- */

	public static function ajax_verify() {
		self::guard();
		$mobile = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) );
		$code   = preg_replace( '/\D+/', '', strtr( (string) wp_unslash( $_POST['code'] ?? '' ), array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) ) );
		$key    = 'homacrm_otp_' . $mobile;
		$rec    = get_transient( $key );
		if ( '' === $mobile || ! is_array( $rec ) ) {
			wp_send_json_error( array( 'message' => __( 'کد منقضی شده است؛ دوباره درخواست کنید.', 'homacrm' ) ), 400 );
		}
		if ( ! hash_equals( (string) $rec['h'], self::hash( $mobile, $code ) ) ) {
			$rec['tries'] = (int) $rec['tries'] + 1;
			if ( $rec['tries'] >= 5 ) {
				delete_transient( $key );
				wp_send_json_error( array( 'message' => __( 'چند بار اشتباه وارد شد؛ کدِ تازه بگیرید.', 'homacrm' ) ), 400 );
			}
			set_transient( $key, $rec, max( 60, (int) Settings::get( 'otp_ttl' ) ) );
			wp_send_json_error( array( 'message' => __( 'کد درست نیست.', 'homacrm' ) ), 400 );
		}
		delete_transient( $key );

		$user = self::find_user( $mobile );
		if ( ! $user ) {
			if ( ! Settings::get( 'otp_register' ) ) {
				wp_send_json_error( array( 'message' => __( 'کاربری با این شماره پیدا نشد.', 'homacrm' ) ), 404 );
			}
			$user = self::register( $mobile );
			if ( is_wp_error( $user ) ) {
				wp_send_json_error( array( 'message' => $user->get_error_message() ), 500 );
			}
		}
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) );
		$redirect = wp_validate_redirect( $redirect, function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : admin_url( 'profile.php' ) );
		wp_send_json_success( array( 'redirect' => apply_filters( 'homacrm_login_redirect', $redirect, $user ) ) );
	}

	/* ---------- کاربر ---------- */

	/** کاربر را با موبایل پیدا می‌کند؛ کلیدهای Digits و ووکامرس هم برای کوچ از آن‌ها. */
	public static function find_user( $mobile ) {
		$ten  = substr( $mobile, 1 );
		$keys = array(
			array( self::META, $mobile ),
			array( 'billing_phone', $mobile ),
			array( 'digits_phone_no', $ten ),
			array( 'digits_phone', '+98' . $ten ),
		);
		foreach ( $keys as $k ) {
			$u = get_users( array( 'meta_key' => $k[0], 'meta_value' => $k[1], 'number' => 1, 'fields' => 'all' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $u ) {
				if ( ! get_user_meta( $u[0]->ID, self::META, true ) ) {
					update_user_meta( $u[0]->ID, self::META, $mobile );
				}
				return $u[0];
			}
		}
		$u = get_user_by( 'login', $mobile );
		return $u ? $u : null;
	}

	private static function register( $mobile ) {
		$login = $mobile;
		if ( username_exists( $login ) ) {
			$login = $mobile . '_' . wp_generate_password( 4, false );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $mobile,
				'role'         => class_exists( 'WooCommerce' ) ? 'customer' : get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( $id, self::META, $mobile );
		update_user_meta( $id, 'billing_phone', $mobile );
		do_action( 'homacrm_user_registered', $id, $mobile );
		return get_user_by( 'id', $id );
	}

	public static function profile_field( $user ) {
		echo '<h2>' . esc_html__( 'هما', 'homacrm' ) . '</h2><table class="form-table"><tr><th><label for="homacrm_mobile">' . esc_html__( 'موبایل (ورود با پیامک)', 'homacrm' ) . '</label></th><td><input type="tel" id="homacrm_mobile" name="homacrm_mobile" dir="ltr" value="' . esc_attr( get_user_meta( $user->ID, self::META, true ) ) . '" class="regular-text"></td></tr></table>';
	}

	public static function profile_save( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['homacrm_mobile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- هستهٔ وردپرس nonceِ پروفایل را می‌سنجد.
			return;
		}
		$m = Util::mobile( wp_unslash( $_POST['homacrm_mobile'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$o = self::find_user( $m );
		if ( '' === $m || ( $o && (int) $o->ID !== (int) $user_id ) ) {
			return;
		}
		update_user_meta( $user_id, self::META, $m );
	}

	/* ---------- کمکی ---------- */

	/**
	 * nonce فقط برای کاربرِ واردشده سنجیده می‌شود. برای مهمان نه، چون صفحه‌های کش‌شده nonceِ کهنه دارند
	 * و فرمِ ورود را بی‌صدا از کار می‌انداخت؛ محافظِ مهمان همان محدودیتِ تکرار (IP و موبایل) است.
	 */
	private static function guard() {
		if ( is_user_logged_in() && ! check_ajax_referer( 'homacrm_otp', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'صفحه کهنه شده است؛ یک بار تازه کنید.', 'homacrm' ) ), 403 );
		}
	}

	private static function hash( $mobile, $code ) {
		return hash_hmac( 'sha256', $mobile . '|' . $code, wp_salt( 'auth' ) );
	}

	/** اگر سقفِ یک‌ساعته پر شده، چند ثانیه تا آزادشدن مانده؛ وگرنه ۰. */
	private static function limited( $key, $max ) {
		$r = get_transient( 'homacrm_rl_' . $key );
		if ( ! is_array( $r ) || (int) $r['n'] < $max ) {
			return 0;
		}
		return max( 1, (int) $r['until'] - time() );
	}

	private static function hit( $key ) {
		$k = 'homacrm_rl_' . $key;
		$r = get_transient( $k );
		if ( ! is_array( $r ) ) {
			$r = array( 'n' => 0, 'until' => time() + HOUR_IN_SECONDS );
		}
		++$r['n'];
		set_transient( $k, $r, max( 1, (int) $r['until'] - time() ) );
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
