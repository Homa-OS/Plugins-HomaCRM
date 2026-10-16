<?php
/**
 * ارسالِ پیامک از راهِ هما + ثبت در لاگ. همهٔ بخش‌ها از همین‌جا می‌فرستند.
 * برای توسعه‌دهنده: do_action('homacrm_send_sms', $mobile, $message) یا HomaCRM\Sms::send().
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Sms {

	/**
	 * @param string $context برچسبِ لاگ: otp، woo، admin، test، api …
	 * @return array ['ok'=>bool,'error'=>string,'data'=>mixed]
	 */
	public static function send( $mobile, $message, $context = 'api' ) {
		$m = Util::mobile( $mobile );
		if ( '' === $m ) {
			return array( 'ok' => false, 'error' => __( 'شمارهٔ موبایل نامعتبر است.', 'homacrm' ), 'data' => null );
		}
		$message = (string) apply_filters( 'homacrm_sms_message', $message, $m, $context );
		if ( '' === trim( $message ) ) {
			return array( 'ok' => false, 'error' => __( 'متنِ پیامک خالی است.', 'homacrm' ), 'data' => null );
		}
		$res = Api::send_sms( $m, $message, (string) Settings::get( 'sender' ) );
		// متنِ کدِ ورود در لاگ نوشته نمی‌شود.
		Log::add( $context, $m, $res['ok'], $res['ok'] ? '' : $res['error'], 'otp' === $context ? '' : $message );
		do_action( 'homacrm_sms_sent', $m, $message, $context, $res );
		return array( 'ok' => $res['ok'], 'error' => $res['error'], 'data' => $res['data'] );
	}
}

