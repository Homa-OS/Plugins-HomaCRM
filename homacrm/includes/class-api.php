<?php
/**
 * کلاینتِ API بیرونیِ هما (https://homacrm.com/api/v1/external) با توکن.
 * توکن هرگز در لاگ، پیام یا خروجیِ دیباگ نوشته نمی‌شود.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Api {

	/**
	 * یک درخواست. خروجی همیشه آرایه: ['ok'=>bool, 'status'=>int, 'data'=>mixed, 'error'=>string].
	 *
	 * @param string     $method GET|POST|PUT|DELETE
	 * @param string     $path   مثلاً '/messaging/send'
	 * @param array|null $body   بدنهٔ JSON
	 */
	public static function request( $method, $path, $body = null, $token = null ) {
		$token = null === $token ? Settings::token() : $token;
		if ( '' === $token ) {
			return self::fail( 0, __( 'توکنِ API هما تنظیم نشده است.', 'homacrm' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => ( 'GET' === $method ? 20 : 45 ),
			'headers' => array(
				'Authorization' => $token,
				'Accept'        => 'application/json',
				'User-Agent'    => 'HomaCRM-WordPress/' . HOMACRM_VERSION . '; ' . home_url(),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( Settings::api_base() . $path, $args );
		if ( is_wp_error( $res ) ) {
			return self::fail( 0, __( 'اتصال به هما برقرار نشد: ', 'homacrm' ) . $res->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$json   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $status >= 200 && $status < 300 && is_array( $json ) && ! empty( $json['success'] ) ) {
			return array( 'ok' => true, 'status' => $status, 'data' => isset( $json['data'] ) ? $json['data'] : null, 'error' => '' );
		}
		$msg = '';
		if ( is_array( $json ) ) {
			if ( isset( $json['error']['message'] ) ) {
				$msg = $json['error']['message'];
			} elseif ( isset( $json['message'] ) ) {
				$msg = $json['message'];
			}
		}
		if ( 401 === $status || 403 === $status ) {
			$msg = $msg ? $msg : __( 'توکن نامعتبر است یا دسترسیِ لازم را ندارد.', 'homacrm' );
		}
		$fail = self::fail( $status, $msg ? $msg : sprintf( __( 'خطای هما (HTTP %d)', 'homacrm' ), $status ) );
		if ( is_array( $json ) && isset( $json['error']['details'] ) && is_array( $json['error']['details'] ) ) {
			$fail['details'] = $json['error']['details'];
		}
		return $fail;
	}

	private static function fail( $status, $error ) {
		return array( 'ok' => false, 'status' => (int) $status, 'data' => null, 'error' => (string) $error, 'details' => array() );
	}

	/* ---------- میان‌برها ---------- */

	public static function verify( $token = null ) {
		return self::request( 'GET', '/auth/verify', null, $token );
	}

	public static function credit() {
		return self::request( 'GET', '/messaging/credit' );
	}

	public static function numbers() {
		return self::request( 'GET', '/messaging/numbers' );
	}

	/** ارسالِ یک پیامک. $sender خالی = خطِ پیش‌فرضِ پرتال. */
	public static function send_sms( $mobile, $message, $sender = '' ) {
		$body = array( 'recipient' => Util::mobile( $mobile ), 'message' => (string) $message );
		if ( '' !== (string) $sender ) {
			$body['senderId'] = (string) $sender;
		}
		return self::request( 'POST', '/messaging/send', $body );
	}

	public static function create_contact( array $c ) {
		return self::request( 'POST', '/crm/contacts', $c );
	}

	public static function create_lead( array $l ) {
		return self::request( 'POST', '/crm/leads', $l );
	}

	public static function create_deal( array $d ) {
		return self::request( 'POST', '/crm/deals', $d );
	}

	public static function pipelines() {
		return self::request( 'GET', '/crm/pipelines' );
	}

	public static function create_ticket( $title, $description, $priority = 1 ) {
		return self::request( 'POST', '/support/tickets', array( 'title' => $title, 'description' => $description, 'priority' => (int) $priority ) );
	}
}
