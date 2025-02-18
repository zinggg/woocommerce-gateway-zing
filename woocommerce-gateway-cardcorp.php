<?php

/**
 * Plugin Name: WooCommerce Cardcorp Gateway
 * Plugin URI: https://cardcorp.com
 * Author: Cardcorp
 * Author URI: https://cardcorp.com
 * Description: WooCommerce plugin for accepting payments through Cardcorp.
 * Version: 1.6.1
 * Tested up to: 5.4.2
 * WC requires at least: 3.0
 * WC tested up to: 4.2.2
 */

include_once(dirname(__FILE__) . '/includes/cardcorp_additional.php');
add_action('plugins_loaded', 'init_woocommerce_cardcorp', 0);

// For upgrading customers, update old Zing tokens to Cardcorp on plugin activation
function update_gateway_id_on_activation() {
    global $wpdb;

    // Query to find tokens with gateway ID 'zing'
    $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = 'zing'");

    // If a token with gateway ID 'zing' exists, update it to 'cardcorp'
    if (!empty($results)) {
        $wpdb->update(
            "{$wpdb->prefix}woocommerce_payment_tokens",
            array('gateway_id' => 'cardcorp'),
            array('gateway_id' => 'zing')
        );
    }
}

// Hook the function to the plugin activation hook
register_activation_hook(__FILE__, 'update_gateway_id_on_activation');

/**
 * Init payment gateway
 */

