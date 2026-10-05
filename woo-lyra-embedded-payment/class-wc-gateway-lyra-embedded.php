<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Lyranetwork\LyraEmbedded\Sdk\Form\Api as LyraApi;
use Lyranetwork\LyraEmbedded\Sdk\Refund\Api as LyraRefundApi;
use Lyranetwork\LyraEmbedded\Sdk\Refund\OrderInfo as LyraOrderInfo;

class WC_Gateway_LyraEmbedded extends WC_Payment_Gateway
{
    /**
     * Admin page slug used to hook admin head actions.
     *
     * @var string
     */
    protected $admin_page;

    /**
     * Full URL to the gateway settings admin page.
     *
     * @var string
     */
    protected $admin_link;

    /**
     * Constructor. Initialises gateway properties, form fields, and WordPress hooks.
     */
    public function __construct()
    {
        $this->id = 'lyra_embedded';
        $this->method_title = sprintf(__('%s embedded payment', 'woo-lyra-embedded-payment'), LyraEmbeddedTools::get_default('GATEWAY_NAME'));
        $this->title = LyraEmbeddedTools::get_title();

        // Display payment fields on checkout page.
        $this->has_fields = true;

        $this->enabled = 'yes';

        // Init common vars.
        $this->lyra_embedded_init();

        // Load the form fields.
        $this->init_form_fields();

        $this->supports = self::get_supported_features();

        if ($this->is_admin_section_loaded()) {
            // Adding style to admin form action.
            add_action('admin_head-woocommerce_page_' . $this->admin_page, [$this, 'lyra_embedded_admin_head_style']);

            // Adding JS to admin form action.
            add_action('admin_head-woocommerce_page_' . $this->admin_page, [$this, 'lyra_embedded_admin_head_script']);
        }

        add_action('woocommerce_api_wc_gateway_lyra_embedded_widget_request', [$this, 'handle_widget_request']);

        // Adding JS to load REST libs.
        add_action('wp_head', [$this, 'lyra_embedded_rest_head_script']);

        // Rest payment generate temporary token.
        add_action('woocommerce_api_wc_gateway_lyra_embedded_form_token', [$this, 'get_temporary_token']);

        // Display buyer wallet on "Payment methods" menu.
        add_action('woocommerce_after_account_payment_methods', [$this, 'payment_fields'], 10, 2);

        // Manage return to shop response.
        add_action('woocommerce_api_wc_gateway_lyra_embedded_return', [$this, 'process_return_response']);

        // Manage IPN response.
        add_action('woocommerce_api_wc_gateway_lyra_embedded_notify', [$this, 'process_notify_response']);

        // Display payment identifier in subscription metadata.
        add_filter('woocommerce_subscription_payment_meta', [$this, 'get_subscription_payment_meta'], 10, 2);

        // Display online refund result message.
        add_action('woocommerce_admin_order_totals_after_discount', [$this, 'lyra_embedded_display_refund_result'], 10, 1);

        // Process subscription renewal through silent payment.
        add_action('woocommerce_scheduled_subscription_payment_lyra_embedded', [$this, 'process_subscription_payment'], 10, 2);
    }

    /**
     * Return the WooCommerce features supported by this gateway.
     *
     * @return array<int, string>
     */
    public static function get_supported_features()
    {
        return [
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_payment_method_change',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer',
            'subscription_suspension',
            'subscription_reactivation',
            'multiple_subscriptions',
            'subscription_payment_method_change_admin',
            'subscription_payment_method_delayed_change',
            'add_payment_method',
            'refunds'
        ];
    }

    /**
     * Define admin fields for the gateway.
     */
    public function init_form_fields()
    {
        $widget_text = __('Configure the payment form in real time with our intuitive tool. Adjust colors, fonts and fields via a visual interface, and immediately preview the result on all devices. Simple and efficient, it speeds up validation and deployment while guaranteeing an optimal user experience.', 'woo-lyra-embedded-payment');

        $this->form_fields = [
            'widget' => [
                'title' => __('Payment form and payment methods', 'woo-lyra-embedded-payment'),
                'type' => 'cms_widget',
                'description' => $widget_text,
            ],
            'url_check' => [
                'title' => __('REST API Notification URL', 'woo-lyra-embedded-payment'),
                'type' => 'text',
                'description' => '<span class="url">' . add_query_arg('wc-api', 'WC_Gateway_Lyra_Embedded_Notify', network_home_url('/')) . '</span><br />' .
                                 '<div class="lyra-info-box">
                                     <span>&#9432;</span>
                                     <span>'
                                         . sprintf(__('URL to copy into your %s Back Office > Settings > Notification rules. In multistore mode, notification URL is the same for all the stores.', 'woo-lyra-embedded-payment'), LyraEmbeddedTools::get_default('BACKOFFICE_NAME')) .
                                     '</span>
                                 </div>',
                'css' => 'display: none;'
            ],
            'plugin_credits' => [
                'title' => __('Plugin Credits', 'woo-lyra-embedded-payment'),
                'type' => 'plugin_credits'
            ]
        ];
    }

