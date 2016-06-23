<?php

class wpsc_merchant_authorize_sim extends wpsc_merchant {
  	public $name = 'Authorize.net SIM';
	private $sim_url;
 	private $sim_relay_values = array();
	
	public function __construct( $purchase_id = null, $is_receiving = false ) {
		$this->sim_url = self::getSIMURL();
		
		require_once( WPSC_AUTHSIM_FILE_PATH . 'anet_php_sdk/AuthorizeNet.php' ); 
	  	parent::__construct( $purchase_id, $is_receiving );
	}

	public static function getSIMURL() {
		if ( get_option( 'wpec_auth_sim_account_type' ) == 'live' )		
			return 'https://secure.authorize.net/gateway/transact.dll';
		
		return 'https://test.authorize.net/gateway/transact.dll';
	}

	/**
	 * construct_value_array gateway specific data array, extended in merchant files
	 * @abstract
	 */
	public function construct_value_array() {		
		// a sequence number is randomly generated
		$sequence = rand( 1, 1000 );
		// a timestamp is generated
		$timeStamp = time();

		$amount = $this->cart_data['total_price'];

		$fingerprint = $this->get_fingerprint( $sequence, $timeStamp, $amount );

		$collected_data = $_POST['collected_data'];
		
		$this->collected_gateway_data = array(
			'x_login' => get_option( 'wpec_auth_sim_login' ),
			'x_amount' => $amount,
			'x_description' => __( 'Your Shopping Cart', 'wpsc' ),
			'x_invoice_num' => $this->cart_data['session_id'],
			'x_fp_sequence' => $sequence,
			'x_fp_timestamp' => $timeStamp,
			'x_fp_hash' => $fingerprint,
			'x_test_request' => 'false', //( get_option( 'wpec_auth_sim_testmode' ) ? 'true' : 'false' ),
			'x_show_form' => 'PAYMENT_FORM',
			'x_email' => $collected_data[get_option( 'wpec_auth_sim_form_email' )],
			'x_first_name' => $collected_data[get_option( 'wpec_auth_sim_form_first_name' )],
			'x_last_name' => $collected_data[get_option( 'wpec_auth_sim_form_last_name' )],
			'x_address' => $collected_data[get_option( 'wpec_auth_sim_form_address' )],
			'x_city' => $collected_data[get_option( 'wpec_auth_sim_form_city' )],
			'x_zip' => $collected_data[get_option( 'wpec_auth_sim_form_post_code' )],
			'x_phone' => $collected_data[get_option( 'wpec_auth_sim_form_phone' )],
			'x_cancel_url' => $this->cart_data['transaction_results_url'],
		);

		$country_index = get_option( 'wpec_auth_sim_form_country' );
		if ( $country_index !== FALSE && ! empty( $collected_data[$country_index] ) ) {
			$this->collected_gateway_data['x_country'] = $collected_data[$country_index][0];
			$this->collected_gateway_data['x_state'] = wpsc_get_state_by_id( $collected_data[$country_index][1], 'code' );
		}

		//relay response (similar to IPN)
		if ( get_option( 'wpec_auth_sim_relay_response' ) ) {
			$this->collected_gateway_data['x_relay_response'] = 'TRUE';
			$relay_url = $this->cart_data['notification_url'];
			$relay_url = add_query_arg( 'gateway', __CLASS__, $relay_url );
			$relay_url = apply_filters( 'wpec_authorize_sim_relay_url', $relay_url );
			$this->collected_gateway_data['x_relay_url'] = $relay_url;
		}
		
		//WPEC standard shipping/tax stuff
		$free_shipping = false;
		$coupon = wpsc_get_customer_meta( 'coupon' );
		if ( $coupon ) {
			$coupon = new wpsc_coupons( $coupon );
			$free_shipping = $coupon->is_percentage == '2';
		}

		if ( $this->cart_data['has_discounts'] && $free_shipping )
			$this->collected_gateway_data['x_freight'] = 0;
		else
			$this->collected_gateway_data['x_freight'] = $this->cart_data['base_shipping'];

		if ( ! wpsc_tax_isincluded() ) {
			$this->collected_gateway_data['x_tax'] = $this->cart_data['cart_tax'];
		}
		//end WPEC standard shipping/tax stuff

		return true;
	}

	private function get_fingerprint( $sequence, $timeStamp, $amount ) {
		$loginID = get_option( 'wpec_auth_sim_login' );
		$transactionKey = get_option( 'wpec_auth_sim_key' );

		if ( phpversion() >= '5.1.2' )
			return hash_hmac( 'md5', $loginID . '^' . $sequence . '^' . $timeStamp . '^' . $amount . '^', $transactionKey );

		return bin2hex( mhash( MHASH_MD5, $loginID . '^' . $sequence . '^' . $timeStamp . '^' . $amount . '^', $transactionKey ) );
	}
	