function init_woocommerce_cardcorp()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}
	class woocommerce_cardcorp extends WC_Payment_Gateway
	{

		protected $version = '2.0';

		/**
		 * Construct Main Functions
		 */
		public function __construct()
		{
			global $woocommerce;

			$this->id				= 'cardcorp';
			$this->method_title 	= 'Cardcorp Payments';
			$this->method_description = 'Cardcorp Payments';

			$icon 				= plugins_url('/assets/images/general/cardcorp-dark.svg', __FILE__);
			$icon_html			= $icon_html = $this->get_icon();
			$this->icon			= $icon_html;
			$this->screen 		= plugins_url('screen.png', __FILE__);
			$this->has_fields 	= false;
			$this->init_form_fields(); // Load the form fields.
			$this->init_settings(); // Load the settings.

			/* Define user set variables */
			$this->title			= 'Card Payments';
			$this->description		= $this->settings['description'];
			$this->operation		= $this->settings['operation_mode'];
			$this->supports			= array('refunds');
			$this->basket			= $this->settings['basket'] == 'yes';

			if ($this->operation == 'live') {
				$this->cardcorp_url			= "https://eu-prod.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['access_token'];
				$this->ENTITY_ID		= $this->settings['entity_id'];
			} else {
				$this->cardcorp_url	= "https://eu-test.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['test_access_token'];
				$this->ENTITY_ID		= $this->settings['test_entity_id'];
			}
			$this->paymentType			= 'DB';

			if ($this->settings['card_supported'] !== NULL) {
				$this->cards = implode(' ', $this->settings['card_supported']);
			}

			$this->cards_supported			= $this->get_option('card_supported');
			$this->woocommerce_version 		= $woocommerce->version;
			$this->return_url   			= add_query_arg('wc-api', 'cardcorp_payment', home_url('/'));


			if ($this->settings['dob'] == 'yes' && $this->settings['enabled'] == 'yes') {
				add_action('woocommerce_review_order_before_submit', 'check_client_age_field');
			}

			/* Actions */
			add_action('init', array($this, 'cardcorp_process'));
			add_action('woocommerce_api_cardcorp_payment', array($this, 'cardcorp_process'));
			add_action('woocommerce_receipt_cardcorp', array($this, 'receipt_page'));
			add_action('woocommerce_order_refunded', array($this, 'action_woocommerce_order_refunded'), 10, 2);
			add_action('woocommerce_order_action_cardcorp_capture', array($this, 'capture_payment'));
			add_action('woocommerce_order_action_cardcorp_reverse', array($this, 'reverse_payment'));

			/* add_action to parse values to thankyou*/
			add_action('woocommerce_thankyou', array($this, 'report_payment'));
			//add_action('woocommerce_thankyou', array($this, 'parse_value_cardcorp_success_page'));

			/* add_action to parse values when error */
			add_action('woocommerce_before_checkout_form', array($this, 'parse_value_cardcorp_error'), 10);

			/* Lets check for SSL */
			add_action('admin_notices', array($this, 'do_ssl_check'));
			wp_enqueue_style('cardcorp_style', plugin_dir_url(__FILE__) . 'assets/css/cardcorp-style.css', array(), $this->version);


			$tab = isset($_GET['cardcorp_tab']) ? $_GET['cardcorp_tab'] : null;

			if ($tab !== null) {
				$GLOBALS['hide_save_button'] = true;
			} else {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			}
		}

		public function do_ssl_check()
		{
			if ($this->enabled == "yes") {
				if (get_option('woocommerce_force_ssl_checkout') == "no") { ?>
					<div class="error">
						<p>
							<?= sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=advanced')); ?>

						</p>
					</div>
				<?php } ?>
			<?php }
		}


		/**
		 * Woocommerce Admin Panel Option Manage Cardcorp Settings here.
		 *
		 * @return void
		 */
		public function admin_options()
		{

			$default_tab = null;
			$tab = isset($_GET['cardcorp_tab']) ? $_GET['cardcorp_tab'] : $default_tab; ?>

			<nav class="nav-tab-wrapper">

				<a href="?page=wc-settings&tab=checkout&section=cardcorp" class="nav-tab <?php if ($tab === null) { ?> nav-tab-active <?php } ?>">General Settings</a>

				<a href="?page=wc-settings&tab=checkout&section=cardcorp&cardcorp_tab=requires" class="nav-tab <?php if ($tab === "requires") { ?> nav-tab-active <?php } ?>">Requires</a>

				<a href="?page=wc-settings&tab=checkout&section=cardcorp&cardcorp_tab=logs" class="nav-tab <?php if ($tab === "logs") { ?> nav-tab-active <?php } ?>">Logs</a>

			</nav>

			<div class="tab-content">
				<?php
				switch ($tab):
					case 'requires':
						$this->requiresTab();
						break;
					case 'logs':
						$this->logsTab();
						break;
					default:
						$this->generalSettingsTab();
						break;
				endswitch;
				?>
			</div>
		<?php
		}

		public function generalSettingsTab()
		{
		?>
			<h2>Cardcorp Payment Gateway</h2>
			<p>Cardcorp Configuration Settings</p>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table>
		<?php wc_enqueue_js("jQuery( function( $ ) {
				var cardcorp_test_fields = '#woocommerce_cardcorp_test_entity_id, #woocommerce_cardcorp_test_access_token'; 
				var cardcorp_live_fields = '#woocommerce_cardcorp_entity_id, #woocommerce_cardcorp_access_token'; 
				$( '#woocommerce_cardcorp_operation_mode' ).change(function(){ 
					$( cardcorp_test_fields + ',' + cardcorp_live_fields ).closest( 'tr' ).hide();
					if ( 'live' === $( this ).val() ) { 
						$( '#woocommerce_cardcorp_live_credentials, #woocommerce_cardcorp_live_credentials + p' ).show();
						$( '#woocommerce_cardcorp_test_credentials, #woocommerce_cardcorp_test_credentials + p' ).hide(); 
						$( cardcorp_live_fields ).closest( 'tr' ).show();
					} else { 
						$( '#woocommerce_cardcorp_live_credentials, #woocommerce_cardcorp_live_credentials + p' ).hide();
						$( '#woocommerce_cardcorp_test_credentials, #woocommerce_cardcorp_test_credentials + p' ).show();
						$( cardcorp_test_fields ).closest( 'tr' ).show(); 
					} 
				}).change();
			});");
		}

		public function requiresTab()
		{
			global $wp_version;
		?>

			<h3>Requires</h3>
			<table class="requires-table">
				<tbody>
					<tr>
						<td>PHP</td>
						<td><strong><?= phpversion(); ?></strong></td>
						<td>
							<?php if (phpversion() >= '7.0.0') { ?>
								<span class="dashicons dashicons-yes cardcorp-success-text"></span>
							<?php } else if (phpversion() >= '5.2.0') { ?>
								<span class="dashicons dashicons-info cardcorp-warning-text"></span>
							<?php } else { ?>
								<span class="dashicons dashicons-no cardcorp-error-text"></span>
							<?php } ?>
						</td>
					</tr>
					<tr>
						<td>WordPress</td>
						<td><?= $wp_version; ?></td>
						<td>
							<?php if ($wp_version >= '5.4.0') { ?>
								<span class="dashicons dashicons-yes cardcorp-success-text"></span>
							<?php } else if ($wp_version >= '5.0.0') { ?>
								<span class="dashicons dashicons-info cardcorp-warning-text"></span>
							<?php } else { ?>
								<span class="dashicons dashicons-no cardcorp-error-text"></span>
							<?php } ?>
						</td>
					</tr>
					<tr>
						<td>WooCommerce</td>
						<td><?= WC_VERSION; ?></td>
						<td>
							<?php if (WC_VERSION >= '4.2.2') { ?>
								<span class="dashicons dashicons-yes cardcorp-success-text"></span>
							<?php } else if (WC_VERSION >= '3.0') { ?>
								<span class="dashicons dashicons-info cardcorp-warning-text"></span>
							<?php } else { ?>
								<span class="dashicons dashicons-no cardcorp-error-text"></span>
							<?php } ?>
						</td>
					</tr>
					<tr>
						<td>Force SSL</td>
						<td><?php if (get_option('woocommerce_force_ssl_checkout') == "no") { ?> No <?php } else { ?> Yes <?php } ?></td>
						<td>
							<?php if (get_option('woocommerce_force_ssl_checkout') == "no") { ?>
								<span class="dashicons dashicons-no cardcorp-error-text"></span>
							<?php } else { ?>
								<span class="dashicons dashicons-yes cardcorp-success-text"></span>
							<?php } ?>
						</td>
					</tr>
				</tbody>
			</table>

		<?php
		}

		public function logsTab()
		{
		?>
			<h3>Logs</h3>

			<textarea class="large-text logs_textarea" disabled="" rows="30"><?= get_cardcorp_logs(); ?></textarea>

			<?php
		}

		/**
		 * Initialise Cardcorp Woo Plugin Settings Form Fields
		 *
		 * @return void
		 */
		function init_form_fields()
		{

			$this->form_fields = array(
				'enabled' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable Cardcorp',
					'default' 			=> 'yes'
				),
				'force3ds' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Force 3DS Challenge',
					'default' 			=> 'yes'
				),
				'operation_mode' 	=> array(
					'title' 			=> 'Operation Mode',
					'default' 			=> 'Payments processed by Cardcorp',
					'description' 		=> 'You can switch between different environments, by selecting the corresponding operation mode',
					'type' 				=> 'select',
					'class'				=> 'cardcorp_mode',
					'options' 			=> 	array(
						'test' 				=>  'Test Mode',
						'live' 				=>  'Live Mode',
					)
				),
				'description' 		=> array(
					'title' 			=> 'Description',
					'type' 				=> 'text',
					'description' 		=> 'This controls the description which the user sees during checkout',
					'default' 			=> 'Payments proccessed by Cardcorp',
					'desc_tip'    		=> true
				),
				'test_credentials' 	=> array(
					'title'       		=> 'API Test Credentials',
					'type'        		=> 'title',
					'description' 		=> 'Enter your Cardcorp Test API Credentials to process transactions via Cardcorp. 
										You can get your Cardcorp Test Credentials via 
										<a href="mailto:support@cardcorp.com">Cardcorp Support</a>',
				),
				'test_entity_id' 	=> array(
					'title' 			=> 'Test Entity ID',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your Cardcorp Test Entity ID. This is needed in order to take the payment',
					'default'			=> '',
					'desc_tip'    		=> true
				),
				'test_access_token' => array(
					'title' 			=> 'Test Access Token',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your Cardcorp Test Access Token. This is needed in order to take the payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'live_credentials' 	=> array(
					'title'       		=> 'API LIVE Credentials',
					'type'        		=> 'title',
					'description' 		=> 'Enter your Cardcorp Live API Credentials to process transactions via Cardcorp. You can get your Cardcorp Live Credentials via 
										<a href="mailto:support@cardcorp.com">Cardcorp Support</a>',
				),
				'entity_id' 		=> array(
					'title' 			=> 'Entity ID',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your Cardcorp Entity ID. This is needed in order to the take payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'access_token' 		=> array(
					'title' 			=> 'Access Token',
					'type' 				=> 'text',
					'description' 		=> 'Please enter your Cardcorp Access Token. This is needed in order to take the payment',
					'default' 			=> '',
					'desc_tip'    		=> true
				),
				'hr' 				=> array(
					'title' 			=> '<hr>',
					'type' 				=> 'title',
				),
				'dob' 				=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable Over 18s Date of Birth Field',
					'default' 			=> 'no'
				),
				'basket' 			=> array(
					'title'				=> 'Enable/Disable',
					'type' 				=> 'checkbox',
					'label' 			=> 'Enable Cart/Basket above checkout form.',
					'default' 			=> 'no'
				),

				'card_supported' 	=> array(
					'title' 			=> 'Accepted Cards',
					'default' 			=>  array(
						'VISA',
						'MASTER',
						'MAESTRO',
						'APPLEPAY'
					),
					'css'   			=> 'height: 100%;',
					'type' 				=> 'multiselect',
					'options' 		=> array(
						'VISA' 			=> 'VISA',
						'MASTER' 		=> 'MASTER',
						'MAESTRO' 		=> 'MAESTRO',
						'APPLEPAY' 		=> 'APPLEPAY',
					)
				)
			);
		}

		/**
		 * Custom Credit Card Icons on a checkout page
		 *
		 * @return void
		 */
		public function get_icon()
		{
			$icon_html = $this->cardcorp_get_icon();
			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}

		function cardcorp_get_icon()
		{
			$icon_html = '';

			if (isset($this->cards_supported) && '' !== $this->cards_supported) {

				if (!function_exists('get_plugin_data')) {
					require_once(ABSPATH . 'wp-admin/includes/plugin.php');
				}

				foreach ($this->cards_supported as $card) {
					$icons = plugins_url() . '/' . get_plugin_data(__FILE__)['TextDomain'] . '/assets/images/general/' . strtolower($card) . '.svg';
					$icon_html .= '<img src="' . $icons . '" alt="' . strtolower($card) . '" title="' . strtolower($card) . '" style="height:30px; margin:5px 0px 5px 10px; vertical-align: middle; float: none; display: inline; text-align: right;" />';
				}
			}
			return $icon_html;
		}

		/* Adding Cardcorp Payment Button in checkout page. */
		function payment_fields()
		{
			if ($this->description) echo wpautop(wptexturize($this->description));
		}

		/**
		 * Creating Cardcorp Payment Form.
		 *
		 * @param int $order_id
		 * @return void
		 */
		public function generate_cardcorp_payment_form($order_id)
		{
			global $woocommerce;

			$order = new WC_Order($order_id);

			$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);
			$i = 0;
			$cards = "";
			$duplicates = array();
			foreach ($tokens as $token) {

				if (!in_array($token->get_token(), $duplicates)) {
					$token_get_expiry_year = $token->get_expiry_year();
					$token_get_expiry_month = $token->get_expiry_month();
					$card_expiry_str = $token_get_expiry_year . '-' . $token_get_expiry_month;
					$card_expiry_unix = strtotime($card_expiry_str);
					$expiry_last_day_month_unix = strtotime(date("Y-m-t", $card_expiry_unix));

					$date_now_unix = strtotime(date('Y-m-d'));

					if ($expiry_last_day_month_unix > $date_now_unix) {
						$duplicates[] = $token->get_token();
						$cards .= "&registrations[" . $i . "].id=" . $token->get_token();
						$i++;
					}
				}
			}


			/* Required Order Details */
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$url = $this->cardcorp_url . "/v1/checkouts";
			$customer =  get_current_user_id() != 0 ? get_current_user_id() : $order_id;
			$data = "entityId=" . $this->ENTITY_ID
				. "&currency=" . $currency
				. "&merchantTransactionId=" . $order->get_id() . rand(1000, 9999)
				. "&amount=" . $order->get_total()
				. "&billing.city=" . $order->get_billing_city()
				. "&billing.country=" . $order->get_billing_country()
				. "&billing.street1=" . $order->get_billing_address_1() . ' ' . $order->get_billing_address_2()
				. "&billing.postcode=" . $order->get_billing_postcode()
				. "&customer.email=" . $order->get_billing_email()
				. "&customer.phone=" . $order->get_billing_phone()
				. "&customer.givenName=" . $order->get_billing_first_name()
				. "&customer.surname=" . $order->get_billing_last_name()
				. "&customer.merchantCustomerId=" . $customer
				. "&integrity=true";

			if($this->settings['force3ds'] == 'yes'){
				$data .= "&threeDSecure.challengeIndicator=04"; 
			};

			if ($this->operation == 'test'){
				$data .= "&testMode=EXTERNAL"; 
			};

			if (($this->operation == 'test') && $this->settings['force3ds'] == 'yes'){
				$data .= "&customParameters[3DS2_enrolled]=true"; 
				$data .= "&customParameters[3DS2_flow]=challenge"; 
			};

			$data .= "&paymentType=" . $this->paymentType
				. "&shipping.city=" . $order->get_shipping_city()
				. "&shipping.country=" . $order->get_shipping_country()
				. "&shipping.street1=" . $order->get_shipping_address_1()
				. "&shipping.postcode=" . $order->get_shipping_postcode()
				. $cards;



			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 200,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				$status = json_decode($gtwresponse['body']);
				if (isset($status->id)) { ?>

					<?php if ($this->basket) {
						echo do_shortcode('[woocommerce_cart]');
					?>
						<ul style="display:block" class="order_details">
							<li class="order">
								<?php esc_html_e('Order number:', 'woocommerce'); ?>
								<strong><?php echo esc_html($order->get_order_number()); ?></strong>
							</li>
							<li class="date">
								<?php esc_html_e('Date:', 'woocommerce'); ?>
								<strong><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></strong>
							</li>
							<li class="total">
								<?php esc_html_e('Total:', 'woocommerce'); ?>
								<strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
							</li>
							<?php if ($order->get_payment_method_title()) : ?>
								<li class="method">
									<?php esc_html_e('Payment method:', 'woocommerce'); ?>
									<strong><?php echo wp_kses_post($order->get_payment_method_title()); ?></strong>
								</li>
							<?php endif; ?>
						</ul>
						<style>
							#ajax-loading-screen,
							.woocommerce-cart-form .actions,
							.product-remove {
								display: none !important;
							}

							.woocommerce-cart-form {
								margin-bottom: 1.5rem;
							}

							.order_details {
								display: none;
							}
						</style>
						<script>
							$ = jQuery;
							$.each($(".product-quantity select"), function() {
								$(this).replaceWith('x ' + $(this).val())
							});
						</script>
					<?php } else { ?>
						<style>
							#ajax-loading-screen,
							.order_details {
								display: none;
							}
						</style>
					<?php } ?>



				<?php

					if (!function_exists('get_plugin_data')) {
						require_once(ABSPATH . 'wp-admin/includes/plugin.php');
					}

					// ICON
					// <div id=\"d3\"><img border=\"0\" src=\"' . plugins_url() . '/'. get_plugin_data( __FILE__ )['TextDomain'] .'/assets/images/general/cardcorp-dark.svg\" alt=\"Secure Payment\"></div>
					$lang = strtolower(substr(get_bloginfo('language'), 0, 2));
                    echo '<script src="' . $this->cardcorp_url . '/v1/paymentWidgets.js?checkoutId=' . $status->id . '" integrity="' . $status->integrity . '" crossorigin="anonymous"></script>';
					echo '<script src="https://code.jquery.com/jquery-2.1.4.min.js" type="text/javascript"></script>';
					echo '<script type="text/javascript">
						var wpwlOptions = { 
						style: "plain",' . PHP_EOL;
					echo 'locale: "' . $lang . '",' . PHP_EOL;
					echo 'showCVVHint: true,
						brandDetection: true,
						showPlaceholders: true,
						autofocus : "card.number",
						showLabels: false,
						registrations: {
							requireCvv: true
						},
						labels: {
							givenName: "Firstname", 
							surname: "Lastname",
							cardHolder: "Cardholder",
						},
						errorMessages: {
							givenNameError: "Invalid Firstname",
							surNameError: "Invalid Lastname",
						},
						onReady: function(e) { 
							$(".wpwl-form-card").find(".wpwl-button-pay").on("click", function(e){
								validateHolder(e);
							  });

							$(".wpwl-wrapper-registration-holder").prepend("<span>Card Holder:</span> ");
							$(".wpwl-wrapper-registration-expiry").prepend("<span>Expiry:</span> ");
							$(".wpwl-wrapper-registration-number").prepend("<span>Last 4 Digits:</span> ");
							$(".wpwl-group-cvv").after( $(".wpwl-group-cardHolder").detach()); 

							$("button[data-action=show-initial-forms]").html("Use Another Card"); 
							
						    $(".wpwl-group-cardNumber").after( $(".wpwl-group-cardHolder").detach());
							var visa = $(".wpwl-brand:first").clone().removeAttr("class").attr("class", "wpwl-brand-card wpwl-brand-custom wpwl-brand-VISA");
							var master = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MASTER");
							var maestro = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MAESTRO");
							var amex = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-AMEX");
							var diners = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-DINERS");
							var jcb = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-JCB");
							var createRegistrationHtml = "<div class=\"customLabel\">Store payment details?</div><div class=\"customInput\"><input type=\"checkbox\" name=\"createRegistration\" value=\"true\" /></div>";
							$("form.wpwl-form-card").find(".wpwl-button").before(createRegistrationHtml);
							$(".wpwl-brand:first")';
					if (strpos($this->cards, 'VISA') !== false && $i == 0) {
						echo '.after($(visa))';
					}
					if (strpos($this->cards, 'MASTER') !== false && $i == 0) {
						echo '.after($(master))';
					}
					if (strpos($this->cards, 'MAESTRO') !== false && $i == 0) {
						echo '.after($(maestro))';
					}
					if (strpos($this->cards, 'AMEX') !== false && $i == 0) {
						echo '.after($(amex))';
					}
					if (strpos($this->cards, 'DINERS') !== false && $i == 0) {
						echo '.after($(diners))';
					}
					if (strpos($this->cards, 'JCB') !== false && $i == 0) {
						echo '.after($(jcb))';
					}
					echo ';' . PHP_EOL;
					echo '},
						onBeforeSubmitCard: function(e){
							if(!e.target.classList.contains("wpwl-apple-pay-button")){
								return validateHolder(e);
							}
						},
						onChangeBrand: function(e){
							$(".wpwl-brand-custom").css("opacity", "0.2");
							$(".wpwl-brand-" + e).css("opacity", "5"); 
						}
					}
					function validateHolder(e){
						var holder = $(".wpwl-control-cardHolder").val();
						if (holder.trim().length < 2){
						  $(".wpwl-control-cardHolder").addClass("wpwl-has-error").after("<div class=\"wpwl-hint wpwl-hint-cardHolderError\">Invalid card holder</div>");
						  return false;
						}
						return true;
					  }
					</script>';
					if ($this->operation == 'test') echo '<div class="testmode">' . 'This is the TEST MODE. No money will be charged' . '</div>';
					echo '<div id="cardcorp_payment_container">';
					echo '<form action="' . $this->return_url . '" class="paymentWidgets" data-brands="' . $this->cards . '"></form>';
					echo '</div>';
					echo '<div style="text-align: center; margin-top: 10px; max-width: 200px; margin-left: auto; margin-right: auto;">';
					echo '<a href="https://cardcorp.com" target="_blank">';
					echo '<img src="' . plugins_url() . '/' . get_plugin_data(__FILE__)['TextDomain'] . '/assets/images/general/cardcorp-dark.svg" width="100px">';
					echo '</a>';
					echo '</div>';
				} else {
					if (isset(json_decode($gtwresponse['body'])->result->parameterErrors[0]) && !empty(json_decode($gtwresponse['body'])->result->parameterErrors[0])) {
						$ee = json_decode($gtwresponse['body'])->result->parameterErrors[0];
						$order->add_order_note(sprintf('Cardcorp Configuration error: %s', 'Field: ' . $ee->name . ', Value: ' . $ee->value . ', Error:' . $ee->message));
						cardcorp_write_log(sprintf('Cardcorp Configuration error: %s', 'Field: ' . $ee->name . ', Value: ' . $ee->value . ', Error:' . $ee->message));
					} else {
						$order->add_order_note(sprintf('Cardcorp Configuration error: %s', $gtwresponse['body']));
						cardcorp_write_log(sprintf('Cardcorp Configuration error: %s', $gtwresponse['body']));
					}
					wc_add_notice('Configuration error', 'error');
					wp_safe_redirect(wc_get_page_permalink('cart'));
				}
			}
		}

		/**
		 * Updating the Payment Status and redirect to Success/Fail Page
		 *
		 * @return void
		 */
		public function cardcorp_process()
		{
			global $woocommerce;
			global $wpdb;
			if (isset($_GET['resourcePath'])) {
				$url = $this->cardcorp_url . $_GET['resourcePath'];
				$url .= "?entityId=" . $this->ENTITY_ID;
				$head_data = "Bearer " . $this->ACCESS_TOKEN;
				$gtwresponse = wp_remote_post(
					$url,
					array(
						'method' => 'GET',
						'headers' => array('Authorization' => $head_data),
						'sslverify' => true,
						'timeout'	=> 45
					)
				);

				if (!is_wp_error($gtwresponse)) {
					$status = json_decode($gtwresponse['body']);
					$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
					$order = new WC_Order(substr($status->merchantTransactionId, 0, -4));
					if (in_array($status->result->code, $success_code)) {


						if (isset($status->registrationId) && !empty($status->registrationId)) {
							$tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $this->id);
							$cards = array();
							foreach ($tokens as $token) {
								$cards[] = $token->get_token();
							}
							if (!in_array($status->registrationId, $cards)) {
								$token = new WC_Payment_Token_CC();
								$token->set_token($status->registrationId); // Token comes from payment processor
								$token->set_gateway_id($this->id);
								$token->set_last4($status->card->last4Digits);
								$token->set_expiry_year($status->card->expiryYear);
								$token->set_expiry_month($status->card->expiryMonth);
								$token->set_card_type($status->paymentBrand);
								$token->set_user_id(get_current_user_id());
								$token->save();
								WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
							}
						}


						$order->add_order_note(sprintf('Cardcorp Transaction Successful. The Transaction ID was %s and Payment Status %s. 
						Payment type was %s. Authorisation bank code: %s', $status->id, $status->result->description, $status->paymentType, $status->resultDetails->ConnectorTxID3));
						cardcorp_write_log(sprintf('Cardcorp Transaction Successful. The Transaction ID was %s and Payment Status %s. 
						Payment type was %s. Authorisation bank code: %s', $status->id, $status->result->description, $status->paymentType, $status->resultDetails->ConnectorTxID3));

						$message = sprintf('Transaction Successful. The status message <b>%s</b>', $status->result->description);
						$bank_code = $status->resultDetails;
						$astrxId = $status->id;

						$order->payment_complete($status->id);
						update_post_meta($order->id, 'bank_code', $bank_code);
						update_post_meta($order->id, 'AS-TrxId', $astrxId);
						WC()->cart->empty_cart();

						/* Add content to the WC emails. */
						$query = parse_url($this->get_return_url($order), PHP_URL_QUERY);
						if ($query) {
							$url = $this->get_return_url($order) . '&astrxId=' . $astrxId;
						} else {
							$url = $this->get_return_url($order) . '?astrxId=' . $astrxId;
						}

						wp_redirect($url);

						if (in_array($status->paymentType, array('DB'))) {
							//$order->update_status('wc-accepted');
							if (version_compare(WOOCOMMERCE_VERSION, "2.6") <= 0) {
								$order->reduce_order_stock();
							} else {
								//wc_reduce_stock_levels($orderid);
							}
						} else {
							$order->update_status('wc-preauth');
						}
						exit;
					} else {
						include_once(dirname(__FILE__) . '/includes/error_list.php');
						$resp_code = $status->result->code;
						$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] : $status->result->description;
						cardcorp_write_log($resp_code_translated);
						$order->add_order_note(sprintf('Cardcorp Transaction Failed. The Transaction Status %s', $status->result->description));
						cardcorp_write_log(sprintf('Cardcorp Transaction Failed. The Transaction Status %s', $status->result->description));
						// $declinemessage = sprintf('Transaction Unsuccessful. The status message <b>%s</b>', $resp_code_translated ) ;
						// wc_add_notice( $declinemessage, 'error' );
						$astrxId = $status->id;
						$query = parse_url(wc_get_checkout_url($order), PHP_URL_QUERY);
						if ($query) {
							$url = wc_get_checkout_url($order) . '&astrxId=' . $astrxId;
						} else {
							$url = wc_get_checkout_url($order) . '?astrxId=' . $astrxId;
						}
						wp_redirect($url);
						exit;
					}
				}
			}
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int$order_id
		 * @return array
		 */
		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);
			if ($this->woocommerce_version >= 2.1) {
				$redirect = $order->get_checkout_payment_url(true);
			} else {
				$redirect = add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))));
			}
			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);
		}

		/**
		 * Capture the payment and return the result
		 *
		 * @param int $order_id
		 * @return void
		 */
		function capture_payment($order_id)
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->capture_request($order_trx_id_cardcorp, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if (in_array($response->result->code, $success_code)) {
				$order->add_order_note(sprintf('Cardcorp Capture Processed Successful. The Capture ID was %s and Request Status => %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Cardcorp Capture Processed Successful. The Capture ID was %s and Request Status => %s', $response->id, $response->result->description));
				$order->update_status('wc-accepted');
				return true;
			} else {
				$order->add_order_note(sprintf('Cardcorp Capture Request Failed. The Capture Status => %s. Code is == %s', $response->result->description, $response->result->code));
				cardcorp_write_log(sprintf('Cardcorp Capture Request Failed. The Capture Status => %s. Code is == %s', $response->result->description, $response->result->code));
				return false;
			}
			return false;
		}

		/**
		 * Capture Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return string
		 */
		function capture_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=DB";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			echo 'Error in communication';
		}

		/**
		 * Reverse the payment and return the result
		 *
		 * @param int $order_id
		 * @return bool
		 */
		function reverse_payment($order_id)
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->reverse_request($order_trx_id_cardcorp, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if (in_array($response->result->code, $success_code)) {
				$order->add_order_note(sprintf('Cardcorp Reversal Processed Successful. The Reversal ID was: %s and Request Status: %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Cardcorp Reversal Processed Successful. The Reversal ID was: %s and Request Status: %s', $response->id, $response->result->description));
				$order->update_status('wc-reversed');
				return true;
			} else {
				$order->add_order_note(sprintf('Cardcorp Reversal Request Failed. The Reversal Status: %s. Code is: %s', $response->result->description, $response->result->code));
				cardcorp_write_log(sprintf('Cardcorp Reversal Request Failed. The Reversal Status: %s. Code is: %s', $response->result->description, $response->result->code));
				return false;
			}
			return false;
		}

		/**
		 * Reverse Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return void
		 */
		function reverse_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=RV";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			echo 'Error in communication';
		}

		/**
		 * Receipt Page
		 *
		 * @param int $order
		 * @return void
		 */
		function receipt_page($order)
		{
			$this->generate_cardcorp_payment_form($order);
		}

		/**
		 * Refund the payment and return the result
		 *
		 * @param int $order_id
		 * @param NULL $amount
		 * @param string $reason
		 * @return void
		 */
		function process_refund($order_id, $amount = null, $reason = '')
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			$order_data = $order->get_data();
			$order_trx_id_cardcorp = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->refund_request($order_trx_id_cardcorp, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if (in_array($response->result->code, $success_code)) {
				$order->add_order_note(sprintf('Cardcorp Refund Processed Successful. The Refund ID: %s and Request Status: %s', $response->id, $response->result->description));
				cardcorp_write_log(sprintf('Cardcorp Refund Processed Successful. The Refund ID: %s and Request Status: %s', $response->id, $response->result->description));
				$order->update_status('wc-refunded');
				return true;
			} else {
				$order->add_order_note(sprintf('Cardcorp Refund Request Failed. The Refund Status: %s', $response->result->description));
				cardcorp_write_log(sprintf('Cardcorp Refund Request Failed. The Refund Status: %s', $response->result->description));
				return false;
			}
			return false;
		}

		/**
		 * Refund Request
		 *
		 * @param string $order_trx_id_cardcorp
		 * @param string $amount
		 * @param string $currency
		 * @return void
		 */
		function refund_request($order_trx_id_cardcorp, $amount, $currency)
		{
			$url = $this->cardcorp_url . "/v1/payments/" . $order_trx_id_cardcorp;
			$data = "entityId=" . $this->ENTITY_ID .
				"&amount=" . $amount .
				"&currency=" . $currency .
				"&paymentType=RF";
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
			echo 'Error in communication';
		}


		/**
		 * Gateway transaction details on declined trx
		 *
		 * @param int $order_id
		 * @return void
		 */
		function parse_value_cardcorp_error($order_id)
		{
			if (isset($_REQUEST['astrxId'])) {
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
				include_once(dirname(__FILE__) . '/includes/error_list.php');
				$resp_code = $gwresponse->result->code;
				$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] : $gwresponse->result->description;
				cardcorp_write_log($resp_code_translated); ?>

				<div class="woocommerce">
					<ul class="woocommerce-error" role="alert">
						<li><?= sprintf('Transaction Unsuccessful. The status message <b>%s</b>', $resp_code_translated); ?> * </li>
					</ul>
				</div>
			<?php }
		}

		/**
		 * Gateway transaction details on a thankyou page
		 *
		 * @param int $order_id
		 * @return void
		 */
		function parse_value_cardcorp_success_page($order_id)
		{
			if (isset($_REQUEST['astrxId'])) {
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
			?>
				<div class="woocommerce-order">
					<h2>Transaction details</h2>
					<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details tst">
						<li class="woocommerce-order-overview__email email">Transaction Codes

							<?php if (isset($gwresponse->resultDetails->ConnectorTxID1)) { ?>
								<strong><?= $gwresponse->resultDetails->ConnectorTxID1; ?></strong>
							<?php } ?>

							<?php if (isset($gwresponse->resultDetails->ConnectorTxID2)) { ?>
								<strong><?= $gwresponse->resultDetails->ConnectorTxID2; ?></strong>
							<?php } ?>

							<?php if (isset($gwresponse->resultDetails->ConnectorTxID3)) { ?>
								<strong><?= $gwresponse->resultDetails->ConnectorTxID3; ?></strong>
							<?php } ?>

						</li>
						<li class="woocommerce-order-overview__email email">Card Type
							<strong><?= $gwresponse->paymentBrand; ?> *** <?= $gwresponse->card->last4Digits; ?></strong>
						</li>
						<li class="woocommerce-order-overview__email email">Payment Type
							<strong><?= $gwresponse->paymentType; ?></strong>
						</li>
						<li class="woocommerce-order-overview__email email">Transaction Time
							<strong><?= $gwresponse->timestamp; ?></strong>
						</li>
					</ul>
				</div>
<?php }
		}


		/**
		 * Report Payment to Cardcorp
		 *
		 * @param int $order_id
		 * @return void
		 */
		function report_payment($order_id)
		{
			$url = $this->cardcorp_url . "/v1/query/";
			$url .= $_REQUEST['astrxId'];
			$url .= "?entityId=" . $this->ENTITY_ID;
			$head_data = "Bearer " . $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'method' => 'GET',
					'headers' => array('Authorization' => $head_data),
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if (!is_wp_error($gtwresponse)) {
				return $gtwresponse['body'];
			}
		}
	}

	/**
	 * Add the Cardcorp gateway to WooCommerce
	 *
	 * @param array $methods
	 * @return void
	 */
	function add_cardcorp_gateway($methods)
	{
		$methods[] = 'woocommerce_cardcorp';
		return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'add_cardcorp_gateway');
}
