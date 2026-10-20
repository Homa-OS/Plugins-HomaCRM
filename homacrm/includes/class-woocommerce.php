<?php
/**
 * ووکامرس: پیامکِ وضعیتِ سفارش به مشتری، پیامکِ سفارشِ تازه به مدیران،
 * ثبتِ خریدار به‌عنوانِ مخاطب و (اختیاری) سفارش به‌عنوانِ معامله در CRMِ هما.
 * سازگار با HPOS (ذخیره‌سازیِ سفارشِ جدیدِ ووکامرس).
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Woocommerce {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'new_order_obj' ), 20, 1 );
		// سفارشی که مدیر در پیشخوان یا برنامه‌ای با REST می‌سازد هم «سفارشِ تازه» است.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'admin_order' ), 50, 1 );
		add_action( 'woocommerce_rest_insert_shop_order_object', array( __CLASS__, 'rest_order' ), 50, 3 );
	}

	/** سفارشِ دستیِ پیشخوان: فقط سفارشِ تازه‌ساخت (نه ویرایشِ سفارش‌های قدیمی). */
	public static function admin_order( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o || 'admin' !== $o->get_created_via() || in_array( $o->get_status(), array( 'auto-draft', 'checkout-draft', 'draft', 'trash' ), true ) ) {
			return;
		}
		$c = $o->get_date_created();
		if ( $c && $c->getTimestamp() < time() - DAY_IN_SECONDS ) {
			return;
		}
		self::new_order( $o->get_id() );
	}

	public static function rest_order( $order, $request, $creating ) {
		if ( $creating && $order instanceof \WC_Order ) {
			self::new_order( $order->get_id() );
		}
	}

	private static function vars( \WC_Order $o ) {
		return array(
			'name'     => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
			'order'    => $o->get_order_number(),
			'total'    => wp_strip_all_tags( html_entity_decode( $o->get_formatted_order_total() ) ),
			'status'   => wc_get_order_status_name( $o->get_status() ),
			'site'     => get_bloginfo( 'name' ),
			'mobile'   => Util::mobile( $o->get_billing_phone() ),
			'tracking' => (string) $o->get_meta( '_tracking_code' ),
			// لینکِ پرداختِ دوبارهٔ همان سفارش؛ فقط وقتی سفارش هنوز پرداخت‌پذیر است (در انتظارِ پرداخت/ناموفق).
			'pay'      => $o->needs_payment() ? $o->get_checkout_payment_url() : '',
		);
	}

	public static function status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof \WC_Order || ! in_array( $to, (array) Settings::get( 'woo_statuses' ), true ) ) {
			return;
		}
		$tpl = (string) Settings::get( 'woo_tpl_' . $to );
		$m   = Util::mobile( $order->get_billing_phone() );
		if ( '' === $m || '' === trim( $tpl ) ) {
			return;
		}
		// هر وضعیت برای هر سفارش فقط یک بار پیامک می‌شود.
		$flag = '_homacrm_sms_' . $to;
		if ( $order->get_meta( $flag ) ) {
			return;
		}
		$res = Sms::send( $m, Util::render( $tpl, self::vars( $order ) ), 'woo' );
		$order->update_meta_data( $flag, $res['ok'] ? 'sent' : 'failed' );
		$order->add_order_note( $res['ok'] ? __( 'هما: پیامکِ وضعیت فرستاده شد.', 'homacrm' ) : __( 'هما: پیامکِ وضعیت فرستاده نشد — ', 'homacrm' ) . $res['error'] );
		$order->save();
	}

	public static function new_order_obj( $order ) {
		if ( $order instanceof \WC_Order ) {
			self::new_order( $order->get_id() );
		}
	}

	public static function new_order( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o || $o->get_meta( '_homacrm_new_done' ) ) {
			return;
		}
		$o->update_meta_data( '_homacrm_new_done', 1 );
		$o->save();
		$vars = self::vars( $o );

		// پیامک به مدیران
		$admins = Util::mobiles( Settings::get( 'woo_admin_mobiles' ) );
		foreach ( $admins['valid'] as $a ) {
			Sms::send( $a, Util::render( Settings::get( 'woo_admin_template' ), $vars ), 'admin' );
		}

		// خریدار ⇒ مخاطب، سفارش ⇒ معامله (اختیاری)
		if ( '' === $vars['mobile'] ) {
			return;
		}
		$contact_id = 0;
		if ( Settings::get( 'woo_sync_contact' ) ) {
			$contact_id = Crm_Sync::push_contact(
				array(
					'firstName' => $o->get_billing_first_name(),
					'lastName'  => $o->get_billing_last_name(),
					'mobile'    => $vars['mobile'],
					'email'     => $o->get_billing_email(),
					'address'   => trim( $o->get_billing_city() . ' ' . $o->get_billing_address_1() ),
				)
			);
		}
		$pipeline = (int) Settings::get( 'woo_deal_pipeline' );
		$stage    = (int) Settings::get( 'woo_deal_stage' );
		if ( $pipeline > 0 && $stage > 0 ) {
			$deal = array(
				'title'      => sprintf( __( 'سفارش %1$s — %2$s', 'homacrm' ), $vars['order'], get_bloginfo( 'name' ) ),
				'pipelineId' => $pipeline,
				'stageId'    => $stage,
				'amount'     => (float) $o->get_total(),
			);
			if ( $contact_id > 0 ) {
				$deal['contactId'] = $contact_id;
			}
			$r = Api::create_deal( $deal );
			$o->add_order_note( $r['ok'] ? __( 'هما: معامله در CRM ثبت شد.', 'homacrm' ) : __( 'هما: ثبتِ معامله ممکن نشد — ', 'homacrm' ) . $r['error'] );
			$o->save();
		}
	}
}
