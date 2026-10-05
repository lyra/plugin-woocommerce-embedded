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

class LyraEmbeddedTools
{
    /**
     * @var string The gateway product name shown in the module.
     */
    private static $GATEWAY_NAME = 'Lyra Collect';

    /**
     * @var string The merchant Back Office name.
     */
    private static $BACKOFFICE_NAME = 'Lyra Expert';

    /**
     * @var string The default context mode (TEST or PRODUCTION).
     */
    private static $CTX_MODE = 'TEST';

    /**
     * @var string The CMS identifier for tracking.
     */
    private static $CMS_IDENTIFIER = 'WooCommerce_Embedded_10.x-11.x';

    /**
     * @var string The support contact information.
     */
    private static $SUPPORT_EMAIL = 'https://support.lyra.com/hc/fr/requests/new';

    /**
     * @var string The current plugin version.
     */
    private static $PLUGIN_VERSION = '1.0.0';

    /**
     * @var string The REST API base URL.
     */
    private static $REST_URL = 'https://api.lyra.com/api-payment/';

    /**
     * @var string The static resources base URL.
     */
    private static $STATIC_URL = 'https://static.lyra.com/static/';

    /**
     * @var string The epsilon resources path.
     */
    private static $EPSILON_PATH = 'js/epsilon/stable/';

    /**
     * @var string The embedded form default theme.
     */
    private static  $THEME = 'neon';

    /**
     * @var string The default language.
     */
    private static $LANGUAGE = 'en';

    /**
     * @var string The regular expresion of not allowed chars.
     */
    private static $not_allowed_chars_regex = '#[^A-Z0-9ÁÀÂÄÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜÇ -]#ui';

    public static $plugin_features = [
        'whitelabelall' => false
    ];

    public static $abandoned_statuses = [
        'ABANDONED',
        'EXPIRED'
    ];

    /**
     * Retrieves the default value of a static property by name.
     *
     * @param string $name The name of the static property to retrieve
     * @return string The property value, or an empty string if not found
     */
    public static function get_default(string $name): string
    {
        if (! isset(self::${$name})) {
            return '';
        }

        return self::${$name};
    }

    /**
     * Returns the URL to Lyra epsilon static resources.
     *
     * @return string
     */
    public static function get_epsilon_url(): string
    {
        return self::$STATIC_URL . self::$EPSILON_PATH;
    }

    /**
     * Return the current language string in ISO 639-1 format (e.g. "en").
     *
     * @return string Language ISO code.
     */
    public static function get_language(): string
    {
        $language = get_locale() ? substr(get_locale(), 0, 2) : null;
        if (! $language || ! LyraApi::isSupportedLanguage($language)) {
            $language = self::get_default('LANGUAGE');
        }

        return $language;
    }

    /**
     * Return the current locale string in BCP 47 format (e.g. "en-US").
     *
     * @return string Locale code with hyphen separator
     */
    public static function get_locale(): string
    {
        return str_replace('_', '-', get_locale());
    }

    /**
     * Build the contrib string sent to the payment gateway for tracking purposes.
     * The string includes the CMS identifier, plugin version, WooCommerce version,
     * PHP version.
     *
     * @return string
     */
    public static function get_contrib() : string
    {
        // Effective used version.
        include ABSPATH . WPINC . '/version.php';
        $version = $wp_version . '_' . WC_VERSION;

        return self::get_default('CMS_IDENTIFIER') . '_' . self::get_default('PLUGIN_VERSION') . '/' . $version
            . '/' . LyraApi::shortPhpVersion();
    }

    /**
     * Return the absolute URL to the REST API URL.
     *
     * @param  string|null $white_label White-label identifier override.
     * @return string
     */
    public static function get_rest_url($white_label = null): string
    {
        if ($white_label === null) {
            $white_label = self::get_white_label();
        }

        return LyraApi::getWhiteLabelUrl($white_label, 'restUrl') ?? self::$REST_URL;
    }

    /**
     * Return the absolute URL to the REST API URL.
     *
     * @param  string|null $white_label White-label identifier override.
     * @return string
     */
    public static function get_static_url($white_label = null): string
    {
        if ($white_label === null) {
            $white_label = self::get_white_label();
        }

        return LyraApi::getWhiteLabelUrl($white_label, 'staticUrl') ?? self::$STATIC_URL;
    }

