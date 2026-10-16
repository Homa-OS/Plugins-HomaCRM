<?php
/**
 * تنظیمات و صفحهٔ مدیریتِ «هما» در پیشخوانِ وردپرس.
 * توکن جدا ذخیره می‌شود (autoload خاموش) و هرگز دوباره در فرم نمایش داده نمی‌شود.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'homacrm_settings';
	const TOKEN  = 'homacrm_token';

	/** مقدارهای پیش‌فرض؛ هر بخش تا وقتی روشن نشود کاری نمی‌کند. */
	public static function defaults() {
		return array(
			'api_base'            => HOMACRM_DEFAULT_API,
			'sender'              => '',
			// ورود و ثبت‌نام با پیامک
			'otp_enabled'         => 0,
			'otp_length'          => 5,
			'otp_ttl'             => 120,
			'otp_template'        => "کد ورود شما به {site}: {code}\nلغو11",
			'otp_register'        => 1,
			'otp_on_wp_login'     => 1,
			'otp_on_woo'          => 1,
			// ووکامرس
			'woo_enabled'         => 0,
			'woo_statuses'        => array( 'processing', 'completed' ),
			'woo_tpl_processing'  => "{name} عزیز، سفارش {order} در {site} ثبت شد و در حال آماده‌سازی است.\nمبلغ: {total}\nلغو11",
			'woo_tpl_completed'   => "{name} عزیز، سفارش {order} ارسال شد. از خریدتان سپاسگزاریم.\nلغو11",
			'woo_tpl_on-hold'     => "{name} عزیز، سفارش {order} در انتظار پرداخت است.\nلغو11",
			'woo_tpl_cancelled'   => "{name} عزیز، سفارش {order} لغو شد.\nلغو11",
			'woo_tpl_refunded'    => "{name} عزیز، مبلغ سفارش {order} بازگردانده شد.\nلغو11",
			'woo_tpl_failed'      => "{name} عزیز، پرداختِ سفارش {order} ناموفق بود.\nلغو11",
			'woo_admin_mobiles'   => '',
			'woo_admin_template'  => "سفارش تازه {order} از {name} ({mobile}) — {total}",
			'woo_sync_contact'    => 1,
			'woo_deal_pipeline'   => 0,
			'woo_deal_stage'      => 0,
			// CRM
			'crm_sync_users'      => 0,
			'crm_group_id'        => 0,
			// فرم‌ها
			'forms_enabled'       => 0,
			'forms_target'        => 'lead',
			// به‌روزرسانی و اطلاع‌رسانی
			'auto_update'         => 0,
			'notify_email'        => 1,
			'notify_sms'          => 0,
			'notify_mobile'       => '',
			// لاگ
			'log_days'            => 30,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function token() {
		return (string) get_option( self::TOKEN, '' );
	}

	public static function api_base() {
		$b = untrailingslashit( (string) self::get( 'api_base' ) );
		return ( '' === $b || 0 !== strpos( $b, 'https://' ) ) ? HOMACRM_DEFAULT_API : $b;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_homacrm_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_homacrm_test_sms', array( __CLASS__, 'test_sms' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, 'homacrm' ) ) {
			wp_enqueue_style( 'homacrm-admin', HOMACRM_URL . 'assets/admin.css', array(), HOMACRM_VERSION );
		}
	}

	public static function menu() {
		add_menu_page( __( 'هما', 'homacrm' ), __( 'هما', 'homacrm' ), 'manage_options', 'homacrm', array( __CLASS__, 'page' ), HOMACRM_URL . 'assets/menu-icon.svg', 58 );
	}

	private static function tabs() {
		$tabs = array(
			'connection' => __( 'اتصال', 'homacrm' ),
			'login'      => __( 'ورود با پیامک', 'homacrm' ),
			'woo'        => __( 'ووکامرس', 'homacrm' ),
			'crm'        => __( 'CRM و فرم‌ها', 'homacrm' ),
			'tools'      => __( 'ابزارها و گزارش', 'homacrm' ),
			'about'      => __( 'درباره', 'homacrm' ),
		);
		return $tabs;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification
		$s    = self::all();
		echo '<div class="wrap homacrm" dir="rtl"><h1><img src="' . esc_url( HOMACRM_URL . 'assets/icon.svg' ) . '" alt="" width="28" height="28"> ' . esc_html__( 'هما — HomaCRM', 'homacrm' ) . ' <a class="homacrm-site" href="https://homacrm.com/?utm_source=wp-plugin&amp;utm_medium=admin" target="_blank" rel="noopener">HomaCRM.com</a></h1>';
		if ( isset( $_GET['homacrm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['homacrm_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=homacrm&tab=' . $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		if ( 'tools' === $tab ) {
			self::tools_tab();
		} else {
			if ( 'about' === $tab ) {
				self::about_tab();
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'homacrm_save' );
			echo '<input type="hidden" name="action" value="homacrm_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><table class="form-table" role="presentation">';
			call_user_func( array( __CLASS__, 'tab_' . $tab ), $s );
			echo '</table>';
			submit_button( __( 'ذخیره', 'homacrm' ) );
			echo '</form>';
		}
		echo '</div>';
	}

	/* ---------- زبانه‌ها ---------- */

	private static function tab_connection( $s ) {
		$has = '' !== self::token();
		self::row( __( 'توکنِ API', 'homacrm' ), '<input type="password" name="token" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $has ? __( '•••••• (ذخیره شده — برای تغییر، توکنِ تازه را بنویسید)', 'homacrm' ) : __( 'توکن را از پنلِ هما بچسبانید', 'homacrm' ) ) . '">'
			. '<p class="description">' . wp_kses_post( __( 'در پنلِ هما: <b>تنظیمات › توکنِ API</b> (فقط مدیرِ اصلیِ پرتال). یک توکن برای همهٔ امکاناتِ این افزونه کافی است.', 'homacrm' ) ) . '</p>' );
		if ( $has ) {
			$v = Api::verify();
			if ( $v['ok'] ) {
				$c = Api::credit();
				$line = sprintf( __( '✓ وصل است — پرتالِ %s', 'homacrm' ), esc_html( (string) ( $v['data']['portalCode'] ?? '' ) ) );
				if ( $c['ok'] && isset( $c['data']['balance'] ) ) {
					$line .= ' · ' . sprintf( __( 'اعتبارِ پیامک: %s ریال', 'homacrm' ), esc_html( number_format_i18n( (float) $c['data']['balance'] ) ) );
				}
				self::row( __( 'وضعیت', 'homacrm' ), '<span class="homacrm-ok">' . $line . '</span>' );
			} else {
				self::row( __( 'وضعیت', 'homacrm' ), '<span class="homacrm-bad">✗ ' . esc_html( $v['error'] ) . '</span>' );
			}
		}
		self::row( __( 'شمارهٔ فرستنده', 'homacrm' ), '<input type="text" name="sender" value="' . esc_attr( $s['sender'] ) . '" class="regular-text" dir="ltr"><p class="description">' . esc_html__( 'خالی بگذارید تا خطِ پیش‌فرضِ پرتال استفاده شود.', 'homacrm' ) . '</p>' );
		self::row( __( 'نشانیِ API', 'homacrm' ), '<input type="url" name="api_base" value="' . esc_attr( $s['api_base'] ) . '" class="regular-text" dir="ltr"><p class="description">' . esc_html__( 'فقط برای نسخهٔ اختصاصیِ هما؛ پیش‌فرض: ', 'homacrm' ) . '<code>' . esc_html( HOMACRM_DEFAULT_API ) . '</code></p>' );
	}

	private static function tab_login( $s ) {
		self::checkbox( 'otp_enabled', $s, __( 'ورود و ثبت‌نام با پیامک روشن باشد', 'homacrm' ) );
		self::checkbox( 'otp_on_wp_login', $s, __( 'در صفحهٔ ورودِ وردپرس (wp-login.php) هم نشان داده شود', 'homacrm' ) );
		self::checkbox( 'otp_on_woo', $s, __( 'در «حسابِ کاربری» و تسویه‌حسابِ ووکامرس هم نشان داده شود', 'homacrm' ) );
		self::checkbox( 'otp_register', $s, __( 'اگر کاربری با این موبایل نبود، خودکار ثبت‌نام شود', 'homacrm' ) );
		self::row( __( 'طولِ کد', 'homacrm' ), '<input type="number" min="4" max="8" name="otp_length" value="' . esc_attr( $s['otp_length'] ) . '">' );
		self::row( __( 'اعتبارِ کد (ثانیه)', 'homacrm' ), '<input type="number" min="60" max="600" name="otp_ttl" value="' . esc_attr( $s['otp_ttl'] ) . '">' );
		self::row( __( 'متنِ پیامکِ کد', 'homacrm' ), '<textarea name="otp_template" rows="3" class="large-text">' . esc_textarea( $s['otp_template'] ) . '</textarea><p class="description">{code} {site}</p>' );
		self::row( __( 'کدِ کوتاه', 'homacrm' ), '<code>[homacrm_login]</code> — ' . esc_html__( 'فرمِ ورود/ثبت‌نام با پیامک را هر جای سایت می‌گذارد.', 'homacrm' ) );
	}

	private static function tab_woo( $s ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::row( '', '<p>' . esc_html__( 'ووکامرس نصب نیست؛ این بخش بعد از نصبِ آن کار می‌کند.', 'homacrm' ) . '</p>' );
		}
		self::checkbox( 'woo_enabled', $s, __( 'اطلاع‌رسانیِ سفارش با پیامک روشن باشد', 'homacrm' ) );
		$labels = array( 'processing' => __( 'در حال انجام', 'homacrm' ), 'completed' => __( 'تکمیل‌شده', 'homacrm' ), 'on-hold' => __( 'در انتظار', 'homacrm' ), 'cancelled' => __( 'لغوشده', 'homacrm' ), 'refunded' => __( 'مسترد', 'homacrm' ), 'failed' => __( 'ناموفق', 'homacrm' ) );
		foreach ( $labels as $st => $label ) {
			$on = in_array( $st, (array) $s['woo_statuses'], true );
			self::row( sprintf( __( 'وضعیتِ «%s»', 'homacrm' ), $label ), '<label><input type="checkbox" name="woo_statuses[]" value="' . esc_attr( $st ) . '"' . checked( $on, true, false ) . '> ' . esc_html__( 'پیامک به مشتری', 'homacrm' ) . '</label><textarea name="woo_tpl_' . esc_attr( $st ) . '" rows="3" class="large-text">' . esc_textarea( $s[ 'woo_tpl_' . $st ] ) . '</textarea>' );
		}
		self::row( __( 'متغیرها', 'homacrm' ), '<code>{name} {order} {total} {status} {site} {mobile} {tracking}</code>' );
		self::row( __( 'موبایلِ مدیران', 'homacrm' ), '<input type="text" name="woo_admin_mobiles" value="' . esc_attr( $s['woo_admin_mobiles'] ) . '" class="regular-text" dir="ltr"><p class="description">' . esc_html__( 'با ویرگول جدا کنید؛ برای هر سفارشِ تازه پیامک می‌گیرند.', 'homacrm' ) . '</p>' );
		self::row( __( 'متنِ پیامکِ مدیر', 'homacrm' ), '<textarea name="woo_admin_template" rows="2" class="large-text">' . esc_textarea( $s['woo_admin_template'] ) . '</textarea>' );
		self::checkbox( 'woo_sync_contact', $s, __( 'خریدار در CRMِ هما «مخاطب» شود', 'homacrm' ) );
		self::row( __( 'ثبتِ سفارش به‌عنوانِ معامله', 'homacrm' ), '<input type="number" name="woo_deal_pipeline" value="' . esc_attr( $s['woo_deal_pipeline'] ) . '" placeholder="pipelineId"> <input type="number" name="woo_deal_stage" value="' . esc_attr( $s['woo_deal_stage'] ) . '" placeholder="stageId"><p class="description">' . esc_html__( 'خالی/۰ = خاموش. شناسه‌ها را از «ابزارها» ببینید.', 'homacrm' ) . '</p>' );
	}

	private static function tab_crm( $s ) {
		self::checkbox( 'crm_sync_users', $s, __( 'هر کاربرِ تازهٔ وردپرس (با موبایل) در CRMِ هما «مخاطب» شود', 'homacrm' ) );
		self::row( __( 'گروهِ مخاطبان', 'homacrm' ), '<input type="number" name="crm_group_id" value="' . esc_attr( $s['crm_group_id'] ) . '"><p class="description">' . esc_html__( 'شناسهٔ گروه در هما؛ ۰ = بی‌گروه.', 'homacrm' ) . '</p>' );
		self::checkbox( 'forms_enabled', $s, __( 'فرمِ تماس/درخواست روشن باشد', 'homacrm' ) );
		$opts = array( 'lead' => __( 'سرنخ در CRM', 'homacrm' ), 'ticket' => __( 'تیکتِ پشتیبانی', 'homacrm' ), 'both' => __( 'هر دو', 'homacrm' ) );
		$sel  = '';
		foreach ( $opts as $k => $l ) {
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['forms_target'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		self::row( __( 'فرم به کجا برود', 'homacrm' ), '<select name="forms_target">' . $sel . '</select>' );
		self::row( __( 'کدِ کوتاه', 'homacrm' ), '<code>[homacrm_form]</code> · ' . esc_html__( 'فرم‌های Contact Form 7 هم اگر فیلدِ mobile داشته باشند خودکار به هما می‌روند.', 'homacrm' ) );
	}

	private static function tools_tab() {
		echo '<h2>' . esc_html__( 'پیامکِ آزمایشی', 'homacrm' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'homacrm_test_sms' );
		echo '<input type="hidden" name="action" value="homacrm_test_sms"><input type="text" name="mobile" placeholder="09xxxxxxxxx" dir="ltr"> ';
		submit_button( __( 'ارسال', 'homacrm' ), 'secondary', 'submit', false );
		echo '</form>';
		$p = '' !== self::token() ? Api::pipelines() : array( 'ok' => false );
		if ( $p['ok'] && is_array( $p['data'] ) ) {
			echo '<h2>' . esc_html__( 'قیف‌ها و مرحله‌های فروش', 'homacrm' ) . '</h2><ul>';
			foreach ( $p['data'] as $pl ) {
				$stages = array();
				foreach ( (array) ( $pl['stages'] ?? array() ) as $st ) {
					$stages[] = esc_html( ( $st['name'] ?? '' ) . ' #' . ( $st['id'] ?? '' ) );
				}
				echo '<li><b>' . esc_html( ( $pl['name'] ?? '' ) . ' #' . ( $pl['id'] ?? '' ) ) . '</b> — ' . implode( '، ', $stages ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
		Log::render();
	}

	private static function tab_about( $s ) {
		$l = Updater::latest();
		self::row( __( 'آخرین نسخه', 'homacrm' ), $l ? esc_html( $l['version'] . ' — ' . $l['date'] ) . ( version_compare( $l['version'], HOMACRM_VERSION, '>' ) ? ' <b>' . esc_html__( '(تازه‌تر از نسخهٔ شما)', 'homacrm' ) . '</b>' : ' ✓' ) : esc_html__( 'خوانده نشد', 'homacrm' ) );
		self::checkbox( 'auto_update', $s, __( 'نسخه‌های تازه خودکار نصب شوند', 'homacrm' ) );
		self::checkbox( 'notify_email', $s, __( 'نسخهٔ تازه به ایمیلِ مدیرِ سایت خبر داده شود', 'homacrm' ) );
		self::checkbox( 'notify_sms', $s, __( 'نسخهٔ تازه با پیامک هم خبر داده شود (از اعتبارِ همین پرتال)', 'homacrm' ) );
		self::row( __( 'موبایلِ مدیر', 'homacrm' ), '<input type="text" name="notify_mobile" value="' . esc_attr( $s['notify_mobile'] ) . '" dir="ltr" placeholder="09xxxxxxxxx">' );
	}

	private static function about_tab() {
		echo '<div class="homacrm-about"><p><b>HomaCRM — هما</b> · ' . esc_html__( 'نسخه', 'homacrm' ) . ' ' . esc_html( HOMACRM_VERSION ) . ' · ' . esc_html__( 'به‌روزرسانی', 'homacrm' ) . ' ' . esc_html( HOMACRM_RELEASED ) . '</p>';
		echo '<p>' . esc_html__( 'افزونهٔ رسمیِ هما برای وردپرس و ووکامرس. ساخته و نگهداری‌شده توسطِ هما (فناوران نگین شهر نوین).', 'homacrm' ) . '</p>';
		echo '<p><a href="https://homacrm.com" target="_blank" rel="noopener">homacrm.com</a> · <a href="https://github.com/Homa-OS/homacrm-wordpress" target="_blank" rel="noopener">GitHub</a></p></div>';
	}

	/* ---------- ذخیره ---------- */

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'homacrm_save' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'homacrm' ) );
		}
		$in  = wp_unslash( $_POST );
		$s   = self::all();
		$tab = sanitize_key( $in['tab'] ?? '' );
		$checkboxes = array(
			'login' => array( 'otp_enabled', 'otp_on_wp_login', 'otp_on_woo', 'otp_register' ),
			'woo'   => array( 'woo_enabled', 'woo_sync_contact' ),
			'crm'   => array( 'crm_sync_users', 'forms_enabled' ),
			'about' => array( 'auto_update', 'notify_email', 'notify_sms' ),
		);
		foreach ( $checkboxes[ $tab ] ?? array() as $k ) {
			$s[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		foreach ( self::defaults() as $k => $def ) {
			if ( ! array_key_exists( $k, $in ) || in_array( $k, $checkboxes[ $tab ] ?? array(), true ) ) {
				continue;
			}
			if ( is_array( $def ) ) {
				$s[ $k ] = array_map( 'sanitize_key', (array) $in[ $k ] );
			} elseif ( is_int( $def ) ) {
				$s[ $k ] = (int) $in[ $k ];
			} elseif ( false !== strpos( $k, 'tpl' ) || false !== strpos( $k, 'template' ) ) {
				$s[ $k ] = sanitize_textarea_field( $in[ $k ] );
			} elseif ( 'api_base' === $k ) {
				$s[ $k ] = esc_url_raw( $in[ $k ], array( 'https' ) );
			} else {
				$s[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		if ( 'woo' === $tab && ! isset( $in['woo_statuses'] ) ) {
			$s['woo_statuses'] = array();
		}
		update_option( self::OPTION, $s, false );
		$msg = __( 'ذخیره شد.', 'homacrm' );
		if ( 'connection' === $tab && ! empty( $in['token'] ) ) {
			$token = trim( sanitize_text_field( $in['token'] ) );
			$v     = Api::verify( $token );
			if ( $v['ok'] ) {
				update_option( self::TOKEN, $token, false );
				$msg = __( 'توکن ذخیره و تأیید شد.', 'homacrm' );
			} else {
				$msg = __( 'توکن ذخیره نشد: ', 'homacrm' ) . $v['error'];
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'homacrm', 'tab' => $tab, 'homacrm_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function test_sms() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'homacrm_test_sms' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'homacrm' ) );
		}
		$m   = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) );
		$res = '' === $m ? array( 'ok' => false, 'error' => __( 'شمارهٔ موبایل نامعتبر است.', 'homacrm' ) ) : Sms::send( $m, sprintf( __( 'پیامِ آزمایشیِ افزونهٔ هما از %s', 'homacrm' ), get_bloginfo( 'name' ) ), 'test' );
		$msg = $res['ok'] ? __( 'پیامکِ آزمایشی فرستاده شد.', 'homacrm' ) : __( 'ارسال نشد: ', 'homacrm' ) . $res['error'];
		wp_safe_redirect( add_query_arg( array( 'page' => 'homacrm', 'tab' => 'tools', 'homacrm_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- کمکی‌های فرم ---------- */

	private static function row( $label, $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- html از همین کلاس و escape‌شده.
	}

	private static function checkbox( $key, $s, $label ) {
		self::row( '', '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>' );
	}
}
