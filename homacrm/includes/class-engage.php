<?php
/**
 * بازاریابی و نگهداشتِ مشتری با پیامکِ هما:
 * سبدِ رهاشده، «موجود شد خبرم کن»، تبریکِ تولد با کدِ تخفیف، کدِ رهگیریِ مرسوله، درخواستِ نظر
 * و تشکرِ اولین خرید با کدِ تخفیفِ خریدِ دوم.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Engage {

	const CRON  = 'homacrm_engage_tick';
	const CARTS = 'homacrm_carts';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 120, 'homacrm_15min', self::CRON );
		}
		if ( Settings::get( 'eng_cart' ) ) {
			add_action( 'woocommerce_cart_updated', array( __CLASS__, 'capture_cart' ) );
			add_action( 'wp_ajax_nopriv_homacrm_cart_mobile', array( __CLASS__, 'ajax_cart_mobile' ) );
			add_action( 'wp_ajax_homacrm_cart_mobile', array( __CLASS__, 'ajax_cart_mobile' ) );
			add_action( 'wp_footer', array( __CLASS__, 'cart_mobile_js' ) );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'cart_converted' ), 5 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'cart_converted' ), 5 );
			add_action( 'template_redirect', array( __CLASS__, 'restore_cart' ) );
		}
		if ( Settings::get( 'eng_stock' ) ) {
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'stock_form' ), 31 );
			add_action( 'wp_ajax_nopriv_homacrm_stock_sub', array( __CLASS__, 'ajax_stock_sub' ) );
			add_action( 'wp_ajax_homacrm_stock_sub', array( __CLASS__, 'ajax_stock_sub' ) );
			add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'stock_back' ), 10, 3 );
			add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'stock_back' ), 10, 3 );
		}
		if ( Settings::get( 'eng_birthday' ) ) {
			add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'birthday_field' ) );
			add_action( 'woocommerce_save_account_details', array( __CLASS__, 'birthday_save' ) );
		}
		if ( Settings::get( 'eng_welcome' ) ) {
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'welcome_mark' ), 30, 2 );
		}
		if ( Settings::get( 'eng_tracking' ) ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'tracking_box' ) );
			add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'tracking_save' ), 20 );
			add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'tracking_show' ) );
		}
	}

	public static function schedules( $s ) {
		$s['homacrm_15min'] = array( 'interval' => 900, 'display' => 'HomaCRM 15 min' );
		return $s;
	}

	private static function short( $url ) {
		return esc_url_raw( $url );
	}

	/* ================= سبدِ رهاشده ================= */

	private static function cart_key() {
		if ( ! WC()->session ) {
			return '';
		}
		$k = (string) WC()->session->get( 'homacrm_cart_key' );
		if ( '' === $k ) {
			$k = wp_generate_password( 20, false );
			WC()->session->set( 'homacrm_cart_key', $k );
		}
		return $k;
	}

	private static function carts() {
		$c = get_option( self::CARTS, array() );
		return is_array( $c ) ? $c : array();
	}

	private static function save_carts( $c ) {
		// قدیمی‌تر از ۱۰ روز پاک می‌شود.
		foreach ( $c as $k => $v ) {
			if ( time() - (int) $v['t'] > 10 * DAY_IN_SECONDS ) {
				unset( $c[ $k ] );
			}
		}
		update_option( self::CARTS, $c, false );
	}

	private static function current_mobile() {
		if ( is_user_logged_in() ) {
			$m = Util::mobile( get_user_meta( get_current_user_id(), Login::META, true ) );
			if ( '' === $m ) {
				$m = Util::mobile( get_user_meta( get_current_user_id(), 'billing_phone', true ) );
			}
			return $m;
		}
		return WC()->session ? (string) WC()->session->get( 'homacrm_cart_mobile' ) : '';
	}

	public static function capture_cart() {
		if ( is_admin() || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$m = self::current_mobile();
		$k = self::cart_key();
		$c = self::carts();
		if ( WC()->cart->is_empty() ) {
			unset( $c[ $k ] );
			self::save_carts( $c );
			return;
		}
		if ( '' === $m ) {
			return;
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $it ) {
			$items[] = array( (int) $it['product_id'], (int) $it['variation_id'], (int) $it['quantity'] );
		}
		$prev   = $c[ $k ] ?? array( 'n' => 0 );
		$c[ $k ] = array( 'm' => $m, 'i' => $items, 't' => time(), 'n' => (int) $prev['n'], 'name' => get_the_title( $items[0][0] ) );
		self::save_carts( $c );
	}

	public static function ajax_cart_mobile() {
		$m = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $m && WC()->session ) {
			WC()->session->set( 'homacrm_cart_mobile', $m );
			self::capture_cart();
		}
		wp_send_json_success();
	}

	/** شمارهٔ موبایلِ مهمان در تسویه‌حساب، به محضِ تایپ، برای یادآورِ سبد. */
	public static function cart_mobile_js() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() ) {
			return;
		}
		echo '<script>(function(){var f=document.getElementById("billing_phone");if(!f)return;f.addEventListener("change",function(){var b=new URLSearchParams({action:"homacrm_cart_mobile",mobile:f.value});fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",credentials:"same-origin",body:b});});})();</script>';
	}

	public static function cart_converted() {
		$c = self::carts();
		$k = WC()->session ? (string) WC()->session->get( 'homacrm_cart_key' ) : '';
		if ( '' !== $k && isset( $c[ $k ] ) ) {
			unset( $c[ $k ] );
			self::save_carts( $c );
		}
	}

	public static function restore_cart() {
		$k = isset( $_GET['homacrm_cart'] ) ? sanitize_key( wp_unslash( $_GET['homacrm_cart'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $k || ! WC()->cart ) {
			return;
		}
		$c = self::carts();
		foreach ( $c as $key => $v ) {
			if ( strtolower( $key ) === $k ) {
				WC()->cart->empty_cart();
				foreach ( $v['i'] as $it ) {
					WC()->cart->add_to_cart( $it[0], max( 1, $it[2] ), $it[1] );
				}
				WC()->session->set( 'homacrm_cart_key', $key );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}
	}

	private static function cart_reminders() {
		$c     = self::carts();
		$steps = array( max( 1, (int) Settings::get( 'eng_cart_h1' ) ) * HOUR_IN_SECONDS, max( 1, (int) Settings::get( 'eng_cart_h2' ) ) * HOUR_IN_SECONDS );
		$dirty = false;
		foreach ( $c as $k => $v ) {
			$n = (int) $v['n'];
			if ( $n >= 2 || time() - (int) $v['t'] < $steps[ $n ] ) {
				continue;
			}
			$link = add_query_arg( 'homacrm_cart', strtolower( $k ), home_url( '/' ) );
			$res  = Sms::send( $v['m'], Util::render( (string) Settings::get( 'eng_cart_tpl' ), array( 'site' => get_bloginfo( 'name' ), 'product' => $v['name'], 'link' => $link ) ), 'cart' );
			$c[ $k ]['n'] = $n + 1;
			$dirty        = true;
			if ( ! $res['ok'] ) {
				$c[ $k ]['n'] = 2;
			}
		}
		if ( $dirty ) {
			self::save_carts( $c );
		}
	}

	/* ================= موجود شد خبرم کن ================= */

	public static function stock_form() {
		global $product;
		if ( ! $product || $product->is_in_stock() ) {
			return;
		}
		$u = is_user_logged_in() ? Util::mobile( get_user_meta( get_current_user_id(), Login::META, true ) ) : '';
		echo '<form class="homacrm-stock" onsubmit="event.preventDefault();var f=this,b=new URLSearchParams({action:\'homacrm_stock_sub\',p:\'' . (int) $product->get_id() . '\',m:f.m.value,_n:\'' . esc_js( wp_create_nonce( 'homacrm_stock' ) ) . '\'});f.querySelector(\'button\').disabled=true;fetch(' . esc_attr( wp_json_encode( admin_url( 'admin-ajax.php' ) ) ) . ',{method:\'POST\',body:b}).then(function(r){return r.json()}).then(function(r){f.querySelector(\'p\').textContent=(r.data&&r.data.message)||\'\';f.querySelector(\'button\').disabled=false;});" dir="rtl">'
			. '<b>' . esc_html__( 'ناموجود است؛ موجود شد خبرتان کنیم؟', 'homacrm' ) . '</b>'
			. '<span><input name="m" type="tel" inputmode="numeric" dir="ltr" placeholder="09xxxxxxxxx" value="' . esc_attr( $u ) . '" required><button type="submit" class="button">' . esc_html__( 'خبرم کن', 'homacrm' ) . '</button></span><p></p></form>';
		echo '<style>.homacrm-stock{border:1px dashed #d5d8dd;border-radius:12px;padding:12px;margin:12px 0;display:flex;flex-direction:column;gap:8px}.homacrm-stock span{display:flex;gap:8px}.homacrm-stock input{flex:1;height:42px;border:1px solid #d5d8dd;border-radius:10px;padding:0 10px}.homacrm-stock p{margin:0;font-size:12.5px;color:#1f8a6e}</style>';
	}

	public static function ajax_stock_sub() {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_n'] ?? '' ) ), 'homacrm_stock' ) ) {
			wp_send_json_error( array( 'message' => __( 'صفحه را تازه کنید و دوباره بزنید.', 'homacrm' ) ) );
		}
		$m = Util::mobile( wp_unslash( $_POST['m'] ?? '' ) );
		$p = absint( $_POST['p'] ?? 0 );
		if ( '' === $m || ! $p ) {
			wp_send_json_error( array( 'message' => __( 'شمارهٔ موبایل را درست وارد کنید.', 'homacrm' ) ) );
		}
		$subs = (array) get_post_meta( $p, '_homacrm_stock_subs', true );
		$subs[ $m ] = time();
		update_post_meta( $p, '_homacrm_stock_subs', array_slice( $subs, -500, null, true ) );
		wp_send_json_success( array( 'message' => __( 'ثبت شد؛ به محضِ موجود شدن پیامک می‌گیرید.', 'homacrm' ) ) );
	}

	public static function stock_back( $id, $status, $product = null ) {
		if ( 'instock' !== $status ) {
			return;
		}
		$pid  = $product && $product->get_parent_id() ? $product->get_parent_id() : $id;
		$subs = get_post_meta( $pid, '_homacrm_stock_subs', true );
		if ( ! is_array( $subs ) || ! $subs ) {
			return;
		}
		delete_post_meta( $pid, '_homacrm_stock_subs' );
		$text = Util::render( (string) Settings::get( 'eng_stock_tpl' ), array( 'product' => get_the_title( $pid ), 'site' => get_bloginfo( 'name' ), 'link' => get_permalink( $pid ) ) );
		foreach ( array_keys( $subs ) as $m ) {
			Sms::send( $m, $text, 'stock' );
		}
	}

	/* ================= تولد ================= */

	public static function birthday_field() {
		$v = (string) get_user_meta( get_current_user_id(), 'homacrm_birthday', true ); // m-d جلالی
		list( $mm, $dd ) = array_pad( array_map( 'intval', explode( '-', $v ) ), 2, 0 );
		$months = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
		echo '<p class="form-row form-row-wide"><label>' . esc_html__( 'روز و ماهِ تولد (برای هدیهٔ تولد)', 'homacrm' ) . '</label><span style="display:flex;gap:8px"><select name="homacrm_bd_d"><option value="0">' . esc_html__( 'روز', 'homacrm' ) . '</option>';
		for ( $i = 1; $i <= 31; $i++ ) {
			echo '<option value="' . (int) $i . '"' . selected( $dd, $i, false ) . '>' . (int) $i . '</option>';
		}
		echo '</select><select name="homacrm_bd_m"><option value="0">' . esc_html__( 'ماه', 'homacrm' ) . '</option>';
		foreach ( $months as $i => $n ) {
			echo '<option value="' . (int) ( $i + 1 ) . '"' . selected( $mm, $i + 1, false ) . '>' . esc_html( $n ) . '</option>';
		}
		echo '</select></span></p>';
	}

	public static function birthday_save( $user_id ) {
		$m = absint( $_POST['homacrm_bd_m'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- ووکامرس nonce را سنجیده.
		$d = absint( $_POST['homacrm_bd_d'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31 ) {
			update_user_meta( $user_id, 'homacrm_birthday', $m . '-' . $d );
		} else {
			delete_user_meta( $user_id, 'homacrm_birthday' );
		}
	}

	/** تاریخِ جلالیِ امروز (ماه، روز). */
	public static function jalali_today() {
		$d = current_datetime();
		return Util::jalali( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
	}

	private static function birthdays() {
		list( $jy, $jm, $jd ) = self::jalali_today();
		if ( (int) current_time( 'G' ) < 10 || get_option( 'homacrm_bd_done' ) === "$jy-$jm-$jd" ) {
			return; // روزی یک بار، از ساعتِ ۱۰ صبح.
		}
		update_option( 'homacrm_bd_done', "$jy-$jm-$jd", false );
		$users = get_users( array( 'meta_key' => 'homacrm_birthday', 'meta_value' => "$jm-$jd", 'number' => 500 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$pct   = max( 0, min( 90, (int) Settings::get( 'eng_bd_percent' ) ) );
		foreach ( $users as $u ) {
			$m = Util::mobile( get_user_meta( $u->ID, Login::META, true ) ?: get_user_meta( $u->ID, 'billing_phone', true ) );
			if ( '' === $m ) {
				continue;
			}
			$code = self::coupon( 'BD', $pct, 7, $u->user_email, 'HomaCRM birthday' );
			$name = trim( $u->first_name ) ? $u->first_name : __( 'دوستِ عزیز', 'homacrm' );
			Sms::send( $m, Util::render( (string) Settings::get( 'eng_bd_tpl' ), array( 'name' => $name, 'site' => get_bloginfo( 'name' ), 'code' => $code, 'percent' => $pct ) ), 'birthday' );
		}
	}

	/** کدِ تخفیفِ درصدیِ یک‌بارمصرف؛ ۰٪ یا نبودِ ووکامرس ⇒ رشتهٔ خالی. */
	private static function coupon( $prefix, $pct, $days, $email, $desc ) {
		if ( $pct <= 0 || ! class_exists( '\WC_Coupon' ) ) {
			return '';
		}
		$code = $prefix . strtoupper( wp_generate_password( 6, false ) );
		$cp   = new \WC_Coupon();
		$cp->set_code( $code );
		$cp->set_discount_type( 'percent' );
		$cp->set_amount( $pct );
		$cp->set_usage_limit( 1 );
		$cp->set_email_restrictions( $email ? array( $email ) : array() );
		$cp->set_date_expires( time() + max( 1, (int) $days ) * DAY_IN_SECONDS );
		$cp->set_description( $desc );
		$cp->save();
		return $code;
	}

	/* ================= تشکرِ اولین خرید ================= */

	/** سفارشِ تکمیل‌شده اگر اولین سفارشِ تکمیل‌شدهٔ این مشتری باشد، برای پیامکِ تشکر علامت می‌خورد (ارسال در ساعتِ مجاز). */
	public static function welcome_mark( $order_id, $o = null ) {
		$o = $o instanceof \WC_Order ? $o : wc_get_order( $order_id );
		if ( ! $o || $o->get_meta( '_homacrm_welcome' ) || '' === Util::mobile( $o->get_billing_phone() ) ) {
			return;
		}
		$q = array( 'status' => array( 'completed' ), 'limit' => 2, 'return' => 'ids', 'exclude' => array( $o->get_id() ) );
		if ( $o->get_customer_id() ) {
			$q['customer_id'] = $o->get_customer_id();
		} elseif ( $o->get_billing_email() ) {
			$q['billing_email'] = $o->get_billing_email();
		} else {
			$q['billing_phone'] = $o->get_billing_phone();
		}
		$o->update_meta_data( '_homacrm_welcome', wc_get_orders( $q ) ? 'repeat' : 'due' );
		$o->save();
	}

	private static function welcomes() {
		$ids  = wc_get_orders( array( 'limit' => 50, 'return' => 'ids', 'meta_key' => '_homacrm_welcome', 'meta_value' => 'due' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$pct  = max( 0, min( 90, (int) Settings::get( 'eng_welcome_percent' ) ) );
		$days = max( 1, (int) Settings::get( 'eng_welcome_days' ) );
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			$o->update_meta_data( '_homacrm_welcome', 'sent' );
			$o->save();
			$code = self::coupon( 'TY', $pct, $days, $o->get_billing_email(), 'HomaCRM first purchase' );
			$tpl  = (string) Settings::get( 'eng_welcome_tpl' );
			if ( '' === $code ) { // بی کدِ تخفیف، خطِ کد از متن حذف می‌شود.
				$tpl = implode( "\n", array_filter( explode( "\n", $tpl ), static function ( $l ) { return false === strpos( $l, '{code}' ); } ) );
			}
			$res  = Sms::send( $o->get_billing_phone(), Util::render( $tpl, array( 'name' => $o->get_billing_first_name(), 'site' => get_bloginfo( 'name' ), 'code' => $code, 'percent' => $pct, 'days' => $days ) ), 'welcome' );
			$o->add_order_note( $res['ok'] ? __( 'هما: پیامکِ تشکرِ اولین خرید فرستاده شد.', 'homacrm' ) . ( $code ? ' ' . $code : '' ) : __( 'هما: پیامکِ تشکر نرفت — ', 'homacrm' ) . $res['error'] );
		}
	}

	/* ================= کدِ رهگیری و درخواستِ نظر ================= */

	public static function tracking_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'homacrm_tracking', __( 'کدِ رهگیریِ مرسوله (HomaCRM)', 'homacrm' ), array( __CLASS__, 'tracking_box_html' ), $screen, 'side' );
	}

	public static function tracking_box_html( $post_or_order ) {
		$o = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		$carriers = array( 'post' => 'پست', 'tipax' => 'تیپاکس', 'chapar' => 'چاپار', 'peyk' => 'پیک' );
		echo '<p><select name="homacrm_carrier" style="width:100%">';
		foreach ( $carriers as $k => $n ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $o->get_meta( '_tracking_carrier' ), $k, false ) . '>' . esc_html( $n ) . '</option>';
		}
		echo '</select></p><p><input type="text" name="homacrm_tracking" dir="ltr" style="width:100%" value="' . esc_attr( $o->get_meta( '_tracking_code' ) ) . '" placeholder="کدِ رهگیری"></p><p class="description">' . esc_html__( 'با ذخیره، کدِ رهگیری برای خریدار پیامک می‌شود.', 'homacrm' ) . '</p>';
	}

	public static function tracking_save( $order_id ) {
		if ( ! isset( $_POST['homacrm_tracking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- ووکامرس nonceِ صفحهٔ سفارش را سنجیده.
			return;
		}
		$o    = wc_get_order( $order_id );
		$code = sanitize_text_field( wp_unslash( $_POST['homacrm_tracking'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$car  = sanitize_key( wp_unslash( $_POST['homacrm_carrier'] ?? 'post' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $code || $code === $o->get_meta( '_tracking_code' ) ) {
			return;
		}
		$o->update_meta_data( '_tracking_code', $code );
		$o->update_meta_data( '_tracking_carrier', $car );
		$o->save();
		$links = array( 'post' => 'https://tracking.post.ir/', 'tipax' => 'https://tipaxco.com/tracking', 'chapar' => 'https://chaparnet.com/track/', 'peyk' => '' );
		$names = array( 'post' => 'پست', 'tipax' => 'تیپاکس', 'chapar' => 'چاپار', 'peyk' => 'پیک' );
		$res   = Sms::send( $o->get_billing_phone(), Util::render( (string) Settings::get( 'eng_track_tpl' ), array( 'name' => $o->get_billing_first_name(), 'order' => $o->get_order_number(), 'tracking' => $code, 'carrier' => $names[ $car ] ?? '', 'link' => $links[ $car ] ?? '', 'site' => get_bloginfo( 'name' ) ) ), 'tracking' );
		$o->add_order_note( $res['ok'] ? __( 'هما: کدِ رهگیری پیامک شد.', 'homacrm' ) : __( 'هما: پیامکِ کدِ رهگیری نرفت — ', 'homacrm' ) . $res['error'] );
	}

	public static function tracking_show( $o ) {
		if ( $o->get_meta( '_tracking_code' ) ) {
			echo '<p><strong>' . esc_html__( 'کدِ رهگیری:', 'homacrm' ) . '</strong> <code dir="ltr">' . esc_html( $o->get_meta( '_tracking_code' ) ) . '</code></p>';
		}
	}

	private static function review_requests() {
		$days = max( 1, (int) Settings::get( 'eng_review_days' ) );
		$ids  = wc_get_orders( array( 'status' => 'completed', 'limit' => 50, 'date_completed' => '<' . ( time() - $days * DAY_IN_SECONDS ), 'meta_key' => '_homacrm_review_asked', 'meta_compare' => 'NOT EXISTS', 'return' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			$o->update_meta_data( '_homacrm_review_asked', time() );
			$o->save();
			if ( $o->get_date_completed() && $o->get_date_completed()->getTimestamp() < time() - 30 * DAY_IN_SECONDS ) {
				continue; // سفارش‌های خیلی قدیمی را بی‌صدا رد کن.
			}
			$items = $o->get_items();
			$first = $items ? reset( $items ) : null;
			$link  = $first ? get_permalink( $first->get_product_id() ) . '#reviews' : home_url( '/' );
			Sms::send( $o->get_billing_phone(), Util::render( (string) Settings::get( 'eng_review_tpl' ), array( 'name' => $o->get_billing_first_name(), 'product' => $first ? $first->get_name() : '', 'link' => $link, 'site' => get_bloginfo( 'name' ) ) ), 'review' );
		}
	}

	/* ================= زمان‌بندی ================= */

	/** پیامکِ تبلیغاتی فقط ۹ صبح تا ۹ شب. */
	private static function quiet() {
		$h = (int) current_time( 'G' );
		return $h < 9 || $h >= 21;
	}

	public static function tick() {
		if ( self::quiet() ) {
			return;
		}
		if ( Settings::get( 'eng_cart' ) ) {
			self::cart_reminders();
		}
		if ( Settings::get( 'eng_birthday' ) ) {
			self::birthdays();
		}
		if ( Settings::get( 'eng_review' ) ) {
			self::review_requests();
		}
		if ( Settings::get( 'eng_welcome' ) ) {
			self::welcomes();
		}
	}
}