    /**
     * Return the white-label identifier from the active configuration when enabled.
     *
     * @return string
     */
    public static function get_white_label(): string
    {
        if (! (self::$plugin_features['whitelabelall'] ?? false)) {
            return '';
        }

        $active_model = self::get_active_form_config();
        $white_label = $active_model['shopConfig']['payzenWhiteLabel'] ?? '';
        if ($white_label !== '' && $white_label !== 'EU') {
            return $white_label;
        }

        return '';
    }

    /**
     * Obtain a widget form token from the Lyra REST API.
     *
     * @param  array<string, mixed> $model Decoded widget shop configuration object
     *                                     containing `shopId`, `privateKey`, and `wsData`.
     * @return array<string, mixed>|string API response array on success, empty string on failure.
     */
    public static function get_widget_token($model)
    {
        if (! is_array($model)) {
             WC_Gateway_LyraEmbedded::log_error('Unable to create widget form token: invalid configuration model.');

             return '';
        }

        $ws_data = $model['wsData'] ?? [];

        $params = [
            "amount" => $ws_data['amount'] ?? '',
            "currency" => $ws_data['currency'] ?? '',
            "orderId" => "myOrderId-999999",
            "formAction" => $ws_data['formAction'] ?? 'PAYMENT',
            "customer" => [
                'reference' => $ws_data['customer']['reference'] ?? '',
                "email" => "sample@example.com"
            ],
            "contrib" => self::get_contrib()
        ];

        $shop_id = $model['shopId'] ?? '';
        $private_key = $model['privateKey'] ?? '';
        if (($shop_id === '') || ($private_key === '')) {
            WC_Gateway_LyraEmbedded::log_error('Unable to create widget form token: missing shop ID or private key.');

            return '';
        }

        $white_label = $model['payzenWhiteLabel'] ?? null;

        try {
            $client = new LyraRest(
                self::get_rest_url($white_label),
                $shop_id,
                $private_key
            );

            $result = $client->post('V4/Charge/CreatePayment', json_encode($params));

            if ($result['status'] != 'SUCCESS') {
                WC_Gateway_LyraEmbedded::log_error("Error while creating widget form token: " . $result['answer']['errorMessage']
                    . ' (' . $result['answer']['errorCode'] . ').');

                return '';
            }

            WC_Gateway_LyraEmbedded::log_info("Widget form token created successfully with data: " . json_encode($params) . ".");

            return $result;
        } catch(Exception $e) {
            WC_Gateway_LyraEmbedded::log_error("An error occurred while creating widget form token: " . $e->getMessage());

            return '';
        }
    }

    /**
     * Load and return the currently active form configuration model from WooCommerce configuration.
     * Returns an empty array when no models are configured or the active model cannot be found.
     *
     * @return array<string, mixed> The active model array, or an empty array on failure.
     */
    public static function get_active_form_config(): array
    {
        $models = get_option('woocommerce_lyra_embedded_settings', null);
        if (empty($models)) {
            return [];
        }

        if (! isset($models['activeModel']) || ! isset($models['models']) || ! is_array($models['models'])) {
            return [];
        }

        foreach ($models['models'] as $model) {
            if (isset($model['id']) && ($model['id'] === $models['activeModel'])) {
                return is_array($model) ? $model : [];
            }
        }

        return [];
    }

    /**
     * Return the embedded form theme name from the active model configuration,
     * falling back to the module default theme.
     *
     * @return string
     */
    public static function get_theme(): string
    {
        $active_model = self::get_active_form_config();

        return $active_model['inteConfig']['theme']['name'] ?? self::get_default('THEME');
    }

    /**
     * Return the shop ID from the active form configuration model.
     *
     * @return string The shop ID, or an empty string when not configured.
     */
    public static function get_shop_id(): string
    {
        $active_model = self::get_active_form_config();

        return $active_model['shopConfig']['shopId'] ?? '';
    }

    /**
     * Return the private key for the current shop mode.
     *
     * @return string
     */
    public static function get_private_key(): string
    {
        return self::get_key('private');
    }

    /**
     * Return the public key for the current shop mode.
     *
     * @return string
     */
    public static function get_public_key(): string
    {
        return self::get_key('public');
    }

    /**
     * Return the HMAC-SHA256 key for the current shop mode.
     *
     * @return string
     */
    public static function get_sha_key(): string
    {
        return self::get_key('sha');
    }

    /**
     * Returns the current context mode from configuration (e.g., TEST or PRODUCTION).
     *
     * @return string|null Lowercase context mode or null if not configured
     */
    public static function get_mode(): string
    {
        $active_model = self::get_active_form_config();

        return strtolower($active_model['shopMode'] ?? self::get_default('CTX_MODE'));
    }

