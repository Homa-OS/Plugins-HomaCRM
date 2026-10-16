<?php
/**
 * پاک‌کردنِ تنظیمات، توکن و جدولِ لاگِ هما هنگامِ حذفِ افزونه.
 * دادهٔ کاربران (موبایلِ ثبت‌شده در پروفایل) دست نمی‌خورد.
 *
 * @package HomaCRM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'homacrm_settings' );
delete_option( 'homacrm_token' );
delete_option( 'homacrm_shop_token' );
delete_option( 'homacrm_db_version' );
wp_clear_scheduled_hook( 'homacrm_daily' );
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'homacrm_log' ); // phpcs:ignore WordPress.DB
