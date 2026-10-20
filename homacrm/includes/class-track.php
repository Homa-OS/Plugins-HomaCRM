<?php
/**
 * پیگیریِ سفارش برای هر خریدار، حتی مهمان: کدِ کوتاهِ [homacrm_track].
 * خریدار شمارهٔ سفارش و موبایل را می‌زند، کدِ پیامکی به موبایلِ همان سفارش می‌رود و بعد از تأیید
 * وضعیت، اقلام، مبلغ و کدِ رهگیریِ مرسوله را می‌بیند.
 *
 * امنیت: پاسخِ درخواستِ کد همیشه یکسان است (وجودِ سفارش لو نمی‌رود)، کد فقط به‌صورتِ HMAC می‌ماند،
 * ۵ تلاشِ غلط کد را باطل می‌کند و همان سقف‌های ورود با پیامک (IP و موبایل) برقرار است.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Track {

	public static function init() {
		add_shortcode( 'homacrm_track', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_nopriv_homacrm_track_request', array( __CLASS__, 'ajax_request' ) );
		add_action( 'wp_ajax_homacrm_track_request', array( __CLASS__, 'ajax_request' ) );
		add_action( 'wp_ajax_nopriv_homacrm_track_verify', array( __CLASS__, 'ajax_verify' ) );
		add_action( 'wp_ajax_homacrm_track_verify', array( __CLASS__, 'ajax_verify' ) );
	}

	public static function shortcode() {
		$ajax = wp_json_encode( admin_url( 'admin-ajax.php' ) );
		ob_start();
		?>
		<div class="homacrm-track" dir="rtl">
			<p class="homacrm-track-t"><?php esc_html_e( 'پیگیریِ سفارش', 'homacrm' ); ?></p>
			<div class="homacrm-track-a">
				<input type="text" inputmode="numeric" class="homacrm-track-o" placeholder="<?php esc_attr_e( 'شمارهٔ سفارش', 'homacrm' ); ?>" dir="ltr" aria-label="<?php esc_attr_e( 'شمارهٔ سفارش', 'homacrm' ); ?>">
				<input type="tel" inputmode="numeric" class="homacrm-track-m" placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" maxlength="16" aria-label="<?php esc_attr_e( 'موبایلِ ثبت‌شده در سفارش', 'homacrm' ); ?>">
				<button type="button" class="button homacrm-track-send"><?php esc_html_e( 'دریافتِ کد', 'homacrm' ); ?></button>
			</div>
			<div class="homacrm-track-b" hidden>
				<input type="text" inputmode="numeric" autocomplete="one-time-code" class="homacrm-track-c" placeholder="<?php esc_attr_e( 'کدِ پیامک‌شده', 'homacrm' ); ?>" dir="ltr">
				<button type="button" class="button button-primary homacrm-track-go"><?php esc_html_e( 'نمایشِ سفارش', 'homacrm' ); ?></button>
			</div>
			<p class="homacrm-track-msg" role="status" aria-live="polite"></p>
			<div class="homacrm-track-r"></div>
		</div>
		<style>.homacrm-track [hidden]{display:none!important}.homacrm-track{max-width:440px;border:1px solid #e3e5e8;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:10px}.homacrm-track-t{margin:0;font-weight:700}.homacrm-track-a,.homacrm-track-b{display:flex;flex-direction:column;gap:8px}.homacrm-track input{height:44px;border:1px solid #d5d8dd;border-radius:10px;padding:0 12px;font-size:16px}.homacrm-track .button{min-height:44px}.homacrm-track-msg{margin:0;font-size:13px;color:#555}.homacrm-track-r table{width:100%;border-collapse:collapse}.homacrm-track-r td,.homacrm-track-r th{padding:6px 4px;border-bottom:1px solid #eee;text-align:right}</style>
		<script>(function(){var r=document.currentScript.previousElementSibling.previousElementSibling,q=function(s){return r.querySelector(s)},msg=q('.homacrm-track-msg'),fa=function(v){return String(v||'').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d)})};function post(a,d,cb){d.action=a;fetch(<?php echo $ajax; // phpcs:ignore WordPress.Security.EscapeOutput ?>,{method:'POST',credentials:'same-origin',body:new URLSearchParams(d)}).then(function(x){return x.json()}).then(cb).catch(function(){msg.textContent='<?php echo esc_js( __( 'مشکلی پیش آمد؛ دوباره امتحان کنید.', 'homacrm' ) ); ?>'})}q('.homacrm-track-send').onclick=function(){var b=this;b.disabled=true;post('homacrm_track_request',{order:fa(q('.homacrm-track-o').value),mobile:fa(q('.homacrm-track-m').value)},function(j){b.disabled=false;msg.textContent=(j.data&&j.data.message)||'';if(j.success){q('.homacrm-track-b').hidden=false;q('.homacrm-track-c').focus()}})};q('.homacrm-track-go').onclick=function(){var b=this;b.disabled=true;post('homacrm_track_verify',{order:fa(q('.homacrm-track-o').value),mobile:fa(q('.homacrm-track-m').value),code:fa(q('.homacrm-track-c').value)},function(j){b.disabled=false;msg.textContent=(j.data&&j.data.message)||'';if(j.success){q('.homacrm-track-r').innerHTML=j.data.html;q('.homacrm-track-b').hidden=true}})}})();</script>
		<?php
		return (string) ob_get_clean();
	}

	/** سفارش با شمارهٔ سفارش و موبایلِ صورت‌حساب؛ نبود یا ناهمخوانی ⇒ null. */
	private static function find( $number, $mobile ) {
		$number = (string) preg_replace( '/[^0-9A-Za-z-]/', '', (string) $number );
		if ( '' === $number || '' === $mobile ) {
			return null;
		}
		$id = absint( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ) );
		$o  = $id ? wc_get_order( $id ) : null;
		if ( ! $o instanceof \WC_Order || $o instanceof \WC_Order_Refund || Util::mobile( $o->get_billing_phone() ) !== $mobile ) {
			return null;
		}
		return $o;
	}

	private static function key( $number, $mobile ) {
		return 'homacrm_trk_' . md5( $number . '|' . $mobile );
	}

	public static function ajax_request() {
		$mobile = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- مهمان؛ محافظ سقفِ تکرار است.
		$number = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $mobile || '' === $number ) {
			wp_send_json_error( array( 'message' => __( 'شمارهٔ سفارش و موبایل را کامل وارد کنید.', 'homacrm' ) ), 400 );
		}
		$ip   = Login::ip();
		$wait = max( Login::limited( 'trk_ip_' . md5( $ip ), 15 ), Login::limited( 'trk_m_' . $mobile, 5 ) );
		if ( $wait > 0 ) {
			wp_send_json_error( array( 'message' => __( 'تعدادِ درخواست‌ها زیاد بود؛ کمی بعد دوباره امتحان کنید.', 'homacrm' ) ), 429 );
		}
		Login::hit( 'trk_ip_' . md5( $ip ) );
		$same = __( 'اگر شمارهٔ سفارش و موبایل درست باشد، کد تا چند لحظهٔ دیگر پیامک می‌شود.', 'homacrm' );
		$last = (int) get_transient( 'homacrm_trk_last_' . $mobile );
		if ( $last && time() - $last < 60 ) {
			wp_send_json_success( array( 'message' => $same ) );
		}
		$o = self::find( $number, $mobile );
		if ( $o ) {
			$code = (string) random_int( 10000, 99999 );
			$res  = Sms::send( $mobile, Util::render( (string) Settings::get( 'track_tpl' ), array( 'order' => $o->get_order_number(), 'site' => get_bloginfo( 'name' ), 'code' => $code ) ), 'track' );
			if ( $res['ok'] ) {
				set_transient( self::key( $number, $mobile ), array( 'h' => hash_hmac( 'sha256', $o->get_id() . '|' . $code, wp_salt( 'auth' ) ), 'id' => $o->get_id(), 'tries' => 0 ), 5 * MINUTE_IN_SECONDS );
				set_transient( 'homacrm_trk_last_' . $mobile, time(), 60 );
				Login::hit( 'trk_m_' . $mobile );
			}
		}
		wp_send_json_success( array( 'message' => $same ) );
	}

	public static function ajax_verify() {
		$mobile = Util::mobile( wp_unslash( $_POST['mobile'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$number = sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$code   = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$key    = self::key( $number, $mobile );
		$rec    = get_transient( $key );
		if ( ! is_array( $rec ) ) {
			wp_send_json_error( array( 'message' => __( 'کد منقضی شده یا درست نیست؛ دوباره کد بگیرید.', 'homacrm' ) ), 400 );
		}
		if ( ! hash_equals( (string) $rec['h'], hash_hmac( 'sha256', $rec['id'] . '|' . $code, wp_salt( 'auth' ) ) ) ) {
			$rec['tries'] = (int) $rec['tries'] + 1;
			if ( $rec['tries'] >= 5 ) {
				delete_transient( $key );
				wp_send_json_error( array( 'message' => __( 'چند بار اشتباه وارد شد؛ کدِ تازه بگیرید.', 'homacrm' ) ), 400 );
			}
			set_transient( $key, $rec, 5 * MINUTE_IN_SECONDS );
			wp_send_json_error( array( 'message' => __( 'کد درست نیست.', 'homacrm' ) ), 400 );
		}
		delete_transient( $key );
		$o = wc_get_order( (int) $rec['id'] );
		if ( ! $o ) {
			wp_send_json_error( array( 'message' => __( 'سفارش پیدا نشد.', 'homacrm' ) ), 404 );
		}
		wp_send_json_success( array( 'message' => '', 'html' => self::details( $o ) ) );
	}

	private static function details( \WC_Order $o ) {
		$names = array( 'post' => 'پست', 'tipax' => 'تیپاکس', 'chapar' => 'چاپار', 'peyk' => 'پیک' );
		$date  = $o->get_date_created();
		$h     = '<table><tr><th>' . esc_html__( 'سفارش', 'homacrm' ) . '</th><td dir="ltr">#' . esc_html( $o->get_order_number() ) . '</td></tr>';
		$h    .= '<tr><th>' . esc_html__( 'وضعیت', 'homacrm' ) . '</th><td><b>' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</b></td></tr>';
		if ( $date ) {
			$h .= '<tr><th>' . esc_html__( 'تاریخ', 'homacrm' ) . '</th><td dir="ltr">' . esc_html( Util::jdate( $date ) ) . '</td></tr>';
		}
		foreach ( $o->get_items() as $it ) {
			$h .= '<tr><th>' . esc_html__( 'کالا', 'homacrm' ) . '</th><td>' . esc_html( $it->get_name() . ' × ' . $it->get_quantity() ) . '</td></tr>';
		}
		$h .= '<tr><th>' . esc_html__( 'مبلغ', 'homacrm' ) . '</th><td>' . esc_html( wp_strip_all_tags( html_entity_decode( $o->get_formatted_order_total() ) ) ) . '</td></tr>';
		if ( $o->get_meta( '_tracking_code' ) ) {
			$car = $names[ (string) $o->get_meta( '_tracking_carrier' ) ] ?? '';
			$h  .= '<tr><th>' . esc_html__( 'کدِ رهگیریِ مرسوله', 'homacrm' ) . '</th><td>' . esc_html( $car ) . ' <code dir="ltr">' . esc_html( $o->get_meta( '_tracking_code' ) ) . '</code></td></tr>';
		}
		if ( $o->needs_payment() ) {
			$h .= '<tr><th></th><td><a class="button" href="' . esc_url( $o->get_checkout_payment_url() ) . '">' . esc_html__( 'پرداختِ سفارش', 'homacrm' ) . '</a></td></tr>';
		}
		return $h . '</table>';
	}
}