	/**
	 * submit to gateway, extended in merchant files
	 * @abstract
	 */
	public function submit() {		
		$name_value_pairs = array();
		foreach ( $this->collected_gateway_data as $key => $value ) {
			$name_value_pairs[] = $key . '=' . urlencode( $value );
		}
		$gateway_values =  implode( '&', $name_value_pairs );

		$redirect = $this->sim_url . '?' . $gateway_values;
		// URLs up to 2083 characters long are short enough for an HTTP GET in all browsers.
		// Longer URLs require us to build a seperate form and POST it
		if ( strlen( $redirect ) > 2083 ) {
			//take over the shown page
			wp_register_script( 'wpec_auth_sim_post', WPSC_AUTHSIM_URL . 'js/post_submit.js', array( 'jquery' ) );
			wp_enqueue_script( 'wpec_auth_sim_post' );
			$_SESSION['sim-checkout'] = $this->collected_gateway_data;
			add_filter( 'wpsc_path_wpsc-shopping_cart_page.php', array( $this, 'filter_cart_page' ) );
			return;
		}

		if ( defined( 'WPSC_ADD_DEBUG_PAGE' ) && WPSC_ADD_DEBUG_PAGE ) {
			echo '<a href="'.esc_url( $redirect ).'">Test the URL here</a>';
			echo '<pre>'.print_r( $this->collected_gateway_data, true ).'</pre>';
			exit();
		} else {
			wp_redirect( $redirect );
			exit();
		}
	}

	public function filter_cart_page( $file_path ) {
		return WPSC_AUTHSIM_FILE_PATH . 'sim_post_page.php';
	}
	
	/**
	 * parse gateway notification, recieves and converts the notification to an array, if possible, extended in merchant files
	 * @abstract
	 */
	public function parse_gateway_notification() {
		$this->sim_relay_values = stripslashes_deep( $_POST );
		$this->session_id = $this->sim_relay_values['x_invoice_num'];
	}

	/**
	 * process gateway notification, checks and decides what to do with the data from the gateway, extended in merchant files
	 * @abstract
	 */
	public function process_gateway_notification() {
		/**
		 * convert authorize.net response codes to WPEC ones
		 * @see http://developer.authorize.net/guides/SIM/wwhelp/wwhimpl/js/html/wwhelp.htm#href=SIM_Trans_response.html#1062778
		 */
		$status = false;
		switch ( $this->sim_relay_values['x_response_code'] ) {
			case 1: //Approved
				$status = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
				break;
			case 2: //Declined
				$status = WPSC_Purchase_Log::PAYMENT_DECLINED;
				break;
			case 3: //Error
				$status = WPSC_Purchase_Log::INCOMPLETE_SALE;
				break;
			case 4: //Held For Review
				$status = WPSC_Purchase_Log::ORDER_RECEIVED;
				break;
		}

		do_action( 'wpec_authorize_sim_relay_response', $this->sim_relay_values, $this );

		//@TODO do hash check here
		
		if ( $status )
			$this->set_transaction_details( $this->sim_relay_values['x_trans_id'], $status );

		if ( in_array( $status, array( WPSC_Purchase_Log::ACCEPTED_PAYMENT, WPSC_Purchase_Log::ORDER_RECEIVED ) ) )
			transaction_results( $this->cart_data['session_id'], false );	

		$status = $this->sim_relay_values['x_response_reason_text'];
		$redirect = add_query_arg( 'sessionid', $this->cart_data['session_id'], $this->cart_data['transaction_results_url'] );
		
		//kind of ghetto but should work
		echo '
<html xmlns="http://www.w3.org/1999/xhtml">    
  <head>      
    <title>'.$status.'</title>      
    <meta http-equiv="refresh" content="0;URL=\''.$redirect.'\'" />
  </head>    
  <body> 
    <p>'.$status.' <a href="'.$redirect.'">Click here if not redirected.</a></p> 
  </body>  
</html>
		';
	}

}