    /**
     * Return the localized payment method title for the active model.
     *
     * @return string Localized title, or default if no matching label is found.
     */
    public static function get_title(): string
    {
        $default_title = __('Payment by credit card', 'woo-lyra-embedded-payment');

        $active_model = self::get_active_form_config();
        $labels = $active_model['cmsConfig']['cmsPaymentMethodLabel'] ?? [];
        if (! is_array($labels) || empty($labels)) {
            return $default_title;
        }

        $language = strtolower(self::get_language());

        foreach ($labels as $lang => $label) {
            if (! is_string($lang) || ! is_string($label) || $label === '') {
                continue;
            }

            // Extract the primary subtag from the DB key (e.g. "en" from "en-EN").
            $normalized_lang = strtolower(strstr($lang, '-', true) ?: $lang);
            if ($normalized_lang === $language) {
                return $label;
            }
        }

        return $default_title;
    }

    /**
     * Create (or reuse) a temporary form token for the current cart checkout session.
     *
     * @return string|false Form token on success, false on failure.
     */
    public static function get_temporary_form_token()
    {
        if (! WC()->cart) {
            return false;
        }

        $currency = LyraApi::findCurrencyByAlphaCode(get_woocommerce_currency(), self::get_white_label());
        $amount = $currency->convertAmountToInteger(WC()->cart->get_total(false));
        $formAction = self::is_one_click_enabled() ? 'CUSTOMER_WALLET' : 'PAYMENT';

        $customer =  WC()->customer;
        $email = $customer->get_billing_email() ?? '';
        $cust_id = $customer->get_id() ?? '';

        if (self::contains_subscription()) {
            // Case of subscriptions with free trial.
            if ((WC()->cart->get_total(false) <= 0)) {
                return self::get_account_form_token(true);
            }

            $formAction = 'REGISTER_PAY';
        }

        $params = [
            'formAction' => $formAction,
            'amount' => $amount,
            'currency' => $currency->getAlpha3(),
            'customer' => [
                'reference' => $cust_id,
                'email' => $email,
                'billingDetails' => [
                    'firstName' => $customer->get_first_name(),
                    'lastName' => $customer->get_last_name(),
                    'address' => $customer->get_billing_address(),
                    'zipCode' => $customer->get_billing_postcode(),
                    'city' => $customer->get_billing_city(),
                    'state' => $customer->get_billing_state(),
                    'country' => $customer->get_billing_country()
                ],
                'shippingDetails' => self::get_shipping_data($customer),
                'shoppingCart' => [
                    'cartItemInfo' => self::get_shopping_cart(WC()->cart->get_cart(), $currency)
                ]
            ],
            'contrib' => self::get_contrib()
        ];

        // Do not refresh token if data didn't change.
        $last_token_data = get_transient('lyra_embedded_token_data');
        $last_token = get_transient('lyra_embedded_token');

        $token_data = base64_encode(serialize($params));
        if ($last_token && $last_token_data && ($last_token_data === $token_data)) {
            // Cart data did not change from last payment attempt, do not re-create payment token.
            WC_Gateway_LyraEmbedded::log_info("Cart data did not change since last token creation, use last created token for current cart for user: {$email}.");

            return $last_token;
        }

        // Get order ID for draft orders otherwise generate a temporary order_id.
        $order_id = WC()->session->get('store_api_draft_order') ?? LyraApi::generateTransId(time());
        $params['orderId'] = $order_id;

        $token = self::create_form_token($params, "user {$email}");
        if ($token) {
            set_transient('lyra_embedded_token_data', $token_data, 900);
            set_transient('lyra_embedded_token', $token, 900);
        }

        return $token;
    }

