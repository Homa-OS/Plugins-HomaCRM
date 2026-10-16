<?php
/**
 * همگام‌سازیِ کاربرانِ وردپرس با «مخاطبان»ِ CRMِ هما.
 * تکراری‌بودنِ موبایل در هما خطا نیست (یعنی مخاطب از قبل هست).
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Crm_Sync {

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20 );
		add_action( 'homacrm_user_registered', array( __CLASS__, 'on_register' ), 20 );
	}

	public static function on_register( $user_id ) {
		if ( get_user_meta( $user_id, '_homacrm_contact_id', true ) ) {
			return;
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		$m = Util::mobile( get_user_meta( $user_id, Login::META, true ) ?: get_user_meta( $user_id, 'billing_phone', true ) );
		if ( '' === $m ) {
			return;
		}
		$id = self::push_contact(
			array(
				'firstName' => $u->first_name,
				'lastName'  => $u->last_name,
				'mobile'    => $m,
				'email'     => $u->user_email,
			)
		);
		if ( $id > 0 ) {
			update_user_meta( $user_id, '_homacrm_contact_id', $id );
		}
	}

	/** مخاطب می‌سازد؛ شناسهٔ تازه یا ۰ (تکراری/خطا). */
	public static function push_contact( array $c ) {
		$c = array_filter( $c, static function ( $v ) { return '' !== (string) $v; } );
		if ( empty( $c['mobile'] ) ) {
			return 0;
		}
		$g = (int) Settings::get( 'crm_group_id' );
		if ( $g > 0 ) {
			$c['groupId'] = $g;
		}
		$c['notes'] = sprintf( __( 'از سایتِ %s (افزونهٔ هما)', 'homacrm' ), home_url() );
		$r = Api::create_contact( $c );
		return ( $r['ok'] && isset( $r['data']['id'] ) ) ? (int) $r['data']['id'] : 0;
	}
}
