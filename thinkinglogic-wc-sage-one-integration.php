<?php
/*
 * Plugin Name:				ThinkingLogic WooCommerce / Sage One Integration
 * Plugin URI:				http:/github.com/ThinkingLogic/wc-sage-one-integration
 * Description:				An integration with the Sage One api (v3.1) for WooCommerce - allowing orders to be turned into Sage One invoices at the click of a button (per order). One invoice will be created per order per month. Each invoice will be allocated a fraction of the total cost of the order, according to how many lessons occur in that month.
 * Version:					0.2
 * Requires at least:		4.4
 * Tested up to:			5.4
 * WC requires at least:	3.0
 * WC tested up to:			4.3
 * Text Domain:				tl-wc-sage-one-integration
 * Author:					Thinking Logic
 * Author URI:				https://github.com/ThinkingLogic/wc-sage-one-integration
 * License:					MIT
 * License URI:				http://www.opensource.org/licenses/MIT
 * Domain Path:				/languages
 */

use ThinkingLogic\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once( dirname( __FILE__ ) . '/Logger.php' );
require_once( dirname( __FILE__ ) . '/SageApiClient.php' );
require_once( dirname( __FILE__ ) . '/class-thinking-logic-wc-sage.php' );


if ( is_admin() ) { // admin actions
	add_action( 'admin_menu', 'tl_wc_sage_admin_menu' );
	add_action( 'admin_init', 'tl_wc_sage_register_settings' );
	add_action( 'woocommerce_order_actions_end', 'tl_wc_sage_order_actions' );
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'tl_wc_sage_order_customer_link' );
	add_action( 'save_post', 'tl_wc_sage_create_invoices', 10, 3 );
	add_action( 'admin_notices', 'tl_wc_display_admin_notices' );
	add_action( 'woocommerce_product_options_advanced', 'tl_wc_sage_show_product_fields' );
	add_action( 'woocommerce_process_product_meta', 'tl_wc_sage_save_product_fields' );
} else {
	// non-admin enqueues, actions, and filters
}

// handle authorisation callbacks
add_action( 'parse_request', 'tl_wc_sage_callback_handler' );
function tl_wc_sage_callback_handler() {
	$requestPath = substr( $_SERVER["REQUEST_URI"], 0, strlen( ThinkingLogicWCSage::CALLBACK_REQUEST_PATH ) );
	if ( isset( $_GET['code'] ) && $requestPath === ThinkingLogicWCSage::CALLBACK_REQUEST_PATH ) {
		$sageone_client      = ThinkingLogicWCSage::sageClient();
		$authorisation_code  = $_GET['code'];
		$authorisation_state = $_GET['state'];
		Logger::log( "Received authorisation code : " . $authorisation_code . " with state " . $authorisation_state );
		/* Exchange the authorisation code for an access_token */
		$response = $sageone_client->getInitialAccessToken( $authorisation_code, $authorisation_state );
		Logger::log( "Got response from Sage: " . $response );
		if ( isset( $response ) ) {
			// redirect back to settings
			wp_redirect( home_url( '/wp-admin/options-general.php?page=tl-wc-sage-plugin-options' ) );
			die;
		}
	}
}

/**
 * Add a settings menu.
 */
function tl_wc_sage_admin_menu() {
	add_options_page( 'Sage One Integration', 'Sage One Integration', 'manage_options', ThinkingLogicWCSage::OPTIONS_GROUP, 'tl_wc_sage_plugin_options' );
}

/** Show the settings page. */
function tl_wc_sage_plugin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	include_once( dirname( __FILE__ ) . '/options.php' );
}

/**
 * The fields used for our settings
 */
function tl_wc_sage_register_settings() { // whitelist options
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_CLIENT_ID );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_CLIENT_SECRET );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_REFRESH_TOKEN );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_REFRESH_TOKEN_EXPIRES_AT );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_ACCESS_TOKEN );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_ACCESS_TOKEN_EXPIRES );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_CALLBACK_URL );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_SHIPPING_TAX_ID );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_LINE_ITEM_TAX_ID );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_LEDGER_CODES );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, ThinkingLogicWCSage::OPTION_DEFAULT_ACCRUALS_LEDGER_CODE );

	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, Logger::OPTION_CUSTOM_NOTICES );
	register_setting( ThinkingLogicWCSage::OPTIONS_GROUP, Logger::OPTION_LOG_DEBUG );
}

/**
 * Show the create sage invoice button.
 *
 * @param integer $order_id The order identifier
 */