    /**
     * Create (or reuse) a customer account form token for wallet or card registration.
     *
     * @param  bool $force_register Force REGISTER form action instead of CUSTOMER_WALLET.
     * @return string|false Form token on success, false on failure.
     */
    public static function get_account_form_token($force_register = false)
    {
        $customer = WC()->customer;
        $reference = $customer->get_id();
        $email = $customer->get_billing_email();
        $currency = LyraApi::findCurrencyByAlphaCode(get_woocommerce_currency(), self::get_white_label());

        $params = [
            'formAction' => ($force_register || is_wc_endpoint_url('add-payment-method')) ? 'REGISTER' : 'CUSTOMER_WALLET',
            'customer' => [
                'email' => $email,
                'reference' => $reference,
                'billingDetails' => [
                    'firstName' => $customer->get_first_name(),
                    'lastName' => $customer->get_last_name(),
                    'address' => $customer->get_billing_address(),
                    'zipCode' => $customer->get_billing_postcode(),
                    'city' => $customer->get_billing_city(),
                    'state' => $customer->get_billing_state(),
                    'country' => $customer->get_billing_country()
                ]
            ],
            'contrib' => self::get_contrib(),
            'currency' => $currency->getAlpha3(),
            'metadata' => [
                'from_account' => true,
                'blog_id' => get_current_blog_id()
            ]
        ];

        // Do not refresh token if data didn't change.
        $last_token_data = get_transient('lyra_embedded_account_token_data_' . $reference);
        $last_token = get_transient('lyra_embedded_account_token_' . $reference);

        $token_data = base64_encode(serialize($params));
        if ($last_token && $last_token_data && ($last_token_data === $token_data)) {
            // User data did not change from last token creation, do not re-create account token.
            WC_Gateway_LyraEmbedded::log_info("User data did not change since last token creation, use last created account token for user: {$email}.");

            return $last_token;
        }

        $token = self::create_form_token($params, "user {$email}", 'CreateToken');
        if ($token) {
            set_transient('lyra_embedded_account_token_data_' . $reference, $token_data, 900);
            set_transient('lyra_embedded_account_token_' . $reference, $token, 900);
        }

        return $token;
    }

    /**
     * Create a form token for an existing WooCommerce order.
     *
     * @param  int|string $order_id WooCommerce order ID.
     * @return string|false Form token on success, false on failure.
     */
    public static function get_order_form_token($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            WC_Gateway_LyraEmbedded::log_error("Unable to create form token: order #{$order_id} could not be loaded.");

            return false;
        }

        // Get currency.
        $order_currency = $order->get_currency() ?? get_woocommerce_currency();
        $currency = LyraApi::findCurrencyByAlphaCode($order_currency, self::get_white_label());
        if ($currency == null) {
            WC_Gateway_LyraEmbedded::log_error("The store currency ({$order_currency}) is not supported by payment gateway.");

            wp_die(sprintf(__('The store currency (%s) is not supported by %s.', 'woo-lyra-embedded-payment'),
                get_woocommerce_currency(), self::get_default('GATEWAY_NAME')));
        }

        $amount = $currency->convertAmountToInteger($order->get_total());
        if (self::is_subscription_change_payment_gateway()) {
            $amount = 0;
        }

        $is_hpos_enabled = self::is_hpos_enabled();

        // Customer status.
        $billing_persontype = self::get_meta_data($order, '_billing_persontype', $is_hpos_enabled);
        if ($billing_persontype && $billing_persontype == '2') {
            $identity_code = '_billing_cnpj';
            $cust_status = 'COMPANY';
        } else {
            $identity_code = '_billing_cpf';
            $cust_status = 'PRIVATE';
        }

        $params = [
            'orderId' => $order_id,
            'currency' => $currency->getAlpha3(),
            'amount' => $amount,
            'customer' => [
                'email' => $order->get_billing_email(),
                'reference' => $order->get_user_id(),
                'billingDetails' => [
                    'language' => self::get_language(),
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'category' => $cust_status,
                    'address' => $order->get_billing_address_1() . ' '. $order->get_billing_address_2(),
                    'zipCode' => $order->get_billing_postcode(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'phoneNumber' => str_replace(['(', '-', ' ', ')'], '', $order->get_billing_phone()),
                    'country' => $order->get_billing_country(),
                    'identityCode' => self::get_meta_data($order, $identity_code, $is_hpos_enabled),
                    'streetNumber' => self::get_meta_data($order, '_billing_number', $is_hpos_enabled),
                    'district' => self::get_meta_data($order, '_billing_neighborhood', $is_hpos_enabled) ?? $order->get_billing_city()
                ],
                'shippingDetails' => self::get_order_shipping_data($order),
                'shoppingCart' => [
                    'cartItemInfo' => self::get_shopping_cart($order->get_items(), $currency)
                ]
            ],
            'contrib' => self::get_contrib(),
            'ipnTargetUrl' => add_query_arg('wc-api', 'WC_Gateway_Lyra_Embedded_Notify', network_home_url('/')),
            'metadata' => [
                'order_key' => $order->get_order_key(),
                'blog_id' => get_current_blog_id()
            ]
        ];

        $active_model = self::get_active_form_config();
        $ws_data = $active_model['shopConfig']['wsData'] ?? [];

        $params['formAction'] = $ws_data['formAction'] ?? 'PAYMENT';
        if (self::contains_subscription()) {
            $params['formAction'] = $amount > 0 ? 'REGISTER_PAY' : 'REGISTER';
            $params['metadata']['is_subscription'] = true;
        }

        $update_all_subscriptions_payment_method = isset($_POST['update_all_subscriptions_payment_method'])
            ? sanitize_text_field(wp_unslash($_POST['update_all_subscriptions_payment_method']))
            : '';
        if (! empty($update_all_subscriptions_payment_method)) {
            $params['metadata']['update_identifier_all'] = true;
        }

        // Set Number of attempts in case of rejected payment.
        $rest_attempts = $ws_data['transactionOptions']['cardOptions']['retry'] ?? null;
        if (($rest_attempts !== null) && is_numeric($rest_attempts)) {
            $params['transactionOptions']['cardOptions']['retry'] = $rest_attempts;
        }

        $webservice = $amount > 0 ? 'CreatePayment': 'CreateToken';

        return self::create_form_token($params, "order #{$order_id}", $webservice);
    }

