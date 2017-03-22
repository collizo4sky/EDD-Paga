<?php

/*
Plugin Name: EDD Paga
Plugin URI: http://w3guy.com
Description: Accept Credit Card payments in your Easy Digital Downloads store via Paga
Version: 1.0
Author: Collins Agbonghama
Author URI: https://w3guy.com
License: GPL2
*/

define( 'EDD_PAGA_ROOT', plugin_dir_path( __FILE__ ) );

class EDD_Paga {

	public function __construct() {

		add_action( 'edd_paga_cc_form', '__return_false' );
		add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'edd_gateway_paga', array( $this, 'process_payment' ) );
		add_action( 'parse_request', array( $this, 'verify_order' ) );
		add_action( 'init', array( $this, 'edd_listen_paga' ) );
		add_filter( 'edd_currencies', array( $this, 'naira_currency' ) );
		add_filter( 'edd_ngn_currency_filter_before', array( $this, 'currency_in_price' ), 10, 3 );
		add_filter( 'edd_format_amount_decimals', array( $this, 'remove_delcimal' ) );

		// phone number field
		add_action( 'edd_purchase_form_user_info', array( $this, 'phone_number_checkout_field' ) );
		add_action( 'edd_checkout_error_checks', array( $this, 'validate_phone_number' ), 10, 2 );
		add_filter( 'edd_payment_meta', array( $this, 'store_phone_number_fields' ) );
		add_action( 'edd_payment_personal_details_list', array( $this, 'display_phone_number_purchase_details' ), 10, 2 );
	}

	public function remove_delcimal( $decimals ) {
		$decimals = 0;

		return $decimals;
	}

	public function naira_currency( $currencies ) {
		$currencies['NGN'] = __( 'Nigerian Naira (&#8358;)', 'edd_paga' );

		return $currencies;
	}

	public function currency_in_price( $formatted, $currency, $price ) {
		if ( 'NGN' == $currency ) {
			$formatted = '&#8358;' . ' ' . $price;
		}

		return $formatted;
	}

	// output our custom field HTML
	public function phone_number_checkout_field() {
		?>
		<p id="edd-paga-phone-wrap">
			<label class="edd-label" for="edd-paga-phone"><?php _e( 'Phone Number', 'edd_paga' ); ?> <span class="edd-required-indicator">*</span></label>
			<span class="edd-description"><?php _e( 'Enter your phone number', 'edd_paga' ); ?></span>
			<input class="edd-input" type="text" name="edd_paga_phone" id="edd-paga-phone" placeholder="<?php _e( 'Phone Number', 'edd_paga' ); ?>" value=""/>
		</p>
	<?php
	}


	/** check for errors in phone number custom field */
	public function validate_phone_number( $valid_data, $data ) {
		if ( empty( $data['edd_paga_phone'] ) ) {
			edd_set_error( 'empty_phone', __( 'Please provide your phone number.', 'edd_paga' ) );
		}
	}


	/** store the custom field data in the payment meta */
	public function store_phone_number_fields( $payment_meta ) {
		$payment_meta['phone'] = isset( $_POST['edd_paga_phone'] ) ? sanitize_text_field( $_POST['edd_paga_phone'] ) : '';

		return $payment_meta;
	}


	/** show the phone number field in the "View Order Details" popup */
	public function display_phone_number_purchase_details( $payment_meta, $user_info ) {
		$phone = isset( $payment_meta['phone'] ) ? $payment_meta['phone'] : 'none';
		?>
		<li><?php echo __( 'Phone:', 'edd_paga' ) . ' ' . $phone; ?></li>
	<?php
	}

	// registers the gateway
	public function register_gateway( $gateways ) {
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
				$firstName    = $purchase_data['user_info']['first_name'];
				$lastName     = $purchase_data['user_info']['last_name'];
				$phoneNumber  = $purchase_data['post_data']['edd_paga_phone'];

				$paga_cart[] = "<input type='hidden' name='customerName' value='$firstName $lastName'>";
				$paga_cart[] = "<input type='hidden' name='phoneNumber' value='$phoneNumber'>";
				$paga_cart[] = "<input type='hidden' name='email' value='" . $purchase_data['user_email'] . "'>";
				$paga_cart[] = "<input type='hidden' name='invoice' value='$payment'>";
				$paga_cart[] = "<input type='hidden' name='return_url' value='$success_page'>";

				if ( edd_is_test_mode() ) {
					$paga_cart[] = "<input type='hidden' name='test' value='true'>";
				}

				foreach ( $purchase_data['cart_details'] as $key => $item ) {
					$item_name   = $item['name'];
					$item_price  = $item['price'];
					$paga_cart[] = "<input type='hidden' name='description[$key]' value='$item_name'>";
					$paga_cart[] = "<input type='hidden' name='subtotal[$key]' value='$item_price'>";
				}

				echo '<div style="text-align:center;margin:auto"><h3>Select your method of payment</h3></div>';
				echo '<form action="post">';
				echo implode( "\r\n", $paga_cart );
				echo '</form>';
				echo '<script type="text/javascript" src="https://www.mypaga.com/paga-web/epay/ePay-start.paga?k=89bfad767-abb7-4147-b407-5cec175daa9e8&amp;e=false&layout=H"></script>';
			}
		} else {
			$fail = true;
		}

		// redirect to payment gateway on failure.
		if ( $fail ) {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}


	public function verify_order() {

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

			if ( ! empty( $fail ) ) {
				edd_set_error( 'payment_error', $fail );
				edd_record_gateway_error( 'Paga Error', "There was an error while processing a Paga payment. Payment error $fail" );
				edd_send_back_to_checkout( '?payment-mode=paga' );
			}
		}

	}


	public function edd_listen_paga() {
		$notification_private_key = '';
		$merchant_key             = '';

		// Paga notification used for completing orders.
		if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'PAGAIPN' ) {
			// log the notification
			$this->log_notification( $_POST );

			$invoice_id   = absint( $_POST['invoice'] );
			$trans_amount = absint( $_POST['amount'] );
			$order_amount = absint( edd_get_payment_amount( $invoice_id ) );

			if ( $_POST['notification_private_key'] == $notification_private_key && $_POST['merchant_key'] == $merchant_key ) {
				if ( $order_amount <= $trans_amount && 'false' == $_POST['test'] ) {
					edd_update_payment_status( $invoice_id, 'publish' );
				}
			}
		}
	}

	public function log_notification( $data ) {
		$date = date_i18n( 'M j, Y @ G:i', current_time( 'timestamp' ) );

		$data     = is_array( $data ) || is_object( $data ) ? json_encode( $data ) : $data;
		$resource = fopen( EDD_PAGA_ROOT . 'notification.log', 'a+' );
		fwrite( $resource, "$date: $data" . "\r\n" );
		fclose( $resource );
	}
}


$instance = new EDD_Paga();


