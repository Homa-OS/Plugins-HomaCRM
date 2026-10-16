<?php
/**
 * فرمِ تماس/درخواست ([homacrm_form]) و اتصالِ Contact Form 7:
 * هر فرم ⇒ سرنخ در CRM، تیکتِ پشتیبانی، یا هر دو.
 *
 * @package HomaCRM
 */

namespace HomaCRM;

defined( 'ABSPATH' ) || exit;

final class Forms {

	public static function init() {
		add_shortcode( 'homacrm_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_homacrm_form', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_homacrm_form', array( __CLASS__, 'submit' ) );
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'cf7' ) );
	}

	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'title' => __( 'درخواستِ تماس', 'homacrm' ), 'button' => __( 'ارسال', 'homacrm' ) ), $atts, 'homacrm_form' );
		wp_enqueue_style( 'homacrm-login', HOMACRM_URL . 'assets/login.css', array(), HOMACRM_VERSION );
		$done = isset( $_GET['homacrm_form'] ) ? sanitize_key( $_GET['homacrm_form'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		ob_start();
		?>
		<form class="homacrm-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" dir="rtl">
			<p class="homacrm-otp-title"><?php echo esc_html( $atts['title'] ); ?></p>
			<?php if ( 'ok' === $done ) : ?><p class="homacrm-msg homacrm-ok"><?php esc_html_e( 'دریافت شد؛ به‌زودی با شما تماس می‌گیریم.', 'homacrm' ); ?></p><?php endif; ?>
			<?php if ( 'err' === $done ) : ?><p class="homacrm-msg homacrm-bad"><?php esc_html_e( 'ارسال نشد؛ شماره را بررسی کنید و دوباره بفرستید.', 'homacrm' ); ?></p><?php endif; ?>
			<?php wp_nonce_field( 'homacrm_form', '_homacrm' ); ?>
			<input type="hidden" name="action" value="homacrm_form">
			<input type="hidden" name="back" value="<?php echo esc_url( get_permalink() ); ?>">
			<input type="text" name="name" required placeholder="<?php esc_attr_e( 'نام و نام خانوادگی', 'homacrm' ); ?>">
			<input type="tel" name="mobile" required placeholder="09xxxxxxxxx" dir="ltr">
			<input type="email" name="email" placeholder="<?php esc_attr_e( 'ایمیل (اختیاری)', 'homacrm' ); ?>" dir="ltr">
			<textarea name="message" rows="4" placeholder="<?php esc_attr_e( 'پیام', 'homacrm' ); ?>"></textarea>
			<input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="homacrm-hp" aria-hidden="true">
			<button type="submit" class="button button-primary"><?php echo esc_html( $atts['button'] ); ?></button>
			<p class="homacrm-brand"><?php esc_html_e( 'با', 'homacrm' ); ?> <a href="https://homacrm.com/?utm_source=wp-plugin&amp;utm_medium=form" target="_blank" rel="noopener">HomaCRM.com</a></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function submit() {
		$back = esc_url_raw( wp_unslash( $_POST['back'] ?? home_url() ) );
		if ( ! isset( $_POST['_homacrm'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_homacrm'] ) ), 'homacrm_form' ) || ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( add_query_arg( 'homacrm_form', 'err', $back ) );
			exit;
		}
		$ok = self::push(
			sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			wp_unslash( $_POST['mobile'] ?? '' ),
			sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
			$back
		);
		wp_safe_redirect( add_query_arg( 'homacrm_form', $ok ? 'ok' : 'err', $back ) . '#homacrm' );
		exit;
	}

	/** Contact Form 7: اگر فیلدی به نامِ mobile (یا your-mobile/phone) داشت، به هما می‌رود. */
	public static function cf7( $form ) {
		if ( ! class_exists( '\WPCF7_Submission' ) ) {
			return;
		}
		$sub = \WPCF7_Submission::get_instance();
		if ( ! $sub ) {
			return;
		}
		$d = $sub->get_posted_data();
		$pick = static function ( array $keys ) use ( $d ) {
			foreach ( $keys as $k ) {
				if ( ! empty( $d[ $k ] ) ) {
					return is_array( $d[ $k ] ) ? implode( ' ', $d[ $k ] ) : (string) $d[ $k ];
				}
			}
			return '';
		};
		$mobile = $pick( array( 'mobile', 'your-mobile', 'phone', 'your-phone', 'tel' ) );
		if ( '' === Util::mobile( $mobile ) ) {
			return;
		}
		self::push( $pick( array( 'your-name', 'name' ) ), $mobile, $pick( array( 'your-email', 'email' ) ), $pick( array( 'your-message', 'message' ) ), $sub->get_meta( 'url' ) );
	}

	private static function push( $name, $mobile, $email, $message, $source ) {
		$m = Util::mobile( $mobile );
		if ( '' === $m ) {
			return false;
		}
		$target = (string) Settings::get( 'forms_target' );
		$ok     = true;
		$note   = trim( $message . "\n\n" . sprintf( __( 'از فرمِ سایت: %s', 'homacrm' ), $source ) );
		if ( 'lead' === $target || 'both' === $target ) {
			$parts = preg_split( '/\s+/u', trim( $name ), 2 );
			$r     = Api::create_lead( array_filter( array( 'firstName' => $parts[0] ?? '', 'lastName' => $parts[1] ?? '', 'mobile' => $m, 'email' => $email, 'notes' => $note ) ) );
			$ok    = $ok && $r['ok'];
		}
		if ( 'ticket' === $target || 'both' === $target ) {
			$r  = Api::create_ticket( sprintf( __( 'درخواست از سایت — %s', 'homacrm' ), $name ? $name : $m ), $note . "\n" . sprintf( __( 'موبایل: %1$s · ایمیل: %2$s', 'homacrm' ), $m, $email ) );
			$ok = $ok && $r['ok'];
		}
		do_action( 'homacrm_form_submitted', $m, $name, $email, $message, $ok );
		return $ok;
	}
}