    /**
     * Outputs inline CSS for the gateway admin settings page.
     *
     * @return void
     */
    public function lyra_embedded_admin_head_style()
    {
        wp_register_style('lyra-embedded-settings', WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/css/settings.css', [], LyraEmbeddedTools::get_default('PLUGIN_VERSION'));
        wp_enqueue_style('lyra-embedded-settings');
    }

    /**
     * Outputs the Epsilon widget JS/CSS assets and the inline script that drives the
     * payment-form configuration widget on the admin settings page.
     *
     * @return void
     */
    public function lyra_embedded_admin_head_script()
    {
        // Avoid multiple loadings.
        if (wp_script_is('lyra-embedded-widget', 'registered')) {
            return;
        }

        wp_register_script('lyra-embedded-widget', WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/js/widget.js', ['jquery'], LyraEmbeddedTools::get_default('PLUGIN_VERSION'), true);

        wp_localize_script('lyra-embedded-widget', 'lyraEmbeddedData', [
            'epsilonUrl' => LyraEmbeddedTools::get_epsilon_url(),
            'locale' => LyraEmbeddedTools::get_locale(),
            'widgetRequestUrl' => add_query_arg('wc-api', 'WC_Gateway_Lyra_Embedded_Widget_Request', home_url('/')),
            'widgetRequestNonce' => wp_create_nonce('lyra_embedded_widget_request'),
            'isWhiteLabelAll' => LyraEmbeddedTools::$plugin_features['whitelabelall'] ?? false
        ]);

        wp_enqueue_script('lyra-embedded-widget');
    }

    /**
     * Handles AJAX-style widget requests sent from the admin settings page.
     *
     * Supported actions: getWidgetToken, saveModels, getModels.
     * Requires the `manage_woocommerce` capability and a valid nonce.
     *
     * @return void Sends a JSON response and terminates.
     */
    public function handle_widget_request()
    {
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);

        if (! is_array($data)) {
            $data = wp_unslash($_POST);
        }

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json(['result' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $nonce = isset($data['nonce']) ? sanitize_text_field($data['nonce']) : '';
        if (! wp_verify_nonce($nonce, 'lyra_embedded_widget_request')) {
            wp_send_json(['result' => 'error', 'message' => 'Invalid nonce.'], 403);
        }

        $action = isset($data['action']) ? sanitize_text_field($data['action']) : null;
        if (empty($action)) {
            wp_send_json(['result' => 'error']);
        }

        switch ($action) {
            case 'getWidgetToken':
                $token = isset($data['data']) ? LyraEmbeddedTools::get_widget_token($data['data']) : '';

                if ($token) {
                    $result = ['result' => 'success', 'formToken' => $token];
                } else {
                    $result = ['result' => 'error'];
                }

                break;

            case 'saveModels':
                if (! isset($data['data'])) {
                    $result = ['result' => 'error'];

                    break;
                }

                update_option('woocommerce_lyra_embedded_settings', $data['data']);
                $result = ['result' => 'success'];

                break;

            case 'getModels':
                $models = get_option('woocommerce_lyra_embedded_settings', null);
                $result = ['result' => 'success', 'models' => $models];

                break;

            default:
                $result = ['result' => 'error'];

                break;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json(['result' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }

        wp_send_json($result);
    }

    /**
     * Registers and enqueues frontend JS/CSS assets required by the embedded payment form.
     *
     * @return void
     */
    public function lyra_embedded_rest_head_script()
    {
        if (! $this->is_available()) {
            return;
        }

        // Only load on necessary pages.
        if (! is_checkout() && ! is_checkout_pay_page() && ! is_add_payment_method_page()) {
            return;
        }

        // Avoid multiple loadings.
        if (wp_script_is('lyra-embedded-header', 'registered')) {
            return;
        }

        $version = LyraEmbeddedTools::get_default('PLUGIN_VERSION');

        wp_register_script('lyra-embedded-header', WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/js/payment_header.js', ['jquery'], $version, true);

        wp_localize_script('lyra-embedded-header', 'lyraEmbeddedData', [
            'restTheme' => LyraEmbeddedTools::get_theme(),
            'staticUrl' => LyraEmbeddedTools::get_static_url(),
            'publicKey' => LyraEmbeddedTools::get_public_key(),
            'returnUrl' => add_query_arg('wc-api', 'WC_Gateway_Lyra_Embedded_Return', home_url('/')),
            'language' => LyraEmbeddedTools::get_language()
        ]);

        wp_enqueue_script('lyra-embedded-header');

        // Load utils script.
        if (! wp_script_is('lyra-embedded-utils', 'registered')) {
            wp_register_script('lyra-embedded-utils', WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/js/utils.js', ['jquery'], $version, true);
        }

        wp_enqueue_script('lyra-embedded-utils');

        if (! wp_script_is('lyra-embedded-payment-fields', 'registered')) {
            wp_register_script(
                'lyra-embedded-payment-fields',
                WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/js/payment_fields.js',
                ['jquery', 'lyra-embedded-utils'],
                $version,
                true
            );
        }

        wp_register_style('lyra-embedded-style', WC_LYRA_EMBEDDED_PLUGIN_URL . 'assets/css/style.css', [], $version);
        wp_enqueue_style('lyra-embedded-style');
    }

    /**
     * REST API endpoint handler: returns a temporary form token as JSON and terminates.
     *
     * Registered via the `woocommerce_api_wc_gateway_lyra_embedded_form_token` hook.
     * Do NOT call this method directly from PHP — use
     * LyraEmbeddedTools::get_temporary_form_token() instead.
     *
     * @return void Sends a JSON response and terminates.
     */
    public function get_temporary_token()
    {
        $token = LyraEmbeddedTools::get_temporary_form_token();
        if (! $token) {
            self::log_error('Unable to get temporary form token.');

            self::lyra_json_encode(['result' => 'error']);
        }

        $active_form_config = LyraEmbeddedTools::get_active_form_config();
        $form_config = $active_form_config['formConfig'] ?? null;
        if (! is_array($form_config)) {
            self::log_error('Unable to return temporary token: missing active form configuration.');

            self::lyra_json_encode(['result' => 'error']);
        }

        $form_config['formToken'] = $token;

        self::lyra_json_encode(['result' => 'success', 'formConfig' => $form_config]);
    }

    /**
     * Generates the HTML for the "Plugin Credits" admin field.
     *
     * @param string $key  Field key.
     * @param array  $data Field configuration data.
     * @return string Generated HTML.
     */
    public function generate_plugin_credits_html($key, $data)
    {
        // Get documentation links.
        $languages = [
            'fr' => 'Français',
            'en' => 'English',
            'es' => 'Español',
            'pt' => 'Português'
            // Complete when other languages are managed.
        ];

        $documentation_links = [];
        $doc_uris = LyraApi::getOnlineDocUri();
        foreach ($doc_uris as $lang => $doc_uri) {
            $label = $languages[$lang] ?? $lang;
            $documentation_links[] = [
                'label' => $label,
                'url' => $doc_uri . 'woocommerce_embedded/sitemap.html'
            ];
        }

        $data['title'] = $data['title'] ?? '';
        $data['disabled'] = empty($data['disabled']) ? false : true;
        $data['class'] = $data['class'] ?? '';
        $data['css'] = $data['css'] ?? '';
        $data['placeholder'] = $data['placeholder'] ?? '';
        $data['type'] = $data['type'] ?? 'text';
        $data['desc_tip'] = $data['desc_tip'] ?? false;
        $data['description'] = $data['description'] ?? '';

        $html = '<tr valign="top">' . "\n";
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<label for="' . esc_attr($this->plugin_id . $this->id . '_' . $key) . '">' . wp_kses_post($data['title']) . '</label>';
        $html .= '</th>' . "\n";
        $html .= '<td class="forminp">' . "\n";
        $html .= '<div>
            <div style="margin-bottom: 5px;">'
                . __('Developed by', 'woo-lyra-embedded-payment') . ' <a href="https://www.lyra.com/" target="_blank" style="text-decoration: underline; color: #333; font-weight: bold;">Lyra Network</a>
            </div>
            <div style="margin-bottom: 5px;">'
                . __('Contact', 'woo-lyra-embedded-payment') . ' ' . LyraApi::formatSupportEmails(LyraEmbeddedTools::get_default('SUPPORT_EMAIL'), __('client support', 'woo-lyra-embedded-payment')) .
            '</div>
            <div style="margin-bottom: 10px;">
                ' . __('Module version', 'woo-lyra-embedded-payment') . ' <b>' . LyraEmbeddedTools::get_default('PLUGIN_VERSION') . '</b>'.
            '</div>
            <div class="lyra-info-box">
                <span>&#9432;</span>
                <span>'
                    . __('Access to the module configuration documentation:', 'woo-lyra-embedded-payment');

        foreach ($documentation_links as $link) {
            $html .= '<a href="' . ($link['url']) . '" target="_blank" style="margin-left: 10px; color: #007bdb; text-decoration: underline; font-weight: bold;">'
                . $link['label'] . '</a>';
        }

        $html .= '</span>
            </div>
        </div>';

        $html .= '</td>' . "\n";
        $html .= '</tr>' . "\n";

        return $html;
    }

    /**
     * Generates the HTML for the "CMS Widget" admin field, including the preview image
     * and the button that opens the Epsilon configuration widget.
     *
     * @param string $key  Field key.
     * @param array  $data Field configuration data.
     * @return string Generated HTML.
     */
    public function generate_cms_widget_html($key, $data)
    {
        $data['title'] = $data['title'] ?? '';
        $data['disabled'] = empty($data['disabled']) ? false : true;
        $data['class'] = $data['class'] ?? '';
        $data['css'] = $data['css'] ?? '';
        $data['placeholder'] = $data['placeholder'] ?? '';
        $data['type'] = $data['type'] ?? 'text';
        $data['desc_tip'] = $data['desc_tip'] ?? false;
        $data['description'] = $data['description'] ?? '';

        $html = '<tr valign="top">' . "\n";
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<label for="' . esc_attr($this->plugin_id . $this->id . '_' . $key) . '">' . wp_kses_post($data['title']) . '</label>';
        $html .= '</th>' . "\n";
        $html .= '<td class="forminp">' . "\n";

        $image_url = LyraEmbeddedTools::get_epsilon_url() . 'widget-preview.webp';

        $html .= '<div class="lyra-widget-div">
            <div class="lyra-widget-section">
                <div class="lyra-widget-content">
                  <span>' . $data['description'] . '</span>
                  <button type="button" class="components-button wp-menu-image dashicons-before dashicons-admin-appearance" id="widget_button" style="background-color: #007bdb; border-color: #007bdb; color: #fff;">
                    <span>' . __('Configure the payment form', 'woo-lyra-embedded-payment') . '</span>
                  </button>
                </div>
                <div class="lyra-widget-image-wrap">
                    <img src="' .  $image_url . '" class="lyra-widget-img"/>
                </div>
            </div>
        </div>';

        $html .= '</td>' . "\n";
        $html .= '</tr>' . "\n";

        return $html;
    }

    /**
     * Process the payment and return the result.
     *
     * @param  int $order_id WooCommerce order ID.
     * @return array<string, string>
     **/
    public function process_payment($order_id)
    {
        self::log_info("Received paymet request for order #{$order_id} during checkout processing.");

        $is_hpos_enabled = LyraEmbeddedTools::is_hpos_enabled();

        $order = wc_get_order($order_id);
        if ($order) {
            $cached_form_config = LyraEmbeddedTools::get_meta_data($order, '_lyra_embedded_form_config', $is_hpos_enabled);
            $cached_form_config_created_at = (int) LyraEmbeddedTools::get_meta_data($order, '_lyra_embedded_form_config_created_at', $is_hpos_enabled);

            if ($cached_form_config && $cached_form_config_created_at && (time() - $cached_form_config_created_at) < 900) {
                self::log_info("Reusing cached form config for repeated checkout processing on order #{$order_id}.");

                return ['result' => 'success', 'formConfig' => $cached_form_config];
            }
        }

        if ($token = LyraEmbeddedTools::get_order_form_token($order_id)) {
            $active_form_config = LyraEmbeddedTools::get_active_form_config();
            $form_config = $active_form_config['formConfig'] ?? null;
            if (! is_array($form_config)) {
                self::log_error("Unable to load active form configuration for order #{$order_id} during checkout processing.");

                return ['result' => 'error'];
            }
        }

        $active_form_config = LyraEmbeddedTools::get_active_form_config();
        $form_config = $active_form_config['formConfig'] ?? null;
        if (! is_array($form_config)) {
            self::log_error("Unable to load active form configuration for order #{$order_id} during checkout processing.");

            return ['result' => 'error'];
        }

        $form_config['formToken'] = $token;
        $encoded_form_config = wp_json_encode($form_config);

        if ($order) {
            LyraEmbeddedTools::update_meta_data($order, '_lyra_embedded_form_config', $encoded_form_config, $is_hpos_enabled);
            LyraEmbeddedTools::update_meta_data($order, '_lyra_embedded_form_config_created_at', time(), $is_hpos_enabled);

            if ($is_hpos_enabled) {
                $order->save();
            }
        }

        return ['result' => 'success', 'formConfig' => $encoded_form_config];
    }

    /**
     * Remove the cached checkout form configuration from the order.
     *
     * @param WC_Order $order Related WooCommerce order.
     * @return void
     */
    private static function lyra_embedded_clear_cached_form_config($order): void
    {
        if (! $order) {
            return;
        }

        $is_hpos_enabled = LyraEmbeddedTools::is_hpos_enabled();

        if (! LyraEmbeddedTools::get_meta_data($order, '_lyra_embedded_form_config', $is_hpos_enabled)
            && ! LyraEmbeddedTools::get_meta_data($order, '_lyra_embedded_form_config_created_at', $is_hpos_enabled)) {
            return;
        }

        LyraEmbeddedTools::delete_meta_data($order, '_lyra_embedded_form_config', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, '_lyra_embedded_form_config_created_at', $is_hpos_enabled);

        if ($is_hpos_enabled) {
            $order->save();
        }

        if (function_exists("wcs_get_subscriptions_for_order") && $subscriptions = wcs_get_subscriptions_for_order($order)) {
            foreach ($subscriptions as $subscription) {
                if (! LyraEmbeddedTools::get_meta_data($subscription, '_lyra_embedded_form_config', $is_hpos_enabled)
                    && ! LyraEmbeddedTools::get_meta_data($subscription, '_lyra_embedded_form_config_created_at', $is_hpos_enabled)) {
                    continue;
                }

                LyraEmbeddedTools::delete_meta_data($subscription, '_lyra_embedded_form_config', $is_hpos_enabled);
                LyraEmbeddedTools::delete_meta_data($subscription, '_lyra_embedded_form_config_created_at', $is_hpos_enabled);

                if ($is_hpos_enabled) {
                    $subscription->save();
                }
            }
        }
    }

    /**
     * Render payment fields for checkout, order-pay, and add-payment-method contexts.
     *
     * @return void
     */
    public function payment_fields()
    {
        if (! $this->is_available()) {
            return;
        }

        if (! is_checkout() && ! is_checkout_pay_page() && ! is_add_payment_method_page()) {
            return;
        }

        $smartform_attributes = 'kr-single-payment-button';

        // Order created from WordPress BO.
        if ($order_id = get_query_var('order-pay')) {
            $form_token = LyraEmbeddedTools::get_order_form_token($order_id);
        } elseif (is_add_payment_method_page()) {
            $form_token = LyraEmbeddedTools::get_account_form_token();
            $smartform_attributes = 'kr-no-card-logo-header';
            $smartform_attributes .= is_wc_endpoint_url('add-payment-method') ? ' kr-single-payment-button' : '';
        } else {
            $form_token = LyraEmbeddedTools::get_temporary_form_token();
        }

        if (! $form_token) {
            return;
        }

        $active_form_config = LyraEmbeddedTools::get_active_form_config();
        $form_config = $active_form_config['formConfig'] ?? null;
        if (! is_array($form_config)) {
            self::log_error('Unable to render payment fields: missing active form configuration.');

            return;
        }

        $form_config['formToken'] = $form_token;
        wp_enqueue_script('lyra-embedded-payment-fields');

        ob_start();

        wc_get_template(
            'payment_fields.php',
            [
                'smartform_attributes' => $smartform_attributes,
                'form_config' => $form_config
            ],
            '',
            WC_LYRA_EMBEDDED_PLUGIN_PATH . '/templates/'
        );

        echo ob_get_clean();
    }

    /**
     * Check if this gateway is enabled and available for the current cart.
     *
     * @return bool
     */
    public function is_available()
    {
        if (! parent::is_available()) {
            return false;
        }

        if (is_admin()) {
            return true;
        }

        if (empty(LyraEmbeddedTools::get_active_form_config())) {
            return false;
        }

        if (is_add_payment_method_page()) {
            return LyraEmbeddedTools::is_one_click_enabled();
        }

        return true;
    }

    /**
     * Handle the browser return flow after payment completion.
     *
     * Validates the gateway response, loads the related order, updates the
     * WooCommerce order state when needed, and redirects the customer to the
     * appropriate confirmation or error page.
     *
     * @return void
     */
    public function process_return_response()
    {
        self::log_info("User return to shop process starts.");

        $ip_addr = "[IP: {" . WC_Geolocation::get_ip_address() . "}]";

        $data = (array) stripslashes_deep($_POST);
        if (! LyraEmbeddedTools::check_response_validity($data)) {
            self::log_error("{$ip_addr} Invalid return request received, redirect to cart page. Content: " . print_r($data, true));

            $this->lyra_embedded_redirect_error();
        }

        // Check the authenticity of the request.
        if (! LyraEmbeddedTools::check_hash($data, false)) {
            self::log_error("{$ip_addr} Authentication failed: received invalid response with parameters: " . print_r($data, true));

            $this->lyra_embedded_redirect_error();
        }

        $answer = json_decode($data['kr-answer'], true);
        if (! is_array($answer)) {
            self::log_error("{$ip_addr} Invalid return request received, redirect to cart page. Content of kr-answer: " . $data['kr-answer']);

            $this->lyra_embedded_redirect_error();
        }

        $transactions = $answer['transactions'] ?? [];
        if (! is_array($transactions) || empty ($transactions)) {
            self::log_error("{$ip_addr} No transactions found in payment response.");

            $this->lyra_embedded_redirect_error();
        }

        $metadata = $transactions[0]['metadata'] ?? null;
        if ($metadata && isset($metadata['from_account'])) {
            if (isset($transactions[0]['paymentMethodToken'])) {
                wc_add_notice(__('Payment method successfully added.', 'woocommerce'));
            } else {
                wc_add_notice(__('Unable to add payment method to your account.', 'woocommerce'), 'error');
            }

            $cust_id = get_current_user_id();
            delete_transient('lyra_embedded_account_token_data_' . $cust_id);
            delete_transient('lyra_embedded_account_token_' . $cust_id);

            wp_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit();
        }

        $order_id = $answer['orderDetails']['orderId'] ?? null;
        if (! $order_id) {
            self::log_error('No order ID was received in response.');

            $this->lyra_embedded_redirect_error();
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            self::log_error("{$ip_addr} Order #$order_id not found.");

            $this->lyra_embedded_redirect_error();
        }

        if (($answer['orderCycle'] ?? '') === 'CLOSED') {
            self::lyra_embedded_clear_cached_form_config($order);
        }

        $payment_result = LyraEmbeddedTools::get_payment_result($answer);
        $trans_uuid = $payment_result['uuid'];
        $trans_status = $payment_result['status'];

        self::log_info("{$ip_addr} Payment status for transaction with UUID #$trans_uuid is {$trans_status}.");

        $current_status = $order->get_status();
        self::log_info("{$ip_addr} The current state for order #$order_id is {$current_status}.");

        $not_processed = in_array($current_status, ['pending', 'checkout-draft']);
        if (LyraApi::isAcceptedPayment($trans_status)) {
            $msg = (LyraApi::isPendingPayment($trans_status)) ? 'Pending payment' : 'Accepted payment';
            self::log_info("{$ip_addr} {$msg} for order #$order_id.");
            if ($not_processed) {
                self::log_info("{$ip_addr} Order #$order_id not processed yet, let's update order.");

                // Add order note.
                self::lyra_embedded_add_order_note($payment_result, $order);

                // Add order note metadata.
                self::lyra_embedded_update_order_meta($payment_result, $order);

                $this->lyra_embedded_save_order($order, $trans_status);

                if ($metadata && isset($metadata['is_subscription']) && isset($payment_result['paymentMethodToken'])) {
                    $this->lyra_embedded_process_subscription($order, $payment_result);
                }
            }

            // Redirect to order confirmation page.
            wp_redirect($this->get_return_url($order));
            exit;
        } else {
            self::log_info("{$ip_addr} Payment failed for order #$order_id.");
            if ($not_processed) {
                // Add order note.
                self::lyra_embedded_add_order_note($payment_result, $order);

                // Add order note metadata.
                self::lyra_embedded_update_order_meta($payment_result, $order);

                $this->lyra_embedded_save_order($order, $trans_status);
            }

            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Handle the server-to-server IPN notification from the payment gateway.
     *
     * Validates the notification payload, switches to the correct blog in
     * multisite mode, verifies the signature, updates the order status, and
     * returns the gateway acknowledgement.
     *
     * @return void
     */
    public function process_notify_response()
    {
        $data = (array) stripslashes_deep($_POST);

        // Ignore IPN response on webservice operations.
        if (isset($data['kr-src']) && ($data['kr-src'] === 'WEBSERVICES')) {
            self::log_info('Notification from webservice operation, IPN will be ignored.');

            die('OK-Notification from webservice operation, IPN ignored.');
        }

        $ipn_src = $data['kr-src'] ?? '';
        $ipn_metadata = "[IPN call" . ($ipn_src ? " | src=" . $ipn_src : "") . "][IP: {" . WC_Geolocation::get_ip_address() . "}]";

        if (! LyraEmbeddedTools::check_response_validity($data)) {
            self::log_error("{$ipn_metadata} Invalid IPN response received. Content: " . print_r($data, true));

            die('KO-Invalid IPN response received.');
        }

        // Parse the answer first to extract blog_id for multisite support.
        $answer = json_decode($data['kr-answer'], true);

        if (! is_array($answer)) {
            self::log_error("{$ipn_metadata} Invalid IPN response received. Content: " . print_r($data, true));

            die('KO-Invalid IPN response received.');
        }

        // Check the authenticity of the request (using the active blog's private key).
        if (! LyraEmbeddedTools::check_hash($data)) {
            self::log_error("{$ipn_metadata} Authentication failed: received invalid response with parameters: " . print_r($data, true));

            die('KO-An error occurred while computing the signature.');
        }

        $order_cycle = $answer['orderCycle'] ?? '';

        // Ignore IPN response with abandoned/expired status if order cycle is not closed.
        if (LyraEmbeddedTools::is_abandoned_payment($answer['orderStatus']) && ($order_cycle !== 'CLOSED')) {
            self::log_info("{$ipn_metadata} Abandoned or Expired payment, IPN will be ignored.");

            die('KO-Payment abandoned or expired.');
        }

        $payment_result = LyraEmbeddedTools::get_payment_result($answer);
        if (! is_array($payment_result) || empty($payment_result)) {
            self::log_error("{$ipn_metadata} Invalid payment result received. Content: " . print_r($answer, true));

            die('KO-Invalid payment result received.');
        }

        $order_id = $answer['orderDetails']['orderId'] ?? null;
        if (! $order_id) {
            if (isset($payment_result['operationType']) && ($payment_result['operationType'] === 'VERIFICATION')) {
                self::log_info("{$ipn_metadata} Verification transaction, IPN will be ignored.");

                die('OK-Verification transaction, IPN ignored.');
            } else {
                self::log_error("{$ipn_metadata} No order ID.");

                die('KO-No order ID was received in response.');
            }
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            self::log_error("{$ipn_metadata} Order #$order_id not found.");

            die('KO-Order not found.');
        }

        if ($order_cycle === 'CLOSED') {
            self::lyra_embedded_clear_cached_form_config($order);
        }

        $trans_uuid = $payment_result['uuid'] ?? 'N/A';
        $trans_status = $payment_result['status'] ?? '';
        if (! $trans_status) {
            self::log_error("{$ipn_metadata} Missing transaction status in payment result for order #$order_id.");

            die('KO-Invalid payment result received.');
        }

        self::log_info("{$ipn_metadata} Payment status for transaction with UUID #$trans_uuid is {$trans_status}.");

        $current_status = $order->get_status();
        self::log_info("{$ipn_metadata} The current state for order #$order_id is {$current_status}.");

        $order_status = $answer['orderStatus'] ?? '';
        $not_processed = in_array($current_status, ['pending', 'checkout-draft'], true);
        if ($not_processed) {
            self::lyra_embedded_persist_transaction_details($payment_result, $order);

            if (LyraApi::isAcceptedPayment($trans_status)) {
                $msg = (LyraApi::isPendingPayment($trans_status)) ? 'Pending payment' : 'Accepted payment';
                self::log_info("{$ipn_metadata} {$msg} for order #$order_id, let's update order status.");

                $this->lyra_embedded_save_order($order, $trans_status);

                if (isset($payment_result['metadata']['is_subscription']) && isset($payment_result['paymentMethodToken'])) {
                    $this->lyra_embedded_process_subscription($order, $payment_result);
                }

                die('OK-' . $msg . ' , order has been updated successfully.');
            } elseif ($order_cycle !== 'CLOSED') { // Ignore IPN on failed payment attempts if buyer can try to re-order.
                self::log_info("{$ipn_metadata} Payment is not accepted but buyer can try to re-order. Do not process order #{$order_id} at this time.");

                die('KO-Payment failure but the buyer can retry.');
            }  else {
                self::log_info("{$ipn_metadata} Payment abandoned or failed for order #$order_id, let's update order status.");
                $this->lyra_embedded_save_order($order, $trans_status);

                if (LyraEmbeddedTools::is_abandoned_payment($order_status)) {
                    die('KO-Payment abandoned, order has been cancelled.');
                } else {
                    die('KO-Payment failure, order has been updated.');
                }
            }
        } else {
            self::log_info("{$ipn_metadata} Order #$order_id is already saved.");

            if ($current_status === 'on-hold') {
                switch (true) {
                    case LyraApi::isCancelledPayment($trans_status):
                        self::log_info("{$ipn_metadata} Order #$order_id is in a pending status and payment is cancelled. It may be a payment expiration. Do nothing.");

                        die('payment_ko_already_done');
                        break;
                    case LyraApi::isPendingPayment($trans_status):
                        self::log_info("{$ipn_metadata} Order #$order_id is in a pending status and stays in the same status. Do nothing.");

                        die('payment_ok_already_done');
                        break;
                    case LyraApi::isAcceptedPayment($trans_status):
                        self::log_info("{$ipn_metadata} Order #$order_id is in a pending status and payment is accepted. Complete order payment.");

                        $this->lyra_embedded_save_order($order, $trans_status);
                        die('payment_ok');
                    default:
                        self::log_info("{$ipn_metadata} Order #$order_id is in a pending status and payment failed. Cancel order.");

                        // Add order note.
                        $this->lyra_embedded_save_order($order, $trans_status);
                        die('payment_ko');
                }
            } elseif (LyraApi::isAcceptedPayment($trans_status) && ! in_array($current_status, ['failed', 'cancelled'])) {
                $msg = (LyraApi::isPendingPayment($trans_status)) ? 'Pending payment' : 'Accepted payment';
                self::log_info("{$ipn_metadata} {$msg} confirmed for order #$order_id.");

                // Manage refunds and transaction amount updates from merchant BO.
                if ((in_array($ipn_src, ['BO', 'MERCH_BO'], true)) && ($current_status !== 'refunded')) {
                    $this->process_refund_from_bo($order, $payment_result);
                }

                // Order success registered and payment success received.
                die('payment_ok_already_done');
            } elseif (! LyraApi::isAcceptedPayment($trans_status)) {
                if (LyraApi::isCancelledPayment($trans_status)) {
                    self::log_info("{$ipn_metadata} Payment cancelled for order #$order_id, let's update order status.");
                    $this->lyra_embedded_save_order($order, $trans_status);

                    die('KO-Payment failure, order has been cancelled.');
                } elseif (in_array($current_status, ['failed', 'cancelled'], true)) {
                    self::log_info("{$ipn_metadata} Payment failed confirmed for order #$order_id.");

                    // Order failure registered and payment error received.
                    die('KO-Payment failure, already registered.');
                }
            } else {
                self::log_error("{$ipn_metadata} Error! Invalid payment result received for already saved order #$order_id. Payment result : {$trans_status}, Order status : {$current_status}.");

                // Registered order status not match payment result.
                die('KO-Order status does not match the payment result.');
            }
        }
    }

    /**
     * Process refund.
     *
     * The gateway will allow it to refund the passed in amount in the currency of the order.
     *
     * @param int $order_id Order ID.
     * @param float|null $amount Refund amount.
     * @param string $reason Refund reason.
     *
     * @return bool|\WP_Error True or false based on success, or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        self::log_info("Automatic refund process start for order #$order_id.");

        $order = wc_get_order($order_id);
        if (! $order || is_null($amount)) {
            return false;
        }

        if ($order->get_payment_method() !== 'lyra_embedded') {
            return false;
        }

        $this->lyra_embedded_launch_online_refund($order, $amount);

        $message = sprintf(__('Online Refund of %1$s.', 'woo-lyra-embedded-payment'), $amount . $order->get_currency());

        if ($reason) {
            if (strlen($reason) > 500) {
                $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 450) : substr($reason, 0, 450);
                $reason = $reason . '...';
            }

            $message = sprintf(__('Online Refund of %1$s - Reason: %2$s.', 'woo-lyra-embedded-payment'), $amount . $order->get_currency(), $reason);
        }

        $order->add_order_note($message);

        self::log_info("Automatic refund process end for order #$order_id.");

        return true;
    }

    /**
     * Displays the stored online refund result notice for the given order in the admin.
     *
     * The notice is stored as a short-lived transient scoped to the order ID
     * (set by {@see LyraEmbeddedRefundProcessor}) so that concurrent refunds on
     * different orders cannot bleed into each other's admin pages.
     *
     * @param int|string $order_id WooCommerce order ID.
     *
     * @return void
     */
    public function lyra_embedded_display_refund_result($order_id)
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $transient_key = 'lyra_embedded_refund_result_' . $order_id;
        $refund_result  = get_transient($transient_key);

        if ($refund_result) {
            // The notice HTML is already sanitized (built with esc_html) in LyraEmbeddedRefundProcessor::doOnError.
            echo wp_kses_post($refund_result);
            delete_transient($transient_key);
        }
    }

    /**
     * Delegate a scheduled subscription renewal payment to the subscription processor.
     *
     * Called by the `woocommerce_scheduled_subscription_payment_lyra_embedded` action.
     *
     * @param float|int|string $renewal_total Renewal amount to charge.
     * @param WC_Order         $renewal_order Renewal order instance.
     * @return void
     */
    public function process_subscription_payment($renewal_total, WC_Order $renewal_order)
    {
        require_once 'includes/LyraEmbeddedSubscriptionProcessor.php';

        LyraEmbeddedSubscriptionProcessor::process_subscription_payment($renewal_total, $renewal_order);
    }

    /**
     * Writes a message to the WooCommerce log under the `lyra_embedded` source.
     *
     * @param string $msg Message to log.
     * @return void
     */
    public static function log_info($msg)
    {
        wc_get_logger()->info($msg, ['source' => 'lyra_embedded']);
    }

    /**
     * Add the saved Lyra embedded token to subscription payment meta.
     *
     * @param array           $payment_meta
     * @param WC_Subscription $subscription
     * @return array
     */
    public function get_subscription_payment_meta($payment_meta, $subscription): array
    {
        $saved_meta = LyraEmbeddedTools::get_meta_data($subscription, 'lyra_embedded_token');
        if (! $saved_meta) {
            return $payment_meta;
        }

        $payment_meta[$this->id] = array(
            'post_meta' => array(
                'lyra_embedded_token' => array(
                    'value' => $saved_meta,
                    'label' => sprintf(__('%s embedded token', 'woo-lyra-embedded-payment'), LyraEmbeddedTools::get_default('GATEWAY_NAME'))
                )
            )
        );

        return $payment_meta;
    }

    /**
     * Add payment and 3DS information as notes on the order.
     *
     * @param array    $payment_result Normalised payment result data.
     * @param WC_Order $order          Related WooCommerce order.
     * @return void
     */
    public static function lyra_embedded_add_order_note($payment_result, $order)
    {
        // 3DS extra message.
        $note = __('3DS authentication: ', 'woo-lyra-embedded-payment');
        if ($status = ($payment_result['authenticationStatus'] ?? '')) {
            $note .= self::get_threeds_status($status);

            if ($threeds_cavv = ($payment_result['cavv'] ?? '')) {
                $note .= "\n";
                $note .= __('3DS certificate: ', 'woo-lyra-embedded-payment') . $threeds_cavv;
            }

            if ($threeds_auth_type = ($payment_result['authenticationType'] ?? '')) {
                $note .= "\n";
                $note .= __('Authentication type: ', 'woo-lyra-embedded-payment') . $threeds_auth_type;
            }
        } else {
            $note .= 'UNAVAILABLE';
        }

        $order->add_order_note($note);

        if (! LyraApi::isCancelledPayment($payment_result['status'] ?? '')) {
            if (($payment_result['cardBrand'] ?? '') === 'MULTI' && ! empty($payment_result['paymentSequence'])) {
                $payment_seq = json_decode($payment_result['paymentSequence'], true);
                foreach (($payment_seq['transactions'] ?? []) as $payment) {
                    self::lyra_embedded_transaction_meta($payment, $order);
                }
            } else {
                self::lyra_embedded_transaction_meta($payment_result, $order);
            }
        }
    }

    /**
     * Writes a message to the WooCommerce log under the `lyra_embedded` source.
     *
     * @param string $msg Message to log.
     * @return void
     */
    public static function log_error($msg)
    {
        wc_get_logger()->error($msg, ['source' => 'lyra_embedded']);
    }

    /**
     * Checks whether the current admin request is for this gateway's settings section.
     *
     * @return bool True if the gateway settings section is currently loaded, false otherwise.
     */
    protected function is_admin_section_loaded(): bool
    {
        $current_section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : null;
        if (is_null($current_section)) {
            return false;
        }

        return ($current_section === $this->id) || (strtolower($current_section) === strtolower(get_class($this)));
    }

    /**
     * Initialises common gateway properties: on the gateway settings admin page,
     * resolves the admin page slug and settings URL.
     *
     * @return void
     */
    protected function lyra_embedded_init()
    {
        if ($this->is_admin_section_loaded()) {
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
            $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
            $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
            if (($page === '') || ($tab === '') || ($section === '')) {
                return;
            }

            $this->admin_page = $page;

            $this->admin_link = admin_url(add_query_arg([
                'page' => $page,
                'tab' => $tab,
                'section' => $section,
            ], 'admin.php'));
        }
    }

    /**
     * Triggers an online refund request through the Lyra refund API.
     *
     * @param WC_Order     $order           WooCommerce order object.
     * @param float|string $refund_amount   Refund amount.
     *
     * @return void
     */
    private function lyra_embedded_launch_online_refund($order, $refund_amount)
    {
        if (! $order || ! $order->get_id()) {
            return;
        }

        require_once 'includes/LyraEmbeddedRefundProcessor.php';

        $order_id = $order->get_id();
        $refund_currency = $order->get_currency();

        $order_info = new LyraOrderInfo();
        $order_info->setOrderRemoteId($order_id);
        $order_info->setOrderId($order_id);
        $order_info->setOrderReference($order->get_order_number());
        $order_info->setOrderCurrencyIsoCode($refund_currency);
        $order_info->setOrderCurrencySign(html_entity_decode(get_woocommerce_currency_symbol($refund_currency)));
        $order_info->setOrderUserInfo(LyraEmbeddedTools::get_user_info());

        $refund_processor = new LyraEmbeddedRefundProcessor($order_id);

        $refund_api = new LyraRefundApi(
            $refund_processor,
            LyraEmbeddedTools::get_private_key(),
            LyraEmbeddedTools::get_rest_url(),
            LyraEmbeddedTools::get_shop_id(),
            'WooCommerce',
            LyraEmbeddedTools::get_default('BACKOFFICE_NAME')
        );

        // Do online refund.
        try {
            $refund_api->refund($order_info, $refund_amount, LyraEmbeddedTools::get_white_label());
        } catch (Exception $e) {
            self::log_error("Error while refunding order #$order_id: " . $e->getMessage());
        }
    }

    /**
     * Empty the cart and redirect the customer back to the cart page.
     *
     * @return void
     */
    private function lyra_embedded_redirect_error()
    {
        WC()->cart->empty_cart();
        wp_redirect(wc_get_cart_url());
        exit;
    }

    /**
     * Store transaction details in order note and order meta.
     *
     * @param array    $payment_result Normalised payment result data.
     * @param WC_Order $order          Related WooCommerce order.
     * @return void
     */
    private static function lyra_embedded_persist_transaction_details($payment_result, $order)
    {
        self::lyra_embedded_add_order_note($payment_result, $order);
        self::lyra_embedded_update_order_meta($payment_result, $order);
    }

    /**
     * Update the WooCommerce order according to the payment transaction status.
     *
     * @param WC_Order $order        Order to update.
     * @param string   $trans_status Gateway transaction status.
     * @return void
     */
    private function lyra_embedded_save_order($order, $trans_status)
    {
        switch (true) {
            case LyraApi::isPendingPayment($trans_status):
                $order->update_status('on-hold');
                break;

            case LyraApi::isAcceptedPayment($trans_status):
                $order->payment_complete();
                break;

            case LyraApi::isCancelledPayment($trans_status):
                $order->update_status('cancelled');
                break;

            default:
                $order->update_status('failed');
                break;
        }

        self::log_info("Order #{$order->get_id()} updated successfully.");
    }

    /**
     * Persist the payment method token on subscriptions linked to the order.
     *
     * @param WC_Order $order          Related WooCommerce order.
     * @param array    $payment_result Normalised payment result data.
     * @return void
     */
    private function lyra_embedded_process_subscription($order, $payment_result)
    {
        if (! function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }

        if (isset($payment_result['metadata']['update_identifier_all'])) {
            // Update payment identifier for all subscriptions for the customer.
            $subscriptions = wcs_get_subscriptions(['customer_id' => $order->get_user_id()]);
        } else {
            // Update payment identifier for all subscriptions for the order.
            $subscriptions = wcs_get_subscriptions_for_order($order);
        }

        $is_hpos_enabled = LyraEmbeddedTools::is_hpos_enabled();
        foreach ($subscriptions as $subscription) {
            LyraEmbeddedTools::update_meta_data($subscription, 'lyra_embedded_token', $payment_result['paymentMethodToken'], $is_hpos_enabled);

            if ($is_hpos_enabled) {
                $subscription->save();
            }
        }
    }

    /**
     * Add transaction details as an order note.
     *
     * @param array    $transaction Transaction data returned by the gateway.
     * @param WC_Order $order       Related WooCommerce order.
     * @return void
     */
    private static function lyra_embedded_transaction_meta($transaction, $order)
    {
        $sequence_number = $transaction['sequenceNumber'] ?? '';
        $note = sprintf(__('Transaction ID: %s.', 'woo-lyra-embedded-payment'), ($transaction['transId'] ?? '') . ($sequence_number ? '-' . $sequence_number : ''));

        if ($trans_uuid = ($transaction['uuid'] ?? '')) {
            $note .= "\n";
            $note .= sprintf(__('Transaction UUID: %s.', 'woo-lyra-embedded-payment'), $trans_uuid);
        }

        if ($trans_status = ($transaction['status'] ?? '')) {
            $note .= "\n";
            $note .= sprintf(__('Transaction status: %s.', 'woo-lyra-embedded-payment'), $trans_status);
        }

        $order->add_order_note($note);
    }

    /**
     * Persist gateway transaction details in order meta.
     *
     * @param array    $payment_result Normalised payment result data.
     * @param WC_Order $order           Related WooCommerce order.
     * @return void
     */
    private static function lyra_embedded_update_order_meta($payment_result, $order)
    {
        $is_hpos_enabled = LyraEmbeddedTools::is_hpos_enabled();

        // Delete old saved transaction details.
        LyraEmbeddedTools::delete_meta_data($order, 'Transaction ID', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, 'Card number', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, 'IBAN / BIC', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, 'Means of payment', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, 'Card expiry', $is_hpos_enabled);
        LyraEmbeddedTools::delete_meta_data($order, 'Sequence number', $is_hpos_enabled);

        // Store transaction details.
        LyraEmbeddedTools::update_meta_data($order, 'Transaction ID', $payment_result['transId'] ?? '', $is_hpos_enabled);
        LyraEmbeddedTools::update_meta_data($order, 'Means of payment', $payment_result['cardBrand'] ?? '', $is_hpos_enabled);
        LyraEmbeddedTools::update_meta_data($order, 'Sequence number', $payment_result['sequenceNumber'] ?? '', $is_hpos_enabled);

        if (($payment_result['cardBrand'] ?? '') === 'SDD') {
            LyraEmbeddedTools::update_meta_data($order, 'IBAN / BIC', $payment_result['cardNumber'] ?? '', $is_hpos_enabled);
        }  else {
            LyraEmbeddedTools::update_meta_data($order, 'Card number', $payment_result['cardNumber'] ?? '', $is_hpos_enabled);
        }

        $expiry = '';
        if (! empty($payment_result['expiryMonth']) && ! empty($payment_result['expiryYear'])) {
            $expiry = str_pad($payment_result['expiryMonth'], 2, '0', STR_PAD_LEFT) . '/' . $payment_result['expiryYear'];
        }

        LyraEmbeddedTools::update_meta_data($order, 'Card expiry', $expiry, $is_hpos_enabled);

        if ($is_hpos_enabled) {
            $order->save();
        }
    }

    /**
     * Persist the latest amount received from gateway updates.
     *
     * @param WC_Order $order  Related WooCommerce order.
     * @param float    $amount Latest amount converted to WooCommerce format.
     * @return void
     */
    private static function lyra_embedded_set_last_amount($order, $amount)
    {
        LyraEmbeddedTools::update_meta_data('_lyra_embedded_last_updated_amount', $amount);

        if (LyraEmbeddedTools::is_hpos_enabled()) {
            $order->save();
        }
    }

    /**
     * Convert a raw 3DS authentication status code to a human-readable label.
     *
     * @param string $status Raw 3DS status code ('Y', 'N', 'U', 'A', or other)
     *
     * @return string Human-readable status label, or the original code if unknown
     */
    private static function get_threeds_status(string $status): string
    {
        switch ($status) {
            case 'Y':
                return 'SUCCESS';

            case 'N':
                return 'FAILED';

            case 'U':
                return 'UNAVAILABLE';

            case 'A':
                return 'ATTEMPT';

            default :
                return $status;
        }
    }

    /**
     * Encode a response as JSON and terminate execution.
     *
     * @param mixed $result Data to encode in JSON.
     * @return void
     */
    private function lyra_json_encode($result)
    {
        @ob_clean();
        echo wp_json_encode($result);
        die();
    }

    /**
     * Create WooCommerce refund entries from Back Office transaction updates.
     *
     * @param WC_Order $order          Related WooCommerce order.
     * @param array    $payment_result Gateway payment payload.
     * @return void
     */
    private function process_refund_from_bo($order, $payment_result)
    {
        $process_wc_refund = false;
        $trans_uuid = null;

        $amount = $payment_result['amount'];

        $order_currency = $order->get_currency() ?? get_woocommerce_currency();
        $currency = LyraApi::findCurrencyByAlphaCode($order_currency, LyraEmbeddedTools::get_white_label());

        if ($payment_result['operationType'] === 'CREDIT') {
            $trans_uuid = $payment_result['uuid'];
            $refund_amount = $amount;
            $reason = sprintf(__('Refund via %s Back Office.', 'woo-lyra-embedded-payment' ), LyraEmbeddedTools::get_default('BACKOFFICE_NAME'));
            $process_wc_refund = true;

            // Check if a refund object has already created for this transaction.
            foreach ($order->get_refunds() as $refund) {
                if (LyraEmbeddedTools::get_refund_transaction_id($refund) == $trans_uuid) {
                    self::log_info("Found existing refund #{$refund->get_id()} for credit transaction #$trans_uuid.") ;

                    $process_wc_refund = false;
                    break;
                }
            }
        } else {
            $last_updated_amount = LyraEmbeddedTools::get_meta_data($order, '_lyra_embedded_last_updated_amount');

            if (! $last_updated_amount || $amount < $last_updated_amount) {
                $process_wc_refund = true;
                self::lyra_embedded_set_last_amount($order, $amount);

                if ($last_updated_amount) {
                    $refund_amount = $last_updated_amount - $amount;
                } else {
                    $total_order_in_cents = $currency->convertAmountToInteger($order->get_total());
                    $refund_amount = $total_order_in_cents - $amount;
                }

                $reason = sprintf(__('Update of transaction amount in %s Back Office.', 'woo-lyra-embedded-payment' ), LyraEmbeddedTools::get_default('BACKOFFICE_NAME'));
            }
        }

        if ($process_wc_refund) {
            $order_id = $order->get_id();
            self::log_info("Create refund for order #$order_id.");

            $refund_amount = $currency->convertAmountToFloat($refund_amount);

            // Create the refund.
            $refund = wc_create_refund(
                [
                    'order_id' => $order_id,
                    'amount'   => $refund_amount,
                    'reason'   => $reason
                ]
            );

            if (is_wp_error($refund)) {
                self::log_error("Error while creating refund for order #$order_id: " . $refund->get_error_message());
            } else {
                if ($trans_uuid) {
                    LyraEmbeddedTools::set_refund_transaction_id($refund, $trans_uuid);
                }

                self::log_info("Refund #{$refund->get_id()} created successfully for order #$order_id.");
                $order->add_order_note(sprintf(__('Online Refund of %1$s - Reason: %2$s.', 'woo-lyra-embedded-payment'), $refund_amount . $order->get_currency(), $reason));
            }
        }
    }
}