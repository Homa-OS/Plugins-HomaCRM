<?php
/**
 * ابزارهای کوچک: یکسان‌کردنِ موبایل، قالبِ متن، شمارهٔ فارسی.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Util {

	/** موبایلِ ایرانی به 09xxxxxxxxx؛ نامعتبر ⇒ رشتهٔ خالی. */
	public static function mobile( $raw ) {
		$s = strtr( (string) $raw, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) );
		$d = preg_replace( '/\D+/', '', $s );
		if ( 0 === strpos( $d, '0098' ) ) {
			$d = substr( $d, 4 );
		} elseif ( 0 === strpos( $d, '98' ) && 12 === strlen( $d ) ) {
			$d = substr( $d, 2 );
		}
		if ( 10 === strlen( $d ) && '9' === $d[0] ) {
			$d = '0' . $d;
		}
		return preg_match( '/^09\d{9}$/', $d ) ? $d : '';
	}

	/** جایگذاریِ {name} در متن. */
	public static function render( $template, array $vars ) {
		$pairs = array();
		foreach ( $vars as $k => $v ) {
			$pairs[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( (string) $template, $pairs );
	}

	/** موبایل با چهار رقمِ آخرِ پیدا، برای لاگ. */
	public static function mask( $mobile ) {
		$m = (string) $mobile;
		return strlen( $m ) > 4 ? str_repeat( '•', strlen( $m ) - 4 ) . substr( $m, -4 ) : $m;
	}
}
