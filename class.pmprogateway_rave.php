<?php

/**
 *
 * @link              https://rave.flutterwave.com/
 * @since             1.0.0
 * @package           Rave_Flutterwave_PMPro
 *
 * @wordpress-plugin
 * Plugin Name:       Rave Flutterwave for Paid Membership Pro
 * Plugin URI:        https://rave.flutterwave.com/
 * Description:       Rave payment gateway for Paid Membership Pro. Supports NGN, KES, GHS and USD 
 * Version:           1.0.0
 * Author:            Oluwole Adebiyi (King Flamez)
 * Author URI:        https://github.com/kingflamez
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

define('Rave_Flutterwave_PMPro', '1.0.0');

defined('ABSPATH') or die('No script kiddies please!');
if (!function_exists('KKD_rave_pmp_gateway_load')) {
  add_action('plugins_loaded', 'KKD_rave_pmp_gateway_load', 20);

  DEFINE('KKD_RAVEPMP', "rave-paidmembershipspro");

  function KKD_rave_pmp_gateway_load()
  {
		// paid memberships pro required
    if (!class_exists('PMProGateway')) {
      return;
    }

		// load classes init method
    add_action('init', array('PMProGateway_rave', 'init'));

		// plugin links
    add_filter('plugin_action_links', array('PMProGateway_rave', 'plugin_action_links'), 10, 2);

    if (!class_exists('PMProGateway_rave')) {
      /**
       * PMProGateway_rave Class
       *
       * Handles Rave integration.
       *
       */
      class PMProGateway_rave extends PMProGateway
      {
        public $requeryCount = 0;
        function __construct($gateway = null)
        {
          $this->requeryCount = 0;
          $this->gateway = $gateway;
          $this->gateway_environment = pmpro_getOption("gateway_environment");

          return $this->gateway;
        }

        /**
         * Run on WP init
         */
        static function init()
        {
					//make sure Rave is a gateway option
          add_filter('pmpro_gateways', array('PMProGateway_rave', 'pmpro_gateways'));
					
					//add fields to payment settings
          add_filter('pmpro_payment_options', array('PMProGateway_rave', 'pmpro_payment_options'));
          add_filter('pmpro_payment_option_fields', array('PMProGateway_rave', 'pmpro_payment_option_fields'), 10, 2);
          add_action('wp_ajax_kkd_pmpro_rave_ipn', array('PMProGateway_rave', 'kkd_pmpro_rave_ipn'));
          add_action('wp_ajax_nopriv_kkd_rave_ipn', array('PMProGateway_rave', 'kkd_pmpro_rave_ipn'));
					//code to add at checkout
          $gateway = pmpro_getGateway();
          if ($gateway == "rave") {
            add_filter('pmpro_include_billing_address_fields', '__return_false');
            add_filter('pmpro_required_billing_fields', array('PMProGateway_rave', 'pmpro_required_billing_fields'));
            add_filter('pmpro_include_payment_information_fields', '__return_false');
            add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_rave', 'pmpro_checkout_before_change_membership_level'), 10, 2);

            add_filter('pmpro_gateways_with_pending_status', array('PMProGateway_rave', 'pmpro_gateways_with_pending_status'));
            add_filter('pmpro_pages_shortcode_checkout', array('PMProGateway_rave', 'pmpro_pages_shortcode_checkout'), 20, 1);
            add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_rave', 'pmpro_checkout_default_submit_button'));
						// custom confirmation page
            add_filter('pmpro_pages_shortcode_confirmation', array('PMProGateway_rave', 'pmpro_pages_shortcode_confirmation'), 20, 1);
          }
        }


        function kkd_pmpro_rave_ipn()
        {
          global $wpdb;

          define('SHORTINIT', true);
          $input = @file_get_contents("php://input");
          $event = json_decode($input);

          if ($event->paymentPlan != null) {
            self::kkd_pmpro_renewPlan($event);
          }
          http_response_code(200);
          exit();
        }

        static function kkd_pmpro_renewPlan($event)
        {
          global $wp, $wpdb;

          if (isset($event->status) && ($event->status == "successful")) {

            $amount = $event->amount / 100;
            $old_order = new MemberOrder();
            $subscription_code = $event->paymentPlan;
            $email = $event->customer->email;
            $old_order->getLastMemberOrderBySubscriptionTransactionID($subscription_code);

            if (empty($old_order)) {
              exit();
            }

            $user_id = $old_order->user_id;
            $user = get_userdata($user_id);
            $user->membership_level = pmpro_getMembershipLevelForUser($user_id);

            if (empty($user)) {
              exit();
            }

            $morder = new MemberOrder();
            $morder->user_id = $old_order->user_id;
            $morder->membership_id = $old_order->membership_id;
            $morder->InitialPayment = $amount;	//not the initial payment, but the order class is expecting this
            $morder->PaymentAmount = $amount;
            $morder->payment_transaction_id = $event->flwref;
            $morder->subscription_transaction_id = $subscription_code;

            $morder->gateway = $old_order->gateway;
            $morder->gateway_environment = $old_order->gateway_environment;

            $morder->Email = $email;
            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
            $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

            $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";

            $custom_level = array(
              'user_id' => $morder->user_id,
              'membership_id' => $pmpro_level->id,
              'code_id' => '',
              'initial_payment' => $pmpro_level->initial_payment,
              'billing_amount' => $pmpro_level->billing_amount,
              'cycle_number' => $pmpro_level->cycle_number,
              'cycle_period' => $pmpro_level->cycle_period,
              'billing_limit' => $pmpro_level->billing_limit,
              'trial_amount' => $pmpro_level->trial_amount,
              'trial_limit' => $pmpro_level->trial_limit,
              'startdate' => $startdate,
              'enddate' => $enddate
            );
						
						//get CC info that is on file
            $morder->expirationmonth = get_user_meta($user_id, "pmpro_ExpirationMonth", true);
            $morder->expirationyear = get_user_meta($user_id, "pmpro_ExpirationYear", true);
            $morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
            $morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;

						
						//save
            if ($morder->status != 'success') {

              if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                $morder->status = "success";
                $morder->saveOrder();
              }

            }
            $morder->getMemberOrderByID($morder->id);

						//email the user their invoice
            $pmproemail = new PMProEmail();
            $pmproemail->sendInvoiceEmail($user, $morder);

            do_action('pmpro_subscription_payment_completed', $morder);
            exit();
          }

        }

        /**
         * Redirect Settings to PMPro settings
         */
        static function plugin_action_links($links, $file)
        {
          static $this_plugin;

          if (false === isset($this_plugin) || true === empty($this_plugin)) {
            $this_plugin = plugin_basename(__FILE__);
          }

          if ($file == $this_plugin) {
            $settings_link = '<a href="' . admin_url('admin.php?page=pmpro-paymentsettings') . '">' . __('Settings', KKD_RAVEPMP) . '</a>';
            array_unshift($links, $settings_link);
          }

          return $links;
        }
        static function pmpro_checkout_default_submit_button($show)
        {
          global $gateway, $pmpro_requirebilling;
					
					//show our submit buttons
          ?>			
					<span id="pmpro_submit_span">
						<input type="hidden" name="submit-checkout" value="1" />		
						<input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if ($pmpro_requirebilling) {
                                                                                _e('Check Out with Rave', 'pmpro');
                                                                              } else {
                                                                                _e('Submit and Confirm', 'pmpro');
                                                                              } ?> &raquo;" />		
					</span>
					<?php
				
					//don't show the default
    return false;
  }
  /**
   * Make sure Rave is in the gateways list
   */
  static function pmpro_gateways($gateways)
  {
    if (empty($gateways['rave'])) {
      $gateways = array_slice($gateways, 0, 1) + array("rave" => __('Rave', KKD_RAVEPMP)) + array_slice($gateways, 1);
    }
    return $gateways;
  }

  /**
   * Get a list of payment options that the Rave gateway needs/supports.
   */
  static function getGatewayOptions()
  {
    $options = array(
      'rave_merchant_logo',
      'rave_payment_method',
      'rave_merchant_country',
      'rave_live_public_key',
      'rave_live_secret_key',
      'rave_test_public_key',
      'rave_test_secret_key',
      'gateway_environment',
      'currency',
      'tax_state',
      'tax_rate'
    );

    return $options;
  }

  /**
   * Set payment options for payment settings page.
   */
  static function pmpro_payment_options($options)
  {
					//get Rave options
    $rave_options = self::getGatewayOptions();

					//merge with others.
    $options = array_merge($rave_options, $options);

    return $options;
  }

  /**
   * Display fields for Rave options.
   */
  static function pmpro_payment_option_fields($values, $gateway)
  {
    ?>
					<tr class="pmpro_settings_divider gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                                                  } ?>>
						<td colspan="2">
							<?php _e('Rave Settings' . $gateway, 'paid-memberships-pro'); ?>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<td colspan="2">
							<strong><?php _e('Note', 'paid-memberships-pro'); ?>:</strong> <?php _e('Get your API keys from <a target="_blank" href="https://rave.flutterwave.com/">https://rave.flutterwave.com/</a>', 'paid-memberships-pro'); ?>
						</td>	
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">
							<label><?php _e('Webhook', 'pmpro'); ?>:</label>
						</th>
						<td>
							<p><?php _e('To integrate this plugin fully, add this URL to your webhook on your admin', 'pmpro'); ?> <pre><?php echo admin_url("admin-ajax.php") . "?action=kkd_pmpro_rave_ipn"; ?></pre></p>
							
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_merchant_logo"><?php _e('Merchant Logo', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<input type="text" id="rave_merchant_logo" name="rave_merchant_logo" size="60" value="<?php echo esc_attr($values['rave_merchant_logo']) ?>" />
							<small><?php _e("(OPTIONAL!)Link to company's logo (preferrably square size)", 'paid-memberships-pro'); ?></small>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_payment_method"><?php _e('Payment Method', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<select id="rave_payment_method" name="rave_payment_method">
								<option value="both" <?php if ($values['rave_payment_method'] == 'both') { ?>selected="selected"<?php 
                                                                                                      } ?>><?php _e('All', 'paid-memberships-pro'); ?></option>
								<option value="ussd" <?php if ($values['rave_payment_method'] == 'ussd') { ?>selected="selected"<?php 
                                                                                                      } ?>><?php _e('USSD only', 'paid-memberships-pro'); ?></option>
								<option value="account" <?php if ($values['rave_payment_method'] == 'account') { ?>selected="selected"<?php 
                                                                                                            } ?>><?php _e('Account only', 'paid-memberships-pro'); ?></option>
								<option value="card" <?php if ($values['rave_payment_method'] == 'card') { ?>selected="selected"<?php 
                                                                                                      } ?>><?php _e('Card only', 'paid-memberships-pro'); ?></option>
							</select>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_merchant_country"><?php _e('Merchant Country', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<select id="rave_merchant_country" name="rave_merchant_country">
								<option value="NG" <?php if (empty($values['rave_merchant_country'])) { ?>selected="selected"<?php 
                                                                                                  } ?>><?php _e('Nigeria', 'paid-memberships-pro'); ?></option>
								<option value="GH" <?php if (!empty($values['rave_merchant_country'])) { ?>selected="selected"<?php 
                                                                                                    } ?>><?php _e('Ghana', 'paid-memberships-pro'); ?></option>
								<option value="KE" <?php if (!empty($values['rave_merchant_country'])) { ?>selected="selected"<?php 
                                                                                                    } ?>><?php _e('Kenya', 'paid-memberships-pro'); ?></option>
							</select>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_live_public_key"><?php _e('Live Public Key', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<input type="text" id="rave_live_public_key" name="rave_live_public_key" size="60" value="<?php echo esc_attr($values['rave_live_public_key']) ?>" />
							<small><?php _e("<a href='https://rave.flutterwave.com/dashboard/settings/apis' target='_blank'>Live Public key Link</a>", 'paid-memberships-pro'); ?></small>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_live_secret_key"><?php _e('Live Secret Key', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<input type="text" id="rave_live_secret_key" name="rave_live_secret_key" size="60" value="<?php echo esc_attr($values['rave_live_secret_key']) ?>" />
							<small><?php _e("<a href='https://rave.flutterwave.com/dashboard/settings/apis' target='_blank'>Live Secret key Link</a>", 'paid-memberships-pro'); ?></small>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_test_public_key"><?php _e('Test Public Key', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<input type="text" id="rave_test_public_key" name="rave_test_public_key" size="60" value="<?php echo esc_attr($values['rave_test_public_key']) ?>" />
							<small><?php _e("<a href='https://ravesandboxapi.flutterwave.com/dashboard/settings/apis' target='_blank'>Test Public key Link</a>", 'paid-memberships-pro'); ?></small>
						</td>
					</tr>
					<tr class="gateway gateway_rave" <?php if ($gateway != "rave") { ?>style="display: none;"<?php 
                                                                                            } ?>>
						<th scope="row" valign="top">	
							<label for="rave_test_secret_key"><?php _e('Test Secret Key', 'paid-memberships-pro'); ?>:</label>
						</th>
						<td>
							<input type="text" id="rave_test_secret_key" name="rave_test_secret_key" size="60" value="<?php echo esc_attr($values['rave_test_secret_key']) ?>" />
							<small><?php _e("<a href='https://ravesandboxapi.flutterwave.com/dashboard/settings/apis' target='_blank'>Test Secret key Link</a>", 'paid-memberships-pro'); ?></small>
						</td>
					</tr>
					<?php

  }

  /**
   * Remove required billing fields
   */
  static function pmpro_required_billing_fields($fields)
  {
    unset($fields['bfirstname']);
    unset($fields['blastname']);
    unset($fields['baddress1']);
    unset($fields['bcity']);
    unset($fields['bstate']);
    unset($fields['bzipcode']);
    unset($fields['bphone']);
    unset($fields['bemail']);
    unset($fields['bcountry']);
    unset($fields['CardType']);
    unset($fields['AccountNumber']);
    unset($fields['ExpirationMonth']);
    unset($fields['ExpirationYear']);
    unset($fields['CVV']);

    return $fields;
  }

  static function pmpro_gateways_with_pending_status($gateways)
  {
    $morder = new MemberOrder();
    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));

    if ((!in_array("rave", $gateways)) && $found) {
      array_push($gateways, "rave");
    } elseif (($key = array_search("rave", $gateways)) !== false) {
      unset($gateways[$key]);
    }

    return $gateways;
  }

  /**
   * Instead of change membership levels, send users to Rave payment page.
   */
  static function pmpro_checkout_before_change_membership_level($user_id, $morder)
  {
    global $wpdb, $discount_code_id;
					
					//if no order, no need to pay
    if (empty($morder)) {
      return;
    }
    if (empty($morder->code))
      $morder->code = $morder->getRandomCode();

    $morder->payment_type = "rave";
    $morder->status = "pending";
    $morder->user_id = $user_id;
    $morder->saveOrder();

					//save discount code use
    if (!empty($discount_code_id))
      $wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");

    $morder->Gateway->kkd_pmpro_sendToRave($morder);
  }

  function kkd_pmpro_sendToRave(&$order)
  {
    global $pmpro_currency, $current_user;

					//taxes on initial amount
    $initial_payment = $order->InitialPayment;
    $initial_payment_tax = $order->getTaxForPrice($initial_payment);
    $initial_payment = round((float)$initial_payment + (float)$initial_payment_tax, 2);
					
					//taxes on the amount
    $amount = $order->PaymentAmount;
    $amount_tax = $order->getTaxForPrice($amount);
    $amount = round((float)$amount + (float)$amount_tax, 2) + 0;			
					
					//build RAVE
    $environment = pmpro_getOption("gateway_environment");

    $stagingUrl = 'https://ravesandboxapi.flutterwave.com';
    $liveUrl = 'https://api.ravepay.co';

    $redirectURL = pmpro_url("confirmation", "?level=" . $order->membership_level->id);//urlencode(pmpro_url("checkout", "?level=" . $order->membership_level->id . "&review=" . $order->code))
    if ("sandbox" === $environment || "beta-sandbox" === $environment) {
      $publicKey = pmpro_getOption("rave_test_public_key"); // Remember to change this to your live public keys when going live
      $secretKey = pmpro_getOption("rave_test_secret_key"); // Remember to change this to your live secret keys when going live
    } else {
      $publicKey = pmpro_getOption("rave_live_public_key"); // Remember to change this to your live public keys when going live
      $secretKey = pmpro_getOption("rave_live_secret_key"); // Remember to change this to your live secret keys when going live
    }


    $baseUrl = $stagingUrl;
    if ("sandbox" === $environment || "beta-sandbox" === $environment) {
      $baseUrl = $stagingUrl;
    } else {
      $baseUrl = $liveUrl;
    }

    $country = pmpro_getOption('rave_merchant_country');

    $ref = $order->code;
    $overrideRef = true;

    $name = $current_user->display_name;
    $parts = explode(' ', $name);

    if ($parts[1]) {
      $firstname = $parts[0];
      $lastname = $parts[1];
    }

    else {
      $firstname = $name;
    }

				    // Client Parameters

    if (pmpro_isLevelRecurring($order->membership_level)) {
							//convert billing period
      if ($order->BillingPeriod == "Day")
        $period = "daily";
      elseif ($order->BillingPeriod == "Week")
        $period = "weekly";
      elseif ($order->BillingPeriod == "Month")
        $period = "monthly";
      elseif ($order->BillingPeriod == "Year")
        $period = "yearly";
      else {
        $order->error = "Invalid billing period: " . $order->BillingPeriod;
        $order->shorterror = "Invalid billing period: " . $order->BillingPeriod;
        return false;
      }

      if ($order->membership_level->cycle_number == 1) {
        $interval = $period;
      } else {
        $interval = "every " . $order->membership_level->cycle_number . " " . strtolower($order->BillingPeriod) . "s";
      }

      $header = array(
        'Content-Type' => 'application/x-www-form-urlencoded'
      );

      $duration = $order->membership_level->billing_limit;


      $url = $baseUrl . '/v2/gpx/paymentplans/query?seckey=' . $secretKey . '&q=' . $order->membership_level->name . ' - ' . $amount . ' - ' . $interval;

      $args = array(
        'header' => $header
      );

      $response = wp_remote_get($url);
      $resp = json_decode(wp_remote_retrieve_body($response));

      $payPlan = $resp->data->paymentplans[0]->id;

      if ($resp->data->paymentplans[0]->id < 1) {
        $url = $baseUrl . '/v2/gpx/paymentplans/create';
								// make request to endpoint.

        $args = array(
          'body' => "name=" . $order->membership_level->name . " - " . $amount . " - " . $interval . "&amount=" . $amount . "&interval=" . $interval . "&duration=" . $duration . "&seckey=" . $secretKey,
          'header' => $header
        );

        $response = wp_remote_post($url, $args);
        $resp = json_decode(wp_remote_retrieve_body($response));

        $payPlan = $resp->data->id;
      }

      $postfields = array();
      $postfields['PBFPubKey'] = $publicKey;
      $postfields['customer_email'] = $current_user->user_email;
      $postfields['customer_firstname'] = $firstname;
      $postfields['custom_logo'] = pmpro_getOption("rave_merchant_logo");
      $postfields['customer_lastname'] = $lastname;
      $postfields['custom_description'] = "Payment for Membership level: " . $order->membership_level->name . " on " . get_bloginfo('name');
      $postfields['custom_title'] = get_bloginfo('name');
      $postfields['customer_phone'] = $order->billing->phone;
      $postfields['payment_plan'] = $payPlan;
      $postfields['country'] = $country;
      $postfields['redirect_url'] = $redirectURL;
      $postfields['txref'] = $ref;
      $postfields['payment_method'] = pmpro_getOption("rave_payment_method");
      $postfields['amount'] = $initial_payment + 0;
      $postfields['currency'] = $pmpro_currency;
      $postfields['hosted_payment'] = 1;
      ksort($postfields);
      $stringToHash = "";
      foreach ($postfields as $key => $val) {
        $stringToHash .= $val;
      }
      $stringToHash .= $secretKey;
      $hashedValue = hash('sha256', $stringToHash);

      $transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue));
      $json = json_encode($transactionData);
      $htmlOutput = "
									<script type='text/javascript' src='" . $baseUrl . "/flwv3-pug/getpaidx/api/flwpbf-inline.js'></script>
									<script>
									document.addEventListener('DOMContentLoaded', function(event) {
										var data = JSON.parse('" . json_encode($transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue))) . "');
										getpaidSetup(data);
								});
									</script>
									";
      echo $htmlOutput;
      exit;
    } else {

      $postfields = array();
      $postfields['PBFPubKey'] = $publicKey;
      $postfields['customer_email'] = $current_user->user_email;
      $postfields['customer_firstname'] = $firstname;
      $postfields['custom_logo'] = pmpro_getOption("rave_merchant_logo");
      $postfields['customer_lastname'] = $lastname;
      $postfields['custom_description'] = "Payment for Membership level: " . $order->membership_level->name . " on " . get_bloginfo('name');
      $postfields['custom_title'] = get_bloginfo('name');
      $postfields['customer_phone'] = $order->billing->phone;
      $postfields['country'] = $country;
      $postfields['redirect_url'] = $redirectURL;
      $postfields['txref'] = $ref;
      $postfields['payment_method'] = pmpro_getOption("rave_payment_method");
      $postfields['amount'] = $initial_payment + 0;
      $postfields['currency'] = $pmpro_currency;
      $postfields['hosted_payment'] = 1;
      ksort($postfields);
      $stringToHash = "";
      foreach ($postfields as $key => $val) {
        $stringToHash .= $val;
      }
      $stringToHash .= $secretKey;
      $hashedValue = hash('sha256', $stringToHash);

      $transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue));
      $json = json_encode($transactionData);
      $htmlOutput = "
					    <script type='text/javascript' src='" . $baseUrl . "/flwv3-pug/getpaidx/api/flwpbf-inline.js'></script>
					    <script>
					    document.addEventListener('DOMContentLoaded', function(event) {
						    var data = JSON.parse('" . json_encode($transactionData = array_merge($postfields, array('integrity_hash' => $hashedValue))) . "');
						    getpaidSetup(data);
						});
					    </script>
					    ";
      echo $htmlOutput;
      exit;
    }
  }

  static function pmpro_pages_shortcode_checkout($content)
  {
    $morder = new MemberOrder();
    $found = $morder->getLastMemberOrder(get_current_user_id(), apply_filters("pmpro_confirmation_order_status", array("pending")));
    if ($found) {
      $morder->Gateway->kkd_pmpro_delete($morder);
    }

    if (isset($_REQUEST['error'])) {
      global $pmpro_msg, $pmpro_msgt;

      $pmpro_msg = __("IMPORTANT: Something went wrong during the payment. Please try again later or contact the site owner to fix this issue.<br/>" . urldecode($_REQUEST['error']), "pmpro");
      $pmpro_msgt = "pmpro_error";

      $content = "<div id='pmpro_message' class='pmpro_message " . $pmpro_msgt . "'>" . $pmpro_msg . "</div>" . $content;
    }

    return $content;
  }

  /**
   * Custom confirmation page
   */
  static function pmpro_pages_shortcode_confirmation($content, $reference = null)
  {
    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency, $gateway;
    if (!isset($_REQUEST['txref'])) {
      $_REQUEST['txref'] = null;
    }
    if ($reference != null) {
      $_REQUEST['txref'] = $reference;
    }

    if (empty($pmpro_invoice)) {
      $morder = new MemberOrder($_REQUEST['txref']);
      if (!empty($morder) && $morder->gateway == "rave") {
        $pmpro_invoice = $morder;
      }
    }

    if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "rave" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
      $morder = $pmpro_invoice;
      if ($morder->code == $_REQUEST['txref']) {
        $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
        $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
        $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

        $mode = pmpro_getOption("gateway_environment");

        if ($mode == 'sandbox') {
          $apiLink = "https://ravesandboxapi.flutterwave.com/";
        } else {
          $apiLink = "https://api.ravepay.co/";

        }


        $country = pmpro_getOption('rave_merchant_country');

								//Verify Transaction
        if (isset($_GET['txref'])) {
          $morder->Gateway->kkd_pmpro_requery($morder);
        } else {
          $content = 'Unable to Verify Transaction';

        }

      } else {
        $content = 'Invalid Transaction Reference';
      }
    }


    return $content;

  }

  function kkd_pmpro_requery(&$morder)
  {
    $mode = pmpro_getOption("gateway_environment");

    if ($mode == 'sandbox') {
      $apiLink = "https://ravesandboxapi.flutterwave.com/";
      $secretKey = pmpro_getOption("rave_test_secret_key");
    } else {
      $apiLink = "https://api.ravepay.co/";
      $secretKey = pmpro_getOption("rave_live_secret_key");
    }
    $txref = $_REQUEST['txref'];
    $this->requeryCount++;
    $data = array(
      'txref' => $txref,
      'SECKEY' => $secretKey,
      'last_attempt' => '1'
				        // 'only_successful' => '1'
    );
						// make request to endpoint.

    $args = array(
      'body' => $data
    );

    $response = wp_remote_post($apiLink . 'flwv3-pug/getpaidx/api/v2/verify', $args);

    $resp = json_decode(wp_remote_retrieve_body($response));

    if ($resp && $resp->status === "success") {
      if ($resp && $resp->data && $resp->data->status === "successful") {
        $morder->Gateway->kkd_pmpro_verifyTransaction($morder, $resp->data);
      } elseif ($resp && $resp->data && $resp->data->status === "failed") {
        $morder->Gateway->kkd_pmpro_failed($morder);
      } else {
        if ($this->requeryCount > 4) {
          $morder->Gateway->kkd_pmpro_failed($morder);
        } else {
          sleep(3);
          $morder->Gateway->kkd_pmpro_requery($morder);
        }
      }
    } else {
      if ($this->requeryCount > 4) {
        $morder->Gateway->kkd_pmpro_failed($morder);
      } else {
        sleep(3);
        $morder->Gateway->kkd_pmpro_requery($morder);
      }
    }
  }

  /**
   * Requeries a previous transaction from the Rave payment gateway
   * @param string $referenceNumber This should be the reference number of the transaction you want to requery
   * @return object
   * */
  function kkd_pmpro_verifyTransaction(&$order, &$data)
  {
    global $wpdb, $current_user, $pmpro_currency;
    $currency = $pmpro_currency;
    $amount = $order->total;

    if (($data->chargecode == "00" || $data->chargecode == "0") && ($data->amount >= $amount) && ($data->currency == $currency)) {

      $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$order->membership_id . "' LIMIT 1");
							//$pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
      $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $order->user_id, $pmpro_level);

      $enddate = "'" . date("Y-m-d", strtotime("+ " . $pmpro_level->expiration_number . " " . $pmpro_level->expiration_period, current_time("timestamp"))) . "'";

      if ($pmpro_level->cycle_period) {
        $date = strtotime("+" . $pmpro_level->cycle_number . " " . strtolower($pmpro_level->cycle_period));

        $enddate = date('Y-m-d H:i:s', $date);
      }

      if ($order->status != 'success') {


        $custom_level = array(
          'user_id' => $current_user->ID,
          'membership_id' => $pmpro_level->id,
          'code_id' => $discount_code_id,
          'initial_payment' => $pmpro_level->initial_payment,
          'billing_amount' => $pmpro_level->billing_amount,
          'cycle_number' => $pmpro_level->cycle_number,
          'cycle_period' => $pmpro_level->cycle_period,
          'billing_limit' => $pmpro_level->billing_limit,
          'trial_amount' => $pmpro_level->trial_amount,
          'trial_limit' => $pmpro_level->trial_limit,
          'startdate' => $startdate,
          'enddate' => $enddate
        );

        if ($data->paymentplan > 0) {
          $order->subscription_transaction_id = $data->paymentplan;
        }

        if (pmpro_changeMembershipLevel($custom_level, $order->user_id, 'changed')) {
          $order->membership_id = $pmpro_level->id;
          $order->payment_transaction_id = $_REQUEST['txref'];
          $order->status = "success";
          $order->saveOrder();
        }

      }

      if (!empty($order)) {
        $pmpro_invoice = new MemberOrder($order->id);
      } else {
        $pmpro_invoice = null;
      }

      $current_user->membership_level = $pmpro_level; //make sure they have the right level info
      $current_user->membership_level->enddate = $enddate;
      if ($current_user->ID) {
        $current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
      }
							
							//send email to member
      $pmproemail = new PMProEmail();
      $pmproemail->sendCheckoutEmail($current_user, $invoice);
							//send email to admin
      $pmproemail = new PMProEmail();
      $pmproemail->sendCheckoutAdminEmail($current_user, $invoice);
							// echo "<pre>";
							// print_r($pmpro_level);

      $content = "<ul>
								<li><strong>Account:</strong> " . $current_user->display_name . " (" . $current_user->user_email . ")</li>
								<li><strong>Order:</strong> " . $order->code . "</li>
								<li><strong>Membership Level:</strong> " . $pmpro_level->name . "</li>
								<li><strong>Amount Paid:</strong> " . $order->total . " " . $pmpro_currency . "</li>
								</ul>";

      ob_start();
      if (file_exists(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php")) {
        include(get_stylesheet_directory() . "/paid-memberships-pro/pages/confirmation.php");
      } else {
        include(PMPRO_DIR . "/pages/confirmation.php");
      }

      $content .= ob_get_contents();
      ob_end_clean();

      echo $content;

      exit;
    } else {
      if ($data->amount < $amount) {
        return $morder->Gateway->kkd_pmpro_failed($order, "Amount was not enough");
      } elseif ($data->currency != $currency) {
        return $morder->Gateway->kkd_pmpro_failed($order, "Wrong Currency");
      }
      return $morder->Gateway->kkd_pmpro_failed($order);
    }
  }

  function kkd_pmpro_failed(&$order, &$message = '')
  {

    echo $order->shorterror;

    $error = ($_GET['cancelled'] == true) ? "You cancelled the transaction" : "Transaction Failed";

    $content = "<h2>" . $error . "</h2>";
    $content .= "<p>" . $message . "</p>";

    $order->status = "cancelled";
    $order->shorterror = $error;

    echo $content;

    $order->saveOrder();

    exit;
  }

  function kkd_pmpro_delete(&$order)
  {

					//no matter what happens below, we're going to cancel the order in our system
    $order->updateStatus("cancelled");
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->pmpro_membership_orders WHERE id = '" . $order->id . "'");
  }
}
}
}
}
?>