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

	/**
	 * فهرستِ چند موبایل (مثلِ موبایلِ مدیران) با هر جداکننده‌ای: «,» «،» «؛» فاصله یا خطِ تازه.
	 * پیش‌تر فقط «,» شناخته می‌شد و «،»ِ فارسی دو شماره را یکی و نامعتبر می‌کرد.
	 *
	 * @return array{valid: string[], invalid: string[]}
	 */
	public static function mobiles( $raw ) {
		$valid   = array();
		$invalid = array();
		foreach ( preg_split( '/[\r\n,،;؛]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $chunk ) {
			// «0912 123 4567» یک شماره است؛ «09121234567 09351234567» دو شماره.
			$m     = self::mobile( $chunk );
			$parts = '' !== $m ? array( $chunk ) : preg_split( '/\s+/u', trim( $chunk ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $parts as $part ) {
				$m = self::mobile( $part );
				if ( '' === $m ) {
					$invalid[] = $part;
				} else {
					$valid[ $m ] = $m;
				}
			}
		}
		return array( 'valid' => array_values( $valid ), 'invalid' => $invalid );
	}

	/** تاریخِ میلادی ⇒ جلالی: [سال، ماه، روز]. */
	public static function jalali( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + (int) ( ( $gy2 + 3 ) / 4 ) - (int) ( ( $gy2 + 99 ) / 100 ) + (int) ( ( $gy2 + 399 ) / 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * (int) ( $days / 12053 ) );
		$days %= 12053;
		$jy   += 4 * (int) ( $days / 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy   += (int) ( ( $days - 1 ) / 365 );
			$days  = ( $days - 1 ) % 365;
		}
		$jm = ( $days < 186 ) ? 1 + (int) ( $days / 31 ) : 7 + (int) ( ( $days - 186 ) / 30 );
		$jd = 1 + ( ( $days < 186 ) ? ( $days % 31 ) : ( ( $days - 186 ) % 30 ) );
		return array( $jy, $jm, $jd );
	}

	/**
	 * نمایشِ تاریخ به شمسی و در منطقهٔ زمانیِ سایت، با رقمِ لاتین: «1405/07/28 13:40».
	 *
	 * @param int|string|\DateTimeInterface $when مهرِ زمانی، شیءِ تاریخ، یا «Y-m-d[ H:i:s]» به وقتِ UTC
	 */
	public static function jdate( $when, $with_time = true ) {
		if ( $when instanceof \DateTimeInterface ) {
			$ts = $when->getTimestamp();
		} elseif ( is_numeric( $when ) ) {
			$ts = (int) $when;
		} else {
			$ts = strtotime( (string) $when . ( 10 === strlen( (string) $when ) ? ' 12:00:00' : '' ) . ' UTC' );
		}
		if ( ! $ts ) {
			return '';
		}
		$d = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
		list( $jy, $jm, $jd ) = self::jalali( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) . ( $with_time ? ' ' . $d->format( 'H:i' ) : '' );
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