    /**
     * Build shipping details payload expected by the payment API from customer data.
     *
     * @param  WC_Customer $customer WooCommerce customer instance.
     * @return array<string, string>
     */
    private static function get_shipping_data($customer): array
    {
        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
        $shipping_method = $chosen_shipping_methods[0] ?? null;
        $selected_shipping = $shipping_method ? substr($shipping_method, 0, strpos($shipping_method, ':')) : '';

        $shipping_type = 'PACKAGE_DELIVERY_COMPANY';
        $shipping_speed = 'STANDARD';
        $delivery_company_name = '';

        if (! $selected_shipping) { // There is no shipping method.
            $shipping_type = 'ETICKET';
            $shipping_speed = 'EXPRESS';
        } else {
            $delivery_company_name = preg_replace(self::$not_allowed_chars_regex, ' ', $selected_shipping);
        }

        return [
            'firstName' => $customer->get_shipping_first_name(),
            'lastName' => $customer->get_shipping_last_name(),
            'category' => 'PRIVATE',
            'address' => $customer->get_shipping_address_1(),
            'address2' => $customer->get_shipping_address_2(),
            'zipCode' => $customer->get_shipping_postcode(),
            'city' => $customer->get_shipping_city(),
            'state' => $customer->get_shipping_state(),
            'phoneNumber' => str_replace(['(', '-', ' ', ')'], '', $customer->get_shipping_phone()),
            'country' => $customer->get_shipping_country(),
            'deliveryCompanyName' => $delivery_company_name,
            'shippingMethod' => $shipping_type,
            'shippingSpeed' => $shipping_speed
        ];
    }

    /**
    * Build shipping details payload expected by the payment API from an order.

    *
    * @param  WC_Order $order WooCommerce order instance.
    * @return array<string, string>
    */
    private static function get_order_shipping_data($order): array
    {
        $shipping_method = $order->get_shipping_method();

        $shipping_type = 'PACKAGE_DELIVERY_COMPANY';
        $shipping_speed = 'STANDARD';
        $delivery_company_name = '';

        if (! $shipping_method) { // There is no shipping method.
            $shipping_type = 'ETICKET';
            $shipping_speed = 'EXPRESS';
        } else {
            $delivery_company_name = preg_replace(self::$not_allowed_chars_regex, ' ', $shipping_method);
        }

        return [
            'firstName' => $order->get_shipping_first_name(),
            'lastName' => $order->get_shipping_last_name(),
            'category' => 'PRIVATE',
            'address' => $order->get_shipping_address_1(),
            'address2' => $order->get_shipping_address_2(),
            'zipCode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'phoneNumber' => str_replace(['(', '-', ' ', ')'], '', $order->get_shipping_phone()),
            'country' => $order->get_shipping_country(),
            'deliveryCompanyName' => $delivery_company_name,
            'shippingMethod' => $shipping_type,
            'shippingSpeed' => $shipping_speed
        ];
    }

