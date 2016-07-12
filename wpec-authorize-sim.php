<?php
/*
Plugin Name: Authorize.net SIM for WP eCommerce
Plugin URI: https://wpecommerce.org
Description: Authorize.net SIM extends your WP e-Commerce store by enabling the Authorize.net SIM payment gateway.
Version: 1.3
Author: WP eCommerce
Author URI: https://wpecommerce.org
*/

define( 'WPSC_AUTHSIM_VERSION', '1.3' );
define( 'WPEC_AUTHSIM_VERSION', '1.3' );
define( 'WPEC_AUTHSIM_PRODUCT_ID', 637 );
define( 'WPSC_AUTHSIM_MODULE_PRESENT', true );
define( 'WPSC_AUTHSIM_FILE_PATH', dirname( __FILE__ ) . '/' );
define( 'WPSC_AUTHSIM_URL', plugins_url( '/', __FILE__ ) );
//define( 'WPSC_ADD_DEBUG_PAGE', true );

/**
 * WP eCommerce Product Licensing validations
 * Will run the plugin update checker if license is registered
 */
if( is_admin() ) {
	
	// setup the updater
	if( ! class_exists( 'WPEC_Product_Licensing_Updater' ) ) {
		// load our custom updater
		include( dirname( __FILE__ ) . '/WPEC_Product_Licensing_Updater.php' );
	}
	function wpec_authsim_plugin_updater() {
		// retrieve our license key from the DB
		$license = get_option( 'wpec_product_'. WPEC_AUTHSIM_PRODUCT_ID .'_license_active' );
		$key = ! $license ? '' : $license->license_key;
		// setup the updater
		$wpec_updater = new WPEC_Product_Licensing_Updater( 'https://wpecommerce.org', __FILE__, array(
				'version' 	=> WPEC_AUTHSIM_VERSION, 				// current version number
				'license' 	=> $key, 		// license key (used get_option above to retrieve from DB)
				'item_id' 	=> WPEC_AUTHSIM_PRODUCT_ID 	// id of this plugin
			)
		);
	}
	add_action( 'admin_init', 'wpec_authsim_plugin_updater', 0 );
}

function authorize_sim_add_gateway( $nzshpcrt_gateways ) {
	//$authsim_shpcrt_active = get_option( 'authsim_activation_state' );

	//if ( $authsim_shpcrt_active === 'true' ):
	require_once( 'authorize-sim.merchant.php' );
	$num = count( $nzshpcrt_gateways ) + 1;
	$nzshpcrt_gateways[$num] = array(
		'name' => 'Authorize.net SIM',
		'api_version' => 2.0,
		'class_name' => 'wpsc_merchant_authorize_sim',
		'image' => WPSC_URL . '/images/cc.gif',
		'has_recurring_billing' => true,
		'wp_admin_cannot_cancel' => false,
		'requirements' => array(
			/// so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
			'php_version' => 5.0,
			/// for modules that may not be present, like curl
			'extra_modules' => array()
		),
	
		// this may be legacy, not yet decided
		'internalname' => 'wpsc_merchant_authorize_sim',

		// All array members below here are legacy, and use the code in authorize-sim.merchant.php
		'form' => "form_authorize_sim",
		'submit_function' => "submit_authorize_sim",
		'payment_type' => "credit_card",
		'supported_currencies' => array(
			'currency_list' =>  array( 'USD', 'CAD', 'GBP' ),
		),
	);
	//endif;
	return $nzshpcrt_gateways;
}
add_filter( 'wpsc_merchants_modules','authorize_sim_add_gateway' );