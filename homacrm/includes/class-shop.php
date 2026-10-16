<?php
/**
 * فروشگاهِ هما: کالا، قیمت و موجودی از انبارِ هما؛ سفارشِ ووکامرس ⇒ فاکتورِ فروش
 * (با اثرِ انبار و سند) و پرداختِ آن ⇒ دریافت در خزانهٔ هما.
 *
 * کالای ووکامرس با متای `_homa_api_id` (شناسهٔ کالا در هما) به هما وصل است.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Shop {

	const CRON = 'homacrm_shop_sync';
	const META = '_homa_api_id';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'sync' ) );
		if ( Settings::get( 'shop_sync' ) ) {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + 60, 'homacrm_15min', self::CRON );
			}
			// لحظه‌ای: پیش از نهایی‌شدنِ سبد، موجودیِ زنده از هما.
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		} elseif ( wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
		if ( Settings::get( 'shop_invoice' ) ) {
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'paid' ), 30, 1 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ), 30, 1 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ), 30, 1 );
		}
	}

	public static function schedules( $s ) {
		$s['homacrm_15min'] = array( 'interval' => 900, 'display' => 'HomaCRM 15 min' );
		return $s;
	}

	/** توکنِ انبار و فاکتور: اگر جدا تنظیم شده همان، وگرنه توکنِ اصلی. */
	private static function api( $method, $path, $body = null ) {
		$t = trim( (string) get_option( 'homacrm_shop_token', '' ) );
		return Api::request( $method, $path, $body, '' !== $t ? $t : null );
	}

	/* ---------- کالا، قیمت، موجودی ---------- */

	/** همهٔ صفحه‌های یک فهرستِ هما. */
	private static function pages( $path ) {
		$out = array();
		for ( $page = 1; $page <= 200; $page++ ) {
			$r = self::api( 'GET', $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'page=' . $page . '&pageSize=100' );
			if ( ! $r['ok'] || ! is_array( $r['data'] ) ) {
				return $out ? $out : null;
			}
			$out = array_merge( $out, $r['data'] );
			if ( count( $r['data'] ) < 100 ) {
				break;
			}
		}
		return $out;
	}

	/** شناسهٔ هما ⇒ شناسهٔ کالای ووکامرس. */
	private static function map() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$m    = array();
		foreach ( $rows as $r ) {
			$m[ (string) $r->meta_value ] = (int) $r->post_id;
		}
		return $m;
	}

	private static function price( $v ) {
		$f = (float) Settings::get( 'shop_price_factor' );
		return round( (float) $v * ( $f > 0 ? $f : 1 ) );
	}

	/** همگام‌سازیِ دوره‌ای: قیمت و موجودی از هما به ووکامرس. */
	public static function sync() {
		$map = self::map();
		if ( ! $map ) {
			return array( 'ok' => true, 'updated' => 0 );
		}
		$products = self::pages( '/warehouse/products' );
		$stock    = self::pages( '/warehouse/inventory' );
		if ( null === $products && null === $stock ) {
			Log::add( 'shop', '', false, __( 'همگام‌سازیِ کالا: هما در دسترس نبود.', 'homacrm' ) );
			return array( 'ok' => false, 'updated' => 0 );
		}
		$qty = array();
		foreach ( (array) $stock as $s ) {
			$qty[ (string) $s['productId'] ] = (float) $s['stock'];
		}
		$n = 0;
		foreach ( (array) $products as $p ) {
			$id = (string) $p['id'];
			if ( ! isset( $map[ $id ] ) ) {
				continue;
			}
			$wc = wc_get_product( $map[ $id ] );
			if ( ! $wc ) {
				continue;
			}
			if ( ! empty( $p['categoryId'] ) && (string) $wc->get_meta( '_homa_cat_id' ) !== (string) $p['categoryId'] ) {
				$wc->update_meta_data( '_homa_cat_id', (string) $p['categoryId'] );
				$wc->save_meta_data();
			}
			$changed = false;
			$price   = self::price( $p['price'] ?? 0 );
			if ( $price > 0 && (float) $wc->get_regular_price() !== (float) $price && '' === (string) $wc->get_sale_price() ) {
				$wc->set_regular_price( (string) $price );
				$changed = true;
			}
			if ( empty( $p['isActive'] ) && 'publish' === $wc->get_status() ) {
				$wc->set_status( 'draft' );
				$changed = true;
			}
			if ( isset( $qty[ $id ] ) && ! $wc->get_meta( '_homa_unlimited' ) ) {
				$changed = self::apply_stock( $wc, $qty[ $id ] ) || $changed;
			}
			if ( $changed ) {
				$wc->save();
				++$n;
			}
		}
		update_option( 'homacrm_shop_last_sync', array( 'at' => time(), 'updated' => $n ), false );
		return array( 'ok' => true, 'updated' => $n );
	}

	private static function apply_stock( \WC_Product $wc, $q ) {
		$q = (int) floor( $q );
		if ( $wc->get_manage_stock() && (int) $wc->get_stock_quantity() === $q ) {
			return false;
		}
		$wc->set_manage_stock( true );
		$wc->set_stock_quantity( max( 0, $q ) );
		$wc->set_stock_status( $q > 0 ? 'instock' : 'outofstock' );
		return true;
	}

	/** موجودیِ زندهٔ یک کالا (کشِ یک‌دقیقه‌ای)؛ null یعنی نامعلوم. */
	public static function live_stock( $homa_id ) {
		$key = 'homacrm_stk_' . $homa_id;
		$c   = get_transient( $key );
		if ( false !== $c ) {
			return (float) $c;
		}
		$r = self::api( 'GET', '/warehouse/inventory?productId=' . rawurlencode( (string) $homa_id ) );
		if ( ! $r['ok'] || empty( $r['data'][0] ) ) {
			return null;
		}
		$q = (float) $r['data'][0]['stock'];
		set_transient( $key, $q, MINUTE_IN_SECONDS );
		return $q;
	}

	/** پیش از پرداخت: اگر هما کمتر از سبد موجودی دارد، جلوی سفارش را می‌گیرد. */
	public static function check_cart() {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$wc = $item['data'];
			$pid = $wc->get_parent_id() ? $wc->get_parent_id() : $wc->get_id();
			$hid = get_post_meta( $pid, self::META, true );
			if ( ! $hid || get_post_meta( $pid, '_homa_unlimited', true ) ) {
				continue;
			}
			$q = self::live_stock( $hid );
			if ( null === $q ) {
				continue; // هما در دسترس نیست؛ فروش را نمی‌بندیم.
			}
			$p = wc_get_product( $pid );
			if ( $p && self::apply_stock( $p, $q ) ) {
				$p->save();
			}
			if ( $q < (float) $item['quantity'] ) {
				wc_add_notice( sprintf( __( 'از «%1$s» فقط %2$s عدد موجود است؛ تعداد را در سبد اصلاح کنید.', 'homacrm' ), $wc->get_name(), max( 0, (int) $q ) ), 'error' );
			}
		}
	}

	/* ---------- سفارش ⇒ فاکتور ---------- */

	/** شناسهٔ مشتری در هما (موجود یا تازه). */
	private static function customer( \WC_Order $o ) {
		$m = Util::mobile( $o->get_billing_phone() );
		if ( '' === $m ) {
			return 0;
		}
		$c = array_filter(
			array(
				'firstName' => $o->get_billing_first_name(),
				'lastName'  => $o->get_billing_last_name() ? $o->get_billing_last_name() : '-',
				'mobile'    => $m,
				'email'     => $o->get_billing_email(),
				'address'   => trim( $o->get_billing_state() . ' ' . $o->get_billing_city() . ' ' . $o->get_billing_address_1() . ' ' . $o->get_billing_address_2() ),
				'notes'     => sprintf( __( 'خریدار از سایتِ %s', 'homacrm' ), home_url() ),
			),
			static function ( $v ) { return '' !== (string) $v; }
		);
		$g = (int) Settings::get( 'crm_group_id' );
		if ( $g > 0 ) {
			$c['groupId'] = $g;
		}
		$r = self::api( 'POST', '/crm/contacts', $c );
		if ( $r['ok'] && isset( $r['data']['id'] ) ) {
			return (int) $r['data']['id'];
		}
		return isset( $r['details']['existingId'] ) ? (int) $r['details']['existingId'] : 0;
	}

	/**
	 * سفارشِ پرداخت‌شده یا تأییدشده ⇒ فاکتورِ فروشِ نهایی در هما (اثرِ انبار و سند).
	 * سفارشِ پرداخت‌نشده فاکتور نمی‌سازد تا انبارِ هما با سبدهای رهاشده کم نشود.
	 */
	public static function invoice( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o || $o->get_meta( '_homacrm_invoice_id' ) ) {
			return;
		}
		$customer = self::customer( $o );
		if ( ! $customer ) {
			$o->add_order_note( __( 'هما: فاکتور ساخته نشد — موبایلِ خریدار معتبر نیست یا مشتری ثبت نشد.', 'homacrm' ) );
			$o->save();
			return;
		}
		$items   = array();
		$missing = array();
		foreach ( $o->get_items() as $it ) {
			$pid = $it->get_product_id();
			$hid = (int) get_post_meta( $pid, self::META, true );
			$q   = (float) $it->get_quantity();
			if ( $hid <= 0 || $q <= 0 ) {
				$missing[] = $it->get_name();
				continue;
			}
			$items[] = array(
				'productId' => $hid,
				'quantity'  => $q,
				'unitPrice' => self::to_homa( (float) $it->get_total() / $q ),
				'comments'  => $it->get_name(),
				'catId'     => (int) get_post_meta( $pid, '_homa_cat_id', true ),
			);
		}
		$ship = (float) $o->get_shipping_total();
		$ware = (int) Settings::get( 'shop_shipping_ware' );
		if ( $ship > 0 && $ware > 0 ) {
			$items[] = array( 'productId' => $ware, 'quantity' => 1, 'unitPrice' => self::to_homa( $ship ), 'comments' => $o->get_shipping_method() );
		}
		if ( ! $items ) {
			$o->add_order_note( __( 'هما: هیچ قلمِ این سفارش به کالای هما وصل نیست؛ فاکتور ساخته نشد.', 'homacrm' ) );
			$o->save();
			return;
		}
		$note = sprintf( __( 'سفارشِ %1$s از %2$s', 'homacrm' ), $o->get_order_number(), wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $ship > 0 && $ware <= 0 ) {
			$note .= ' · ' . sprintf( __( 'هزینهٔ ارسال: %s', 'homacrm' ), wc_format_decimal( $ship, 0 ) );
		}
		if ( $o->get_customer_note() ) {
			$note .= ' · ' . $o->get_customer_note();
		}
		$send = array_map( static function ( $x ) { unset( $x['catId'] ); return $x; }, $items );
		$r = self::api(
			'POST',
			'/finance/invoices',
			array(
				'customerId' => $customer,
				'items'      => $send,
				'status'     => 'ready',
				'comments'   => $note,
			)
		);
		if ( $r['ok'] && ! empty( $r['data']['id'] ) ) {
			$o->update_meta_data( '_homacrm_invoice_id', (int) $r['data']['id'] );
			$o->update_meta_data( '_homacrm_customer_id', $customer );
			$o->add_order_note( sprintf( __( 'هما: فاکتورِ %1$s (شناسهٔ %2$d) ثبت شد.', 'homacrm' ), $r['data']['serial'] ?? '', (int) $r['data']['id'] ) . ( $missing ? ' ' . __( 'اقلامِ بی‌کدِ هما: ', 'homacrm' ) . implode( '، ', $missing ) : '' ) );
			$o->save();
			self::warehouse_out( $o, $items, (int) $r['data']['id'], (string) ( $r['data']['serial'] ?? '' ) );
		} else {
			$o->add_order_note( __( 'هما: ثبتِ فاکتور ناموفق بود — ', 'homacrm' ) . $r['error'] );
		}
		$o->save();
		Log::add( 'shop', Util::mobile( $o->get_billing_phone() ), $r['ok'], $r['ok'] ? '' : $r['error'], 'invoice #' . $o->get_order_number() );
	}

	/** کسرِ انبار: حوالهٔ خروجِ اقلامِ فاکتور از انبارِ هر کالا (یک بار برای هر سفارش). */
	private static function warehouse_out( \WC_Order $o, array $items, $invoice_id, $serial ) {
		if ( $o->get_meta( '_homacrm_wh_id' ) || ! Settings::get( 'shop_wh_out' ) ) {
			return;
		}
		$lines = array();
		foreach ( $items as $it ) {
			$cat = (int) ( $it['catId'] ?? 0 );
			if ( ! $cat ) {
				// شناسهٔ انبارِ کالا: از همگام‌سازی، وگرنه با جست‌وجوی نام در فهرستِ کالاهای هما.
				$pr = self::api( 'GET', '/warehouse/products?pageSize=20&search=' . rawurlencode( (string) $it['comments'] ) );
				foreach ( ( $pr['ok'] && is_array( $pr['data'] ) ) ? $pr['data'] : array() as $row ) {
					if ( (int) $row['id'] === (int) $it['productId'] ) {
						$cat = (int) $row['categoryId'];
					}
				}
			}
			if ( $cat ) {
				$lines[] = array( 'productId' => (int) $it['productId'], 'quantity' => $it['quantity'], 'categoryId' => $cat );
			}
		}
		if ( ! $lines ) {
			return;
		}
		$r = self::api( 'POST', '/warehouse/inventory/movements', array(
			'type'      => 'out',
			'lines'     => $lines,
			'title'     => sprintf( __( 'حوالهٔ فروشِ اینترنتی — فاکتورِ %s', 'homacrm' ), $serial ),
			'reference' => 'invoice:' . $invoice_id,
			'comments'  => sprintf( __( 'سفارشِ %1$s از %2$s', 'homacrm' ), $o->get_order_number(), wp_parse_url( home_url(), PHP_URL_HOST ) ),
		) );
		if ( $r['ok'] ) {
			$o->update_meta_data( '_homacrm_wh_id', $r['data']['id'] ?? 1 );
			$o->add_order_note( __( 'هما: حوالهٔ خروج از انبار ثبت شد.', 'homacrm' ) );
		} else {
			$o->add_order_note( __( 'هما: کسرِ انبار ناموفق بود — ', 'homacrm' ) . $r['error'] );
		}
		$o->save();
	}

	/** پرداختِ موفق یا تأییدِ سفارش ⇒ فاکتور، و اگر پول رسیده، دریافت روی همان فاکتور. */
	public static function paid( $order_id ) {
		$o = wc_get_order( $order_id );
		if ( ! $o || $o->get_meta( '_homacrm_payment_id' ) || ! $o->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}
		if ( ! $o->get_meta( '_homacrm_invoice_id' ) ) {
			self::invoice( $order_id );
			$o = wc_get_order( $order_id );
		}
		$inv = (int) $o->get_meta( '_homacrm_invoice_id' );
		if ( $inv <= 0 || 'cod' === $o->get_payment_method() && 'completed' !== $o->get_status() ) {
			return;
		}
		$bank = (int) Settings::get( 'shop_bank_id' );
		$body = array(
			'operation' => 'receive',
			'method'    => $bank > 0 ? 'bank' : 'cash',
			'amount'    => self::to_homa( (float) $o->get_total() ),
			'invoiceId' => $inv,
			'tagNumber' => (string) $o->get_transaction_id(),
			'comments'  => sprintf( __( 'پرداختِ سفارشِ %1$s (%2$s)', 'homacrm' ), $o->get_order_number(), $o->get_payment_method_title() ),
		);
		if ( $bank > 0 ) {
			$body['bankId'] = $bank;
		}
		// اگر دریافتی با همین مبلغ روی فاکتور هست (مثلاً پاسخِ قبلی دیر رسید)، دوباره ثبت نکن.
		$ex = self::api( 'GET', '/finance/payments?invoiceId=' . $inv . '&pageSize=20' );
		foreach ( ( $ex['ok'] && is_array( $ex['data'] ) ) ? $ex['data'] : array() as $pay ) {
			if ( (float) ( $pay['amount'] ?? 0 ) === (float) $body['amount'] ) {
				$o->update_meta_data( '_homacrm_payment_id', $pay['id'] );
				$o->add_order_note( __( 'هما: دریافتِ این فاکتور از قبل ثبت بود.', 'homacrm' ) );
				$o->save();
				return;
			}
		}
		$r = self::api( 'POST', '/finance/payments', $body );
		if ( $r['ok'] ) {
			$o->update_meta_data( '_homacrm_payment_id', $r['data']['id'] ?? 1 );
			$o->add_order_note( __( 'هما: دریافتِ وجه روی فاکتور ثبت شد.', 'homacrm' ) );
		} else {
			$o->add_order_note( __( 'هما: ثبتِ دریافت ناموفق بود — ', 'homacrm' ) . $r['error'] );
		}
		$o->save();
	}

	/** مبلغِ ووکامرس ⇒ واحدِ هما (معکوسِ ضریبِ قیمت). */
	private static function to_homa( $v ) {
		$f = (float) Settings::get( 'shop_price_factor' );
		return round( $v / ( $f > 0 ? $f : 1 ) );
	}
}