    /**
     * Build shopping cart item payload for the payment API from cart/order items.
     *
     * @param  array<int, mixed> $items    Cart or order items.
     * @param  object            $currency Currency helper instance used to convert amounts.
     * @return array<int, array<string, int|string>>
     */
    private static function get_shopping_cart($items, $currency): array
    {
        if (! is_array($items) || count($items) == 0) {
            return [];
        }

        $product_label_regex_not_allowed = '#[^A-Z0-9ÁÀÂÄÉÈÊËÍÌÎÏÓÒÔÖÚÙÛÜÇ ]#ui';

        $products = [];
        foreach ($items as $item) {
            if (isset($item['data'])) {
                $cart_product = $item['data'];
                $quantity = $item['quantity'];
            } else {
                $cart_product = $item->get_product();
                $quantity = $item->get_quantity();
            }

            if ($cart_product) {
                $name = $cart_product->get_name();
                $price = $cart_product->get_price();
                $rates = WC_Tax::get_rates($cart_product->get_tax_class());
                $taxes = WC_Tax::calc_tax($price, $rates, false);
                $tax_amount = array_sum($taxes);
                $rate = $price > 0 ? ($tax_amount / $price) * 100 : 0;

                $product = [
                    "productLabel" => substr(preg_replace($product_label_regex_not_allowed, ' ', $name), 0, 255),
                    "productRef" => $cart_product->get_id(),
                    "productQty" => $quantity,
                    "productAmount" => $currency->convertAmountToInteger($price),
                    "productVat" => number_format($rate, 4, '.', '')
                ];

                array_push($products, $product);
            }
        }

        return $products;
    }

    /**
     * Check whether one-click (customer wallet) payment is active.
     *
     * @return bool
     */
    public static function is_one_click_enabled(): bool
    {
        $active_model = self::get_active_form_config();
        $form_action = $active_model['shopConfig']['wsData']['formAction'] ?? null;

        return ($form_action && $form_action == 'CUSTOMER_WALLET');
    }

    /**
     * Determine whether the current checkout uses WooCommerce Checkout Block.
     *
     * @return bool
     */
    public static function has_checkout_block(): bool
    {
        global $post;

        // Checkout page for orders created from BO doesn't use checkout block.
        if (isset($_GET['pay_for_order'])) {
            return false;
        }

        if ($post) {
            return function_exists('has_block') && has_block('woocommerce/checkout', $post->ID);
        }

        return function_exists('has_block') && has_block('woocommerce/checkout', wc_get_page_id('checkout'));
    }

    /**
     * Check whether the current cart contains subscription products.
     *
     * @return bool
     */
    public static function contains_subscription(): bool
    {
        return class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription();
    }

    /**
     * Check whether the current request is a subscription payment method change flow.
     *
     * @return bool True when the request is currently changing the payment gateway for a subscription.
     */
    public static function is_subscription_change_payment_gateway(): bool
    {
        if (! class_exists('WC_Subscriptions_Product')) {
            return false;
        }

        return WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
    }

    /**
     * Check whether the current request contains the mandatory REST response fields.
     *
     * @return bool True when kr-hash, kr-hash-algorithm and kr-answer are all present.
     */
    public static function check_response_validity($data): bool
    {
        return isset($data['kr-hash']) && isset($data['kr-hash-algorithm']) && isset($data['kr-answer']);
    }

    /**
     * Verify the HMAC-SHA256 signature of a gateway response.
     *
     * @param array<string, string> $data  POST data containing kr-answer, kr-hash and kr-hash-algorithm.
     * @param bool                  $is_ipn True when validating a server-to-server IPN (uses private key),
     *                                     false for a browser return (uses SHA key).
     * @return bool True when the computed hash matches the received hash.
     */
    public static function check_hash($data, $is_ipn = true): bool
    {
        $supported_sign_algos = ['sha256_hmac'];

        // Check if the hash algorithm is supported.
        if (! in_array($data['kr-hash-algorithm'], $supported_sign_algos)) {
            WC_Gateway_LyraEmbedded::log_error('Hash algorithm is not supported: ' . $data['kr-hash-algorithm']);

            return false;
        }

        $kr_answer = str_replace('\/', '/', $data['kr-answer']);
        $key = $is_ipn ? self::get_private_key() : self::get_sha_key();

        $hash = hash_hmac('sha256', $kr_answer, $key);

        return ($hash === $data['kr-hash']);
    }