function form_authorize_sim(){
	$account_type_options = array(
		'live' => __( 'Merchant (LIVE)', 'wpsc' ),
		'test' => __( 'Developer (TEST)', 'wpsc' ),
	);
	$account_type_option_html = '';
	$account_type = get_option( 'wpec_auth_sim_account_type' );
	
	foreach ( $account_type_options as $key => $value ) {
		$selected = $key == $account_type ? ' selected="selected"' : '';
		$account_type_option_html .= "<option value='{$key}'{$selected}>{$value}</option>\n";
	}
	
	$output = "
     <tr>
      <td>
      " . __( 'Account Type', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[account_type]'>
      {$account_type_option_html}
      </select>
      </td>
  </tr>
	<tr>
	  <td>
		" . __( 'API Login ID', 'wpsc' ) . "
	  </td>
	  <td>
		<input type='text' name='wpec_auth_sim[login]' value='".get_option( 'wpec_auth_sim_login' )."'>
	  </td>
		</tr>
		<tr>
		  <td>
			" . __( 'Transaction Key', 'wpsc' ) . "
		  </td>
		  <td>
			<input type='text' name='wpec_auth_sim[key]' value='".get_option( 'wpec_auth_sim_key' )."'>
		  </td>
		</tr>
		<tr>
		  <td>
			" . __( 'Test Mode', 'wpsc' ) . "
		  </td>
		  <td>
			<input type='checkbox' name='wpec_auth_sim[testmode]' value='true'". ( get_option( 'wpec_auth_sim_testmode' ) ? " checked='checked'" : '' ) .">
		  </td>
		</tr>
		<tr>
		  <td>
			" . __( 'Relay Response (similar to IPN)', 'wpsc' ) . "
		  </td>
		  <td>
			<input type='checkbox' name='wpec_auth_sim[relay_response]' value='true'". ( get_option( 'wpec_auth_sim_relay_response' ) ? " checked='checked'" : '' ) .">
		  </td>
		</tr>
	<tr class='firstrowth'>
		<td style='border-bottom: medium none;' colspan='2'>
			<strong class='form_group'>" . __( 'Forms Sent to Gateway', 'wpsc' ) . "</strong>
		</td>
	</tr>

     <tr>
      <td>
      " . __( 'Email Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][email]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_email' ) )."
      </select>
      </td>
  </tr>
   <tr>
      <td>
      " . __( 'First Name Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][first_name]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_first_name' ) )."
      </select>
      </td>
  </tr>
    <tr>
      <td>
      " . __( 'Last Name Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][last_name]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_last_name' ) )."
      </select>
      </td>
  </tr>
    <tr>
      <td>
      " . __( 'Address Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][address]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_address' ) )."
      </select>
      </td>
  </tr>
  <tr>
      <td>
      " . __( 'City Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][city]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_city' ) )."
      </select>
      </td>
  </tr>
  <tr>
      <td>
      " . __( 'State Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][state]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_state' ) )."
      </select>
      </td>
  </tr>
  <tr>
      <td>
      " . __( 'Postal / ZIP Code Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][post_code]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_post_code' ) )."
      </select>
      </td>
  </tr>
  <tr>
      <td>
      " . __( 'Country Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][country]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_country' ) )."
      </select>
      </td>
  </tr>
  <tr>
      <td>
      " . __( 'Phone Field', 'wpsc' ) . "
      </td>
      <td>
      <select name='wpec_auth_sim[form][phone]'>
      ".nzshpcrt_form_field_list( get_option( 'wpec_auth_sim_form_phone' ) )."
      </select>
      </td>
  </tr>
";
				
	return $output;
}

function submit_authorize_sim() {
	if( ! empty( $_REQUEST['wpec_auth_sim']) ){
		//save form values
		if ( ! empty( $_REQUEST['wpec_auth_sim']['form'] ) ) {
			foreach( $_REQUEST['wpec_auth_sim']['form'] as $form => $id ) {
				update_option( "wpec_auth_sim_form_{$form}", $id );
			}
			unset( $_REQUEST['wpec_auth_sim']['form'] );
		}

		//save checkboxes
		if ( empty( $_REQUEST['wpec_auth_sim']['testmode'] ) ) {
			update_option( 'wpec_auth_sim_testmode', false );
		} else {
			update_option( 'wpec_auth_sim_testmode', true );
			unset( $_REQUEST['wpec_auth_sim']['testmode'] );
		}
		if ( empty( $_REQUEST['wpec_auth_sim']['relay_response'] ) ) {
			update_option( 'wpec_auth_sim_relay_response', false );
		} else {
			update_option( 'wpec_auth_sim_relay_response', true );
			unset( $_REQUEST['wpec_auth_sim']['relay_response'] );
		}

		//save the rest
		foreach( $_REQUEST['wpec_auth_sim'] as $key => $value ) {
			update_option( "wpec_auth_sim_{$key}", $value );
		}
	}
	return true;
}