function tl_wc_sage_order_actions( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( 'processing' == $order->get_status() ) {
		$sage = ThinkingLogicWCSage::sageClient();
		echo '<li class="wide">';
		echo '   <div id="tl_wc_sage_order_actions" style="text-align: center;">';
		if ( $sage->isRefreshTokenExpiringSoon() ) {
			echo '<a class="button" href="' . $sage->authorizationEndpoint() . '">Refresh Authorisation for Sage</a>';
		} else {
			if ( $order->meta_exists( ThinkingLogicWCSage::ORDER_FIELD_CUSTOMER_ID ) ) {
				echo '<input type="submit"
					class="button button-primary tl_wc_sage_order tl_wc_sage_customer_exists "
					name="' . ThinkingLogicWCSage::CREATE_INVOICE_BUTTON_ID . '"
					value="(Re)create Sage Invoice(s)"/>';
			} else {
				echo '<input type="submit"
					class="button button-primary tl_wc_sage_order "
					name="' . ThinkingLogicWCSage::CREATE_INVOICE_BUTTON_ID . '"
					value="Create Sage Invoice(s)"/>';
			}
		}
		echo '   </div>';
		echo '</li>';
	}
}

/**
 * Function to create invoices in Sage One, invoked by clicking a button on the edit order admin screen.
 *
 * @param <type> $post_id The post identifier
 * @param <type> $post The post
 * @param <type> $update The update
 */
function tl_wc_sage_create_invoices( $post_id, $post, $update ) {
	// If this isn't a 'woocommercer order' post, don't update it.
	if ( ! is_admin() || 'shop_order' != $post->post_type ) {
		return;
	}
	if ( isset( $_POST[ ThinkingLogicWCSage::CREATE_INVOICE_BUTTON_ID ] ) && $_POST[ ThinkingLogicWCSage::CREATE_INVOICE_BUTTON_ID ] ) {
		$sage = ThinkingLogicWCSage::instance();
		$sage->handleCreateSageInvoices( $post_id );
	}
}

/**
 * Display a link to the customer in SageOne if possible.
 *
 * @param WC_Order $order The order
 */
function tl_wc_sage_order_customer_link( $order ) {
	if ( $order->meta_exists( ThinkingLogicWCSage::ORDER_FIELD_CUSTOMER_LINK ) ) {
		echo '<p><a href="' . $order->get_meta( ThinkingLogicWCSage::ORDER_FIELD_CUSTOMER_LINK ) . '">View customer in SageOne</a></p>';
	} else if ( $order->meta_exists( ThinkingLogicWCSage::ORDER_FIELD_CUSTOMER_ID ) ) {
		echo '<p><a href="' . ThinkingLogicWCSage::SAGEONE_UI_URL_BASE . '/contacts/customers/' . $order->get_meta( ThinkingLogicWCSage::ORDER_FIELD_CUSTOMER_ID ) . '">View customer in SageOne</a></p>';
	}
}

/**
 * Adds sales and accruals ledger id fields to products.
 */
function tl_wc_sage_show_product_fields() {
	woocommerce_wp_text_input(
		array(
			'id'          => ThinkingLogicWCSage::PRODUCT_FIELD_LEDGER_CODE,
			'label'       => 'Sales Ledger code',
			'desc_tip'    => 'true',
			'description' => 'The code of the Ledger that purchases of this product should be assigned to in SageOne.',
		)
	);

	woocommerce_wp_text_input(
		array(
			'id'          => ThinkingLogicWCSage::PRODUCT_FIELD_ACCRUALS_LEDGER_CODE,
			'label'       => 'Accruals Ledger code',
			'desc_tip'    => 'true',
			'description' => 'The code of the Ledger that monies should be journalled to until redeemed. Only needed if different to the default accruals ledger code.',
		)
	);

}

/**
 * Saves the ledger code fields on product save.
 *
 * @param <type> $post_id The post identifier
 */
function tl_wc_sage_save_product_fields( $post_id ) {
	$ledger_code = $_POST[ ThinkingLogicWCSage::PRODUCT_FIELD_LEDGER_CODE ];
	if ( ! empty( $ledger_code ) ) {
		update_post_meta( $post_id, ThinkingLogicWCSage::PRODUCT_FIELD_LEDGER_CODE, esc_html( $ledger_code ) );
	}
	$accruals_code = $_POST[ ThinkingLogicWCSage::PRODUCT_FIELD_ACCRUALS_LEDGER_CODE ];
	if ( ! empty( $accruals_code ) ) {
		update_post_meta( $post_id, ThinkingLogicWCSage::PRODUCT_FIELD_ACCRUALS_LEDGER_CODE, esc_html( $accruals_code ) );
	}
}

/**
 * Displays and clears all custom notices.
 *
 * @param <type> $post_id The post identifier
 */
function tl_wc_display_admin_notices( $post_id ) {
	Logger::showAdminNotices();
	Logger::clearAdminNotices();
}
