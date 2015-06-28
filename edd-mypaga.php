<?php

/*
Plugin Name: EDD Paga
Plugin URI: http://w3guy.com
Description: A brief description of the Plugin.
Version: 1.0
Author: collizo4sky
Author URI: http://w3guy.com
License: GPL2
*/

class EDD_Paga {

	public function __construct() {


		add_action( 'edd_paga_cc_form', '__return_false' );
		add_filter( 'edd_payment_gateways', array( $this, 'edd_register_gateway' ) );
		add_action( 'edd_gateway_paga', array( $this, 'process_payment' ) );
		add_action( 'plugins_loaded', array( $this, 'edd_listen_paga' ) );
	}

	// registers the gateway
	public function edd_register_gateway( $gateways ) {
		$gateways['paga'] = array( 'admin_label' => 'Paga', 'checkout_label' => __( 'Paga', 'edd_paga' ) );

		return $gateways;
	}


	public function process_payment( $purchase_data ) {

		$fail = false;
		// check for any stored errors
		$errors = edd_get_errors();

		if ( ! $errors ) {
			// Collect payment data
			$payment_data = array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => edd_get_currency(),
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'gateway'      => 'Paga',
				'status'       => 'pending'
			);


			// Record the pending payment
			$payment = edd_insert_payment( $payment_data );

			if ( ! $payment ) {
				$fail = true;
			} else {

				$paga_cart    = array();
				$success_page = home_url( 'index.php?paga-status=check' );

				$paga_cart[] = "<input type='hidden' name='email' value='" . $purchase_data['user_email'] . "'>";
				$paga_cart[] = "<input type='hidden' name='invoice' value='$payment'>";
				$paga_cart[] = "<input type='hidden' name='return_url' value='$success_page'>";

				if ( edd_is_test_mode() ) {
					$paga_cart[] = "<input type='hidden' name='test' value='true'>";
				}

				foreach ( $purchase_data['cart_details'] as $key => $item ) {
					$item_name   = $item['name'];
					$item_price  = $item['item_price'];
					$paga_cart[] = "<input type='hidden' name='description[$key]' value='$item_name'>";
					$paga_cart[] = "<input type='hidden' name='subtotal[$key]' value='$item_price'>";
				}

				echo '<div style="text-align:center;margin:auto"><h3>Select your method of payment</h3></div>';
				echo '<form method="post" id="submitPagaPayment" action="">';
				echo implode( "\r\n", $paga_cart );
				echo '</form>';
				echo '<script type="text/javascript" src="https://www.mypaga.com/paga-web/epay/ePay-start.paga?k=9bfad767-abb7-4147-b407-5cec175daa9e&amp;e=false&layout=H"></script>';
			}
		} else {
			$fail = true;
		}

		// redirect to payment gateway on failure.
		if ( $fail ) {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}


	public function edd_listen_paga() {
		global $edd_options;
		$notification_private_key = 'pagaepay';
		$merchant_key             = '9bfad767-abb7-4147-b407-5cec175daa9e';

		if ( isset( $_GET['paga-status'] ) && $_GET['paga-status'] == 'check' ) {

				$transaction_status = isset( $_POST['status'] ) ? $_POST['status'] : '';

				switch ( $transaction_status ) {
					case 'SUCCESS':
						edd_empty_cart();
						edd_send_to_success_page();
						break;
					case 'ERROR_TIMEOUT':
						$fail = 'Payment Transaction Timeout. Try again later.';
						break;
					case 'ERROR_INVALID_CUSTOMER_ACCOUNT':
						$fail = 'Invalid Customer Account';
						break;
					case 'ERROR_CANCELLED':
						$fail = 'Transaction was cancelled.';
						break;
					case 'ERROR_BELOW_MINIMUM':
						$fail = 'The order amount is below the minimum allowed. Contact the merchant.';
						break;
					case 'ERROR_ABOVE_MAXINUM':
						$fail = 'The order amount is above the maximum allowed. Contact the merchant.';
						break;
					case 'ERROR_AUTHENTICATION':
						$fail = 'Invalid Login Details';
						break;
					case 'ERROR_OTHER':
						$fail = 'Transaction Failed. Kindly Try again: Other error';
						break;
					default:
						$fail = 'Transaction Failed. Kindly Try again';
						break;
				}

				if(!empty($fail)) {
					edd_set_error( 'payment_error', $fail );
					edd_record_gateway_error( 'Paga Error', "There was an error while processing a Paga payment. Payment error $fail" );
					edd_send_back_to_checkout( '?payment-mode=paga' );
				}
		}

		// Paga notification used for completing orders.
		if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'PAGAIPN' ) {
			if ( $_POST['notification_private_key'] == $notification_private_key && $_POST['merchant_key'] == $merchant_key ) {
				edd_update_payment_status( absint($_POST['transaction_id']), 'publish' );
			}
		}

	}
}


add_action( 'plugins_loaded', 'paga_plugin' );

function paga_plugin() {
	new EDD_Paga();
}