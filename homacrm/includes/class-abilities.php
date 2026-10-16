<?php
/**
 * هما برای هوش مصنوعی و ابزارهای بیرونی:
 *  - REST: /wp-json/homacrm/v1/… (فقط مدیرانِ سایت، با Application Password وردپرس).
 *  - Abilities API وردپرس (پایهٔ MCPِ وردپرس): اگر در این نسخهٔ وردپرس هست، توانایی‌های هما ثبت می‌شوند
 *    تا عامل‌های MCP (مثل Claude) بتوانند پیامک بفرستند، اعتبار ببینند و سرنخ بسازند.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'abilities' ) );
	}

	public static function can() {
		return current_user_can( 'manage_options' );
	}

	public static function rest() {
		register_rest_route(
			'homacrm/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => static function () {
					$v = Api::verify();
					$c = $v['ok'] ? Api::credit() : array( 'ok' => false, 'data' => null );
					return array( 'connected' => $v['ok'], 'portal' => $v['ok'] ? ( $v['data']['portalCode'] ?? null ) : null, 'credit' => $c['ok'] ? $c['data'] : null, 'version' => HOMACRM_VERSION );
				},
			)
		);
		register_rest_route(
			'homacrm/v1',
			'/sms',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'args'                => array( 'mobile' => array( 'required' => true ), 'message' => array( 'required' => true ) ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = Sms::send( $r['mobile'], sanitize_textarea_field( $r['message'] ), 'rest' );
					return new \WP_REST_Response( $res, $res['ok'] ? 200 : 400 );
				},
			)
		);
		register_rest_route(
			'homacrm/v1',
			'/lead',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = Api::create_lead( array_filter( array( 'firstName' => sanitize_text_field( (string) $r['firstName'] ), 'lastName' => sanitize_text_field( (string) $r['lastName'] ), 'mobile' => Util::mobile( $r['mobile'] ), 'email' => sanitize_email( (string) $r['email'] ), 'notes' => sanitize_textarea_field( (string) $r['notes'] ) ) ) );
					return new \WP_REST_Response( $res, $res['ok'] ? 200 : 400 );
				},
			)
		);
	}

	public static function abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'homacrm/send-sms',
			array(
				'label'               => __( 'ارسالِ پیامک با هما', 'homacrm' ),
				'description'         => __( 'یک پیامک به یک شمارهٔ موبایلِ ایرانی از خطِ پرتالِ هما می‌فرستد.', 'homacrm' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'mobile' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ) ), 'required' => array( 'mobile', 'message' ) ),
				'execute_callback'    => static function ( $in ) {
					return Sms::send( $in['mobile'] ?? '', sanitize_textarea_field( $in['message'] ?? '' ), 'ability' );
				},
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		wp_register_ability(
			'homacrm/credit',
			array(
				'label'               => __( 'اعتبارِ پیامکِ هما', 'homacrm' ),
				'description'         => __( 'اعتبارِ پیامکِ پرتالِ هما را برمی‌گرداند.', 'homacrm' ),
				'execute_callback'    => static function () {
					return Api::credit();
				},
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		wp_register_ability(
			'homacrm/create-lead',
			array(
				'label'               => __( 'ساختِ سرنخ در CRMِ هما', 'homacrm' ),
				'description'         => __( 'یک سرنخ با نام، موبایل، ایمیل و یادداشت در CRMِ هما می‌سازد.', 'homacrm' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'firstName' => array( 'type' => 'string' ), 'lastName' => array( 'type' => 'string' ), 'mobile' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string' ), 'notes' => array( 'type' => 'string' ) ) ),
				'execute_callback'    => static function ( $in ) {
					$in['mobile'] = Util::mobile( $in['mobile'] ?? '' );
					return Api::create_lead( array_filter( array_map( 'sanitize_text_field', (array) $in ) ) );
				},
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
	}
}
