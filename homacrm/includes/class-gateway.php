<?php
/**
 * درگاهِ پرداختِ هما برای ووکامرس.
 *
 * سفارش پیش از پرداخت در هما فاکتور می‌شود و خریدار با شمارهٔ همان فاکتور به درگاهِ هما می‌رود.
 * بازگشت از درگاه هیچ‌وقت به‌تنهایی «پرداخت شد» حساب نمی‌شود: افزونه با APIِ هما دریافت‌های ثبت‌شده
 * روی همان فاکتور را می‌خواند و فقط اگر مبلغ کامل رسیده باشد سفارش را پرداخت‌شده می‌کند.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Gateway_Loader {
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', static function ( $g ) {
			$g[] = Gateway::class;
			return $g;
		} );
	}
}

if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
	return;
}

class Gateway extends \WC_Payment_Gateway {

	const PAY_URL = 'https://api.homais.com/services/payment/';

	public function __construct() {
		$this->id                 = 'homacrm_pay';
		$this->method_title       = __( 'درگاهِ پرداختِ HomaCRM', 'homacrm' );
		$this->method_description = __( 'پرداختِ آنلاین از درگاهِ هما؛ سفارش در هما فاکتور می‌شود و پرداخت با APIِ هما تأیید می‌شود.', 'homacrm' );
		$this->has_fields         = false;
		$this->icon               = HOMACRM_URL . 'assets/icon.svg';
		$this->supports           = array( 'products' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_homacrm_pay', array( $this, 'callback' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array( 'title' => __( 'فعال', 'homacrm' ), 'type' => 'checkbox', 'label' => __( 'درگاهِ هما روشن باشد', 'homacrm' ), 'default' => 'no' ),
			'title'       => array( 'title' => __( 'عنوان', 'homacrm' ), 'type' => 'text', 'default' => __( 'پرداختِ آنلاین (درگاهِ امنِ HomaCRM)', 'homacrm' ) ),
			'description' => array( 'title' => __( 'توضیح', 'homacrm' ), 'type' => 'textarea', 'default' => __( 'پرداخت با همهٔ کارت‌های عضوِ شتاب.', 'homacrm' ) ),
			'rial'        => array( 'title' => __( 'واحدِ مبلغِ درگاه', 'homacrm' ), 'type' => 'checkbox', 'label' => __( 'قیمت‌های فروشگاه تومان است؛ برای درگاه ×۱۰ (ریال) شود', 'homacrm' ), 'default' => 'yes' ),
		);
	}

	public function is_available() {
		return parent::is_available() && '' !== Settings::token() && Settings::get( 'shop_invoice' );
	}

	private function portal_code() {
		$pc = (string) get_transient( 'homacrm_pay_portal' );
		if ( '' === $pc ) {
			$t  = trim( (string) get_option( 'homacrm_shop_token', '' ) );
			$v  = Api::verify( '' !== $t ? $t : null );
			$pc = $v['ok'] ? (string) ( $v['data']['portalCode'] ?? '' ) : '';
			if ( '' !== $pc ) {
				set_transient( 'homacrm_pay_portal', $pc, DAY_IN_SECONDS );
			}
		}
		return $pc;
	}

	private function amount( \WC_Order $o ) {
		return (int) round( (float) $o->get_total() * ( 'yes' === $this->get_option( 'rial' ) ? 10 : 1 ) );
	}

	public function process_payment( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o->get_meta( '_homacrm_invoice_id' ) ) {
			Shop::invoice( $order_id );
			$o = wc_get_order( $order_id );
		}
		$inv = (int) $o->get_meta( '_homacrm_invoice_id' );
		$pc  = $this->portal_code();
		if ( $inv <= 0 || '' === $pc ) {
			wc_add_notice( __( 'اتصال به درگاه ممکن نشد؛ چند لحظهٔ دیگر دوباره امتحان کنید یا روشِ دیگری انتخاب کنید.', 'homacrm' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$back = add_query_arg( array( 'order' => $o->get_id(), 'key' => $o->get_order_key() ), WC()->api_request_url( 'homacrm_pay' ) );
		$url  = self::PAY_URL . '?' . http_build_query(
			array(
				'portalcode'  => $pc,
				'CellNumber'  => Util::mobile( $o->get_billing_phone() ),
				'memberid'    => (int) $o->get_meta( '_homacrm_customer_id' ),
				'amount'      => max( 10000, $this->amount( $o ) ),
				'clubID'      => $pc,
				'byCredit'    => 'False',
				'objectType'  => 100,
				'objectID'    => $inv,
				'PaymentType' => 1,
				'ROS'         => $back,
				'ROF'         => add_query_arg( 'fail', 1, $back ),
			)
		);
		$o->update_status( 'pending', __( 'هما: به درگاهِ پرداخت فرستاده شد.', 'homacrm' ) );
		return array( 'result' => 'success', 'redirect' => $url );
	}

	/** مجموعِ دریافت‌های ثبت‌شده روی فاکتور در هما (پاسخِ درگاه به‌تنهایی معتبر نیست). */
	public static function paid_on_invoice( $inv ) {
		$r   = Shop::api( 'GET', '/finance/payments?invoiceId=' . (int) $inv . '&pageSize=50' );
		$sum = 0.0;
		$id  = '';
		foreach ( ( $r['ok'] && is_array( $r['data'] ) ) ? $r['data'] : array() as $p ) {
			if ( 'receive' === ( $p['direction'] ?? 'receive' ) ) {
				$sum += (float) $p['amount'];
				$id   = (string) $p['id'];
			}
		}
		return array( 'ok' => $r['ok'], 'sum' => $sum, 'id' => $id );
	}

	public function callback() {
		$o = wc_get_order( absint( $_GET['order'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $o || ! hash_equals( $o->get_order_key(), (string) wp_unslash( $_GET['key'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		if ( $o->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $o ) );
			exit;
		}
		$inv  = (int) $o->get_meta( '_homacrm_invoice_id' );
		$need = (float) $o->get_total();
		$ok   = false;
		// چند بار، چون ثبتِ دریافت در هما ممکن است چند ثانیه بعد از بازگشت برسد.
		for ( $i = 0; $i < 4 && ! $ok; $i++ ) {
			$p = self::paid_on_invoice( $inv );
			// مبلغ ممکن است به تومان یا ریال ثبت شده باشد؛ هر دو کامل حساب می‌شوند.
			$ok = $p['ok'] && $p['sum'] > 0 && ( $p['sum'] >= $need );
			if ( ! $ok && empty( $_GET['fail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				sleep( 2 );
			} else {
				break;
			}
		}
		if ( $ok ) {
			$o->update_meta_data( '_homacrm_payment_id', $p['id'] );
			$o->save();
			$o->payment_complete( $p['id'] );
			$o->add_order_note( sprintf( __( 'هما: پرداخت روی فاکتور تأیید شد (دریافتِ %s).', 'homacrm' ), $p['id'] ) );
			WC()->cart && WC()->cart->empty_cart();
			wp_safe_redirect( $this->get_return_url( $o ) );
			exit;
		}
		$o->update_status( 'failed', __( 'هما: پرداخت تأیید نشد (دریافتی روی فاکتور ثبت نشده بود).', 'homacrm' ) );
		wc_add_notice( __( 'پرداخت انجام نشد یا هنوز تأیید نشده است. اگر مبلغ از حسابتان کم شده، تا ۷۲ ساعت برمی‌گردد؛ یا با پشتیبانی تماس بگیرید.', 'homacrm' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}
}