    /**
     * Determine whether WooCommerce HPOS (custom order tables) is enabled.
     *
     * @return bool True when HPOS is active, false otherwise.
     */
    public static function is_hpos_enabled(): bool
    {
        return class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Return the gateway transaction identifier stored on a WooCommerce refund.
     *
     * Refund objects do not expose get_transaction_id()/set_transaction_id(), so we
     * persist the gateway transaction UUID in refund meta for compatibility.
     *
     * @param WC_Order_Refund $refund Refund object.
     * @return string
     */
    public static function get_refund_transaction_id($refund): string
    {
        if (! $refund || ! is_callable([$refund, 'get_meta'])) {
            return '';
        }

        $transaction_id = $refund->get_meta('_transaction_id', true);

        return is_string($transaction_id) ? $transaction_id : '';
    }

    /**
     * Persist the gateway transaction identifier on a WooCommerce refund.
     *
     * @param WC_Order_Refund $refund         Refund object.
     * @param string          $transaction_id Gateway transaction UUID.
     * @return void
     */
    public static function set_refund_transaction_id($refund, $transaction_id)
    {
        if (! $refund || ! is_callable([$refund, 'update_meta_data'])) {
            return;
        }

        $is_hpos_enabled = self::is_hpos_enabled();

        self::update_meta_data($refund, '_transaction_id', (string) $transaction_id, $is_hpos_enabled);
        if ($is_hpos_enabled) {
            $refund->save();
        }
    }

    /**
     * Extract a normalised payment result array from a gateway answer payload.
     * Handles both single-transaction and multi-payment responses.
     *
     * @param array<string, mixed> $answer Decoded gateway answer (from kr-answer).
     * @return array<string, mixed> Normalised payment result including uuid, status, amount,
     *                              currency, card details, and optionally a paymentSequence.
     */
    public static function get_payment_result($answer): array
    {
        $multi_payment = false;

        if (isset($answer['transactions']) && is_array($answer['transactions']) && ! empty($answer['transactions'])) {
            $transactions = $answer['transactions'];
            $transaction = $transactions[0];
            if (count($transactions) > 1) {
                $sequence_type = $transaction['transactionDetails']['cardDetails']['sequenceType'] ?? '';
                $multi_payment = ($sequence_type == 'MULTI_PAYMENT_MEAN');
            }
        } else {
            $transaction = $answer;
        }

        $result = self::get_transaction_data($transaction);

        if ($multi_payment) {
            $payment_seq = [
                'transactions' => []
            ];

            foreach ($transactions as $trs) {
                $payment_seq['transactions'][] = self::get_transaction_data($trs);
            }

            $result['cardBrand'] = 'MULTI';
            $result['paymentSequence'] = json_encode($payment_seq);
            $result['amount'] = $answer['orderDetails']['orderTotalAmount'];
        }

        $result['orderStatus'] = isset($answer['orderStatus']) ? $answer['orderStatus'] : '';

        return $result;
    }

    /**
     * Check whether a payment status represents an abandoned or expired payment.
     *
     * @param string $status Gateway order or transaction status.
     * @return bool
     */
    public static function is_abandoned_payment($status): bool
    {
        return in_array($status, self::$abandoned_statuses);
    }

    /**
     * Builds a user context string sent with API operations.
     *
     * @return string
     */
    public static function get_user_info(): string
    {
        $comment_text = 'WooCommerce user: ' . get_option('admin_email');
        $comment_text .= ' ; IP address: ' . WC_Geolocation::get_ip_address();

        return $comment_text;
    }

    public static function get_meta_data($order, $meta_key, $is_hpos_enabled = null)
    {
        if ($is_hpos_enabled == null) {
            $is_hpos_enabled = self::is_hpos_enabled();
        }

        if ($is_hpos_enabled) {
            return $order->get_meta($meta_key, true);
        }

        $order_id = $order->get_id();

        return get_post_meta($order_id, $meta_key, true);
    }

    public static function delete_meta_data($order, $meta_key, $is_hpos_enabled): void
    {
        if ($is_hpos_enabled) {
            $order->delete_meta_data($meta_key);
        } else {
            $order_id = $order->get_id();
            delete_post_meta($order_id, $meta_key);
        }
    }

    public static function update_meta_data($order, $meta_key, $meta_value, $is_hpos_enabled): void
    {
        if ($is_hpos_enabled) {
            $order->update_meta_data($meta_key, $meta_value);
        } else {
            $order_id = $order->get_id();
            update_post_meta($order_id, $meta_key, $meta_value);
        }
    }

    /**
     * Extract and normalise transaction data from a single gateway transaction array.
     *
     * @param array<string, mixed> $transaction A single transaction entry from the gateway response.
     * @return array<string, mixed> Flat array with keys: uuid, status, amount, currency, operationType,
     *                              effectiveAmount, effectiveCurrency, changeRate, sequenceNumber,
     *                              transId, cardBrand, cardNumber, expiryMonth, expiryYear, cardHolder,
     *                              and optionally wallet.
     */
    private static function get_transaction_data($transaction): array
    {
        $result = [
            'uuid' => $transaction['uuid'] ?? '',
            'status' => $transaction['detailedStatus'] ?? '',
            'amount' => $transaction['amount'] ?? '',
            'currency' => $transaction['currency'] ?? '',
            'operationType' => $transaction['operationType'] ?? '',
            'metadata' => $transaction['metadata'] ?? [],
            'paymentMethodToken' => $transaction['paymentMethodToken'] ?? ''
        ];

        if (isset($transaction['transactionDetails'])) {
            $transaction_details = $transaction['transactionDetails'];
            $card_details = $transaction_details['cardDetails'] ?? [];
            $authentication_response = $card_details['authenticationResponse']['value'] ?? [];
            $authentication_result_data = $card_details['threeDSResponse']['authenticationResultData'] ?? [];

            $result['effectiveAmount'] = $transaction_details['effectiveAmount'] ?? '';
            $result['effectiveCurrency'] = $transaction_details['effectiveCurrency'] ?? '';
            $result['changeRate'] = $transaction_details['changeRate'] ?? '';
            $result['sequenceNumber'] = $transaction_details['sequenceNumber'] ?? '1';
            $result['transId'] = $card_details['legacyTransId'] ?? '';
            $result['cardBrand'] = $card_details['effectiveBrand'] ?? '';
            $result['cardNumber'] = $card_details['pan'] ?? '';
            $result['expiryMonth'] = $card_details['expiryMonth'] ?? '';
            $result['expiryYear'] = $card_details['expiryYear'] ?? '';
            $result['cardHolder'] = $card_details['cardHolderName'] ?? '';
            $result['authenticationStatus'] = $authentication_response['status'] ?? '';
            $result['authenticationType'] = $authentication_response['authenticationType'] ?? '';
            $result['cavv'] = $authentication_result_data['cavv'] ?? '';

            if (isset($transaction_details['wallet'])) {
                $result['wallet'] = $transaction_details['wallet'];
            }
        }

        return $result;
    }

    /**
    * Call the payment REST API to create a form token and log outcomes.
    *
    * @param  array<string, mixed> $params   Request payload sent to the API.
    * @param  string $metadata   Context used in logs (user/order info).
    * @param  string $webservice Charge API action name.
    * @return string|false Form token on success, false on failure.
    */
    private static function create_form_token($params, $metadata, $webservice = 'CreatePayment')
    {
        $shop_id = self::get_shop_id();
        $private_key = self::get_private_key();

        if (empty($shop_id) || empty($private_key)) {
            WC_Gateway_LyraEmbedded::log_error("Unable to create form token for {$metadata}: missing shop ID or private key in active Lyra configuration.");

            return false;
        }

        try {
            $client = new LyraRest(
                self::get_rest_url(),
                $shop_id,
                $private_key
            );

            $data = json_encode($params);

            WC_Gateway_LyraEmbedded::log_info("Creating form token for {$metadata} with params: {$data}");

            if (! $webservice) {
                $webservice = ($params['amount'] > 0) ? 'CreatePayment': 'CreateToken';
            }

            $result = $client->post('V4/Charge/' . $webservice, $data);

            if ($result['status'] != 'SUCCESS') {
                WC_Gateway_LyraEmbedded::log_error("Error while creating form token for {$metadata}: " . $result['answer']['errorMessage']
                    . ' (' . $result['answer']['errorCode'] . ').');

                if (isset($result['answer']['detailedErrorMessage']) && ! empty($result['answer']['detailedErrorMessage'])) {
                    WC_Gateway_LyraEmbedded::log_error('Detailed message: ' . $result['answer']['detailedErrorMessage']
                        . ' (' . $result['answer']['detailedErrorCode'] . ').');
                }

                return false;
            }

            WC_Gateway_LyraEmbedded::log_info("Form token created successfully for {$metadata}.");

            return $result['answer']['formToken'];
        } catch (Exception $e) {
            WC_Gateway_LyraEmbedded::log_error($e->getMessage());
        }

        return false;
    }

    /**
     * Return the configured API key for the active mode.
     *
     * @param  string $type Key type: `public` or `private`.
     * @return string
     */
    private static function get_key(string $type): string
    {
        $active_model = self::get_active_form_config();
        $mode = self::get_mode();

        return $active_model['shopConfig']['keys'][$mode][$type . 'Key'] ?? '';
    }
}
