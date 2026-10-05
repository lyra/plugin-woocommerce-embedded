<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;
use Lyranetwork\LyraEmbedded\Sdk\Rest\Api as LyraRest;

class LyraEmbeddedSubscriptionProcessor
{
    /**
     * Process a renewal payment for a subscription order.
     *
     * @param float|int|string $renewal_total Renewal amount to charge.
     * @param WC_Order         $renewal_order Renewal order instance.
     * @return void
     */
    public static function process_subscription_payment($renewal_total, WC_Order $renewal_order)
    {
        if (! $renewal_order) {
            WC_Gateway_LyraEmbedded::log_info('Could not load renewal order or process renewal payment.');

            return;
        }

        $renewal_order_id = $renewal_order->get_id();

        $subscriptions = wcs_get_subscriptions_for_order($renewal_order, ['order_type' => ['renewal']]);
        $subscription = reset($subscriptions); // Get first subscription.
        if (! $subscription) {
            WC_Gateway_LyraEmbedded::log_info("No subscription found for renewal order #{$renewal_order_id}.");

            return;
        }

        $key = LyraEmbeddedTools::get_private_key();
        if (! $key) {
            $error_msg = __('Private key is not configured. Subscription renewal cannot be processed.', 'woo-lyra-embedded-payment');
            self::set_error($renewal_order, $subscription, $error_msg);

            WC_Gateway_LyraEmbedded::log_info("Error while processing renewal payment for subscription #{$subscription->get_id()}, renewal order #{$renewal_order_id}: private key is not configured.");

            $renewal_order->update_status('failed');

            return;
        }

        $cust_id = $renewal_order->get_user_id();
        $saved_identifier = self::get_identifier($cust_id, $subscription);
        if (! $saved_identifier) {
            WC_Gateway_LyraEmbedded::log_info("Customer #{$cust_id} has no valid identifier. Renewal order #{$renewal_order_id} for subscription #{$subscription->get_id()} cannot be processed.");

            $error_msg = __('Customer has no valid identifier. Subscription renewal cannot be processed.', 'woo-lyra-embedded-payment');
            self::set_error($renewal_order, $subscription, $error_msg);

            $renewal_order->update_status('failed');

            return;
        }

        // Allow developers to hook into the subscription renewal payment before it processed.
        do_action('lyra_embedded_before_renewal_payment_created', $renewal_order);

        WC_Gateway_LyraEmbedded::log_info("Start processing renewal payment for subscription #{$subscription->get_id()}, renewal order #{$renewal_order_id}.");

        $silent_payment_result = self::silent_payment($renewal_total, $renewal_order, $saved_identifier);

        if (! $silent_payment_result) {
            $error_msg = sprintf(__('An error has occurred during the renewal of the subscription. Please consult the %s logs for more details.',
                'woo-lyra-embedded-payment'), LyraEmbeddedTools::get_default('GATEWAY_NAME'));
            self::set_error($renewal_order, $subscription, $error_msg);

            $renewal_order->update_status('failed');

            return;
        }

        $payment_result = LyraEmbeddedTools::get_payment_result($silent_payment_result);

        self::update_renewal_order($renewal_order, $payment_result);
    }

    /**
     * Update the renewal order status from the gateway payment result.
     *
     * @param WC_Order $renewal_order Renewal order to update.
     * @param array    $payment_result Gateway payment result data.
     * @return void
     */
    private static function update_renewal_order($renewal_order, $payment_result)
    {
        $subscriptions = wcs_get_subscriptions_for_order($renewal_order, ['order_type' => ['renewal']]);
        $subscription = reset($subscriptions); // Get first subscription.
        $renewal_order_id = $renewal_order->get_id();

        if (! $renewal_order->has_status('pending')) {
            WC_Gateway_LyraEmbedded::log_error("Renewal order #{$renewal_order_id} is not in pending status: order status cannot be updated.");

            return;
        }

        try {
            $order_note = sprintf(_x('Processed renewal payment for order #%s.', 'used in order note', 'woo-lyra-embedded-payment'), $renewal_order_id);
            if ($subscription) {
                $subscription->add_order_note($order_note);
            }

            WC_Gateway_LyraEmbedded::lyra_embedded_add_order_note($payment_result, $renewal_order);

            $trans_status = $payment_result['status'];
            if (LyraApi::isAcceptedPayment($trans_status)) {
                WC_Gateway_LyraEmbedded::log_info("Payment accepted, complete payment for renewal order #$renewal_order_id.");

                $renewal_order->payment_complete();
                WC_Gateway_LyraEmbedded::log_info("Payment successful, renewal order #$renewal_order_id completed.");
            } else {
                // Payment failed or pending.
                $renewal_order->update_status('failed');
                WC_Gateway_LyraEmbedded::log_info("Payment failed for renewal order #$renewal_order_id.");
            }
        } catch (Exception $e) {
            WC_Gateway_LyraEmbedded::log_error("An error occurred while processing #{$renewal_order_id} : {$e->getMessage()}.");

            $order_note = sprintf(__("Error while processing order: %s", 'woo-lyra-embedded-payment'), $e->getMessage());
            $renewal_order->update_status('pending', $order_note);

            return;
        }
    }

