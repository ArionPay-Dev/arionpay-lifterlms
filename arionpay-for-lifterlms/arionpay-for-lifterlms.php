<?php
/**
 * Plugin Name:       ArionPay for LifterLMS
 * Plugin URI:        https://arionpay.com
 * Description:       Sell courses and memberships for Bitcoin, USDT, Litecoin, Ethereum, TRON and more in LifterLMS. The money goes straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-for-lifterlms
 *
 * Flow: checkout makes a pending LifterLMS order and sends the student to the ArionPay page. Only ArionPay itself (a
 * signed webhook, or a signed invoice read when the student opens the order page) records the payment; LifterLMS
 * then enrolls the student. Recurring plans: each payment is a new invoice that the student pays by hand (crypto
 * cannot charge automatically); access pauses until it is paid. Whether an invoice is paid is decided by ArionPay
 * alone; this plugin never compares crypto amounts.
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_LLMS_VERSION', '1.0.0' );

add_action( 'plugins_loaded', 'arionpay_llms_load', 20 );

function arionpay_llms_load() {
	if ( ! class_exists( 'LLMS_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			static function () {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'ArionPay for LifterLMS needs the LifterLMS plugin. Install and activate it first.', 'arionpay-for-lifterlms' ) . '</p></div>';
				}
			}
		);
		return;
	}
	if ( ! class_exists( '\ArionPay\Client', true ) ) {
		require_once __DIR__ . '/lib/arionpay-php/autoload.php';
	}
	require_once __DIR__ . '/includes/class-llms-payment-gateway-arionpay.php';
	add_filter(
		'lifterlms_payment_gateways',
		static function ( $gateways ) {
			$gateways[] = 'LLMS_Payment_Gateway_ArionPay';
			return $gateways;
		}
	);
	add_action( 'rest_api_init', array( 'LLMS_Payment_Gateway_ArionPay', 'register_routes' ) );
}