    /**
     * Perform a silent renewal payment using a saved payment token.
     *
     * @param float|int|string $renewal_total    Renewal amount to charge.
     * @param WC_Order         $renewal_order    Renewal order being processed.
     * @param string           $saved_identifier Saved payment token identifier.
     * @return array|false
     */
    private static function silent_payment($renewal_total, WC_Order $renewal_order, $saved_identifier)
    {
        $order_id = $renewal_order->get_id();
        $currency = LyraApi::findCurrencyByAlphaCode($renewal_order->get_currency(), LyraEmbeddedTools::get_white_label());

        $subscriptions = wcs_get_subscriptions_for_order($renewal_order, ['order_type' => ['renewal']]);
        $subscription = reset($subscriptions); // Get first subscription.

        if (! $subscription) {
            WC_Gateway_LyraEmbedded::log_error("No subscription found for renewal order #{$order_id}, cannot process renewal payment.");

            return false;
        }

        $is_hpos_enabled = LyraEmbeddedTools::is_hpos_enabled();

        $billing_persontype = LyraEmbeddedTools::get_meta_data($renewal_orde, '_billing_persontype', $is_hpos_enabled);
        if ($billing_persontype && $billing_persontype == '2') {
            $identity_code = '_billing_cnpj';
            $cust_status = 'COMPANY';
        } else {
            $identity_code = '_billing_cpf';
            $cust_status = 'PRIVATE';
        }

        $params = [
            'orderId' => $order_id,
            'customer' => [
                'email' => $renewal_order->get_billing_email(),
                'reference' => $renewal_order->get_user_id(),
                'billingDetails' => [
                    'firstName' => $renewal_order->get_billing_first_name(),
                    'lastName' => $renewal_order->get_billing_last_name(),
                    'address' => $renewal_order->get_billing_address_1() . ' ' . $renewal_order->get_billing_address_2(),
                    'zipCode' => $renewal_order->get_billing_postcode(),
                    'city' => $renewal_order->get_billing_city(),
                    'state' => $renewal_order->get_billing_state(),
                    'phoneNumber' => str_replace(['(', '-', ' ', ')'], '', $renewal_order->get_billing_phone()),
                    'country' => $renewal_order->get_billing_country(),
                    'identityCode' => LyraEmbeddedTools::get_meta_data($renewal_orde, $identity_code, $is_hpos_enabled),
                    'streetNumber' => LyraEmbeddedTools::get_meta_data($renewal_orde, '_billing_number', $is_hpos_enabled),
                    'district' => LyraEmbeddedTools::get_meta_data($renewal_orde, '_billing_neighborhood', $is_hpos_enabled) ?? $renewal_order->get_billing_city(),
                    'status' => $cust_status
                ],
                'shippingDetails' => [
                    'firstName' => $renewal_order->get_shipping_first_name(),
                    'lastName' => $renewal_order->get_shipping_last_name(),
                    'address' => $renewal_order->get_shipping_address_1(),
                    'address2' => $renewal_order->get_shipping_address_2(),
                    'zipCode' => $renewal_order->get_shipping_postcode(),
                    'city' => $renewal_order->get_shipping_city(),
                    'state' => $renewal_order->get_shipping_state(),
                    'country' => $renewal_order->get_shipping_country()
                ]
            ],
            'transactionOptions' => [
                'cardOptions' => [
                    'paymentSource' => 'EC'
                ]
            ],
            'contrib' => LyraEmbeddedTools::get_contrib(),
            'amount' => $currency->convertAmountToInteger($renewal_total),
            'currency' => $currency->getAlpha3(),
            'formAction' => 'SILENT',
            'paymentMethodToken' => $saved_identifier,
            'metadata' => [
                'order_key' => $renewal_order->get_order_key(),
                'blog_id' => get_current_blog_id(),
                'wcs_renewal_order' => 'TRUE',
                'parent_order_id' => $subscription->get_parent_id(),
                'subsc_id' => $subscription->get_id()
            ]
        ];

        try {
            $client  = self::get_rest_client();
            $result = $client->post('V4/Charge/CreatePayment', wp_json_encode($params));

            if ($result['status'] != 'SUCCESS') {
                WC_Gateway_LyraEmbedded::log_info("Error while processing renewal payment for subscription #{$subscription->get_id()}, renewal order #{$order_id} : " . $result['answer']['errorMessage']
                    . ' (' . $result['answer']['errorCode'] . ').');

                if (isset($result['answer']['detailedErrorMessage']) && ! empty($result['answer']['detailedErrorMessage'])) {
                    WC_Gateway_LyraEmbedded::log_error('Detailed message: ' . $result['answer']['detailedErrorMessage'] . ' (' . $result['answer']['detailedErrorCode'] . ').');
                }

                return false;
            }

            // Payment form token created successfully.
            WC_Gateway_LyraEmbedded::log_info("Renewal payment successfully processed for subscription #{$subscription->get_id()}, renewal order #{$order_id}.");

            return $result['answer'];
        } catch (Exception $e) {
            WC_Gateway_LyraEmbedded::log_error($e->getMessage());

            return false;
        }
    }

    /**
     * Check whether a saved payment identifier is still valid on the gateway.
     *
     * @param int         $cust_id    WooCommerce customer ID.
     * @param string|null $identifier Saved payment token identifier.
     * @return bool
     */
    private static function check_identifier($cust_id, $identifier = null)
    {
        if (! $identifier) { // Customer has no saved identifier.
            return false;
        }

        $customer = new WC_Customer($cust_id);
        if (! $customer) {
            return false;
        }

        try {
            $request_data = [
                'paymentMethodToken' => $identifier
            ];

            // Perform REST request to check identifier.
            $client = self::get_rest_client();
            $result = $client->post('V4/Token/Get', wp_json_encode($request_data));

            // Verify the API call succeeded before reading the answer.
            if (! isset($result['status']) || $result['status'] !== 'SUCCESS') {
                $error_code = $result['answer']['errorCode'] ?? 'unknown';
                WC_Gateway_LyraEmbedded::log_info("Identifier check API call failed for customer (code: {$error_code}). Assuming identifier is still valid.");

                return true;
            }

            $cancellation_date = $result['answer']['cancellationDate'] ?? null;
            if ($cancellation_date && (strtotime($cancellation_date) <= time())) {
                WC_Gateway_LyraEmbedded::log_error("Identifier for customer {$customer->get_billing_email()}, is expired on payment gateway in date of: {$cancellation_date}.");

                return false;
            }

            return true;
        } catch (Exception $e) {
            $invalid_ident_codes = ['PSP_030', 'PSP_031', 'PSP_561', 'PSP_607', 'INT_905'];
            if (in_array($e->getCode(), $invalid_ident_codes, true)) {
                // The identifier is invalid or doesn't exist.
                WC_Gateway_LyraEmbedded::log_error("Identifier for customer {$customer->get_billing_email()}, is invalid or doesn't exist: {$e->getMessage()}");

                return false;
            }

            WC_Gateway_LyraEmbedded::log_error("Identifier for customer {$customer->get_billing_email()}, couldn't be verified on gateway: {$e->getMessage()}.");

            return true;
        }
    }

    /**
     * Add an error note to the order and subscription, and persist it for admin display.
     *
     * @param WC_Order        $order        Renewal order instance.
     * @param WC_Subscription $subscription Related subscription instance.
     * @param string          $error_msg    Error message to store.
     * @return void
     */
    private static function set_error($order, $subscription, $error_msg)
    {
        $order->add_order_note($error_msg);
        $subscription->add_order_note($error_msg);

        if (is_admin()) { // Show error message only if it's made on backend.
            set_transient('lyra_embedded_renewal_error_msg', $error_msg);
        }
    }

    /**
     * Retrieve a valid saved payment identifier for the customer subscription.
     *
     * @param int             $cust_id      WooCommerce customer ID.
     * @param WC_Subscription $subscription Subscription instance.
     * @return string|null
     */
    private static function get_identifier($cust_id, $subscription)
    {
        $saved_identifier = LyraEmbeddedTools::get_meta_data($subscription, 'lyra_embedded_token');
        if ($saved_identifier && self::check_identifier($cust_id, $saved_identifier)) {
            return $saved_identifier;
        }

        return null;
    }

    /**
     * Create the REST client used to call the Lyra Embedded API.
     *
     * @return LyraRest
     */
    private static function get_rest_client()
    {
        $shop_id = LyraEmbeddedTools::get_shop_id();
        $private_key = LyraEmbeddedTools::get_private_key();

        if (empty($shop_id) || empty($private_key)) {
            WC_Gateway_LyraEmbedded::log_error("Unable to instantiate RET API client for {$metadata}: missing shop ID or private key in active Lyra Embedded configuration.");

            return null;
        }

        return new LyraRest(
            LyraEmbeddedTools::get_rest_url(),
            $shop_id,
            $private_key
        );
    }
}
