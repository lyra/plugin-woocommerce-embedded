<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
*/

declare(strict_types=1);

/**
 * Plugin Name: Lyra Embedded for WooCommerce
 * Plugin URI: https://www.lyra.com/
 * Description: Embedded payment gateway integration for WooCommerce using Lyra.
 * Version: 1.0.0-beta1
 * Author: Lyra Network
 * Author URI: https://www.lyra.com/
 * License: OSL-3.0
 * License URI: https://opensource.org/licenses/osl-3.0.php
 * Text Domain: woo-lyra-embedded-payment
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

define('WC_LYRA_EMBEDDED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_LYRA_EMBEDDED_PLUGIN_PATH', untrailingslashit(plugin_dir_path(__FILE__)));

/**
 * Check plugin requirements on activation.
 *
 * Deactivates the plugin and shows an error if WooCommerce is not active.
 *
 * @return void
 */
function woocommerce_lyra_embedded_activation()
{
    $all_active_plugins = get_option('active_plugins');
    if (is_multisite()) {
        $all_active_plugins = array_merge($all_active_plugins, wp_get_active_network_plugins());
    }

    $all_active_plugins = apply_filters('active_plugins', $all_active_plugins);

    if (stripos(implode('', $all_active_plugins), '/woocommerce.php') === false) {
        deactivate_plugins(plugin_basename(__FILE__)); // Deactivate ourself.

        // Load translation files.
        load_plugin_textdomain('woo-lyra-embedded-payment', false, plugin_basename(dirname(__FILE__)) . '/languages');

        $message = sprintf(__('Sorry ! In order to use WooCommerce %s Payment plugin, you need to install and activate the WooCommerce plugin.', 'woo-lyra-embedded-payment'), 'Lyra embedded');
        wp_die($message, 'Lyra Embedded for WooCommerce', ['back_link' => true]);
    }
}

register_activation_hook(__FILE__, 'woocommerce_lyra_embedded_activation');

/**
 * Delete all plugin data on uninstall.
 *
 * @return void
 */
function woocommerce_lyra_embedded_uninstallation()
{
    delete_option('woocommerce_lyra_embedded_settings');
}

register_uninstall_hook(__FILE__, 'woocommerce_lyra_embedded_uninstallation');

/**
 * Include gateway classes and SDK when WooCommerce is initialised.
 *
 * @return void
 */
function woocommerce_lyra_embedded_init()
{
    // Load translation files.
    load_plugin_textdomain('woo-lyra-embedded-payment', false, plugin_basename(dirname(__FILE__)) . '/languages');

    if (! class_exists('WC_Gateway_LyraEmbedded')) {
        require_once 'class-wc-gateway-lyra-embedded.php';
    }

    require_once 'sdk/sdk-autoload.php';
    require_once 'includes/LyraEmbeddedTools.php';
}

add_action('woocommerce_init', 'woocommerce_lyra_embedded_init');

/**
 * Load plugin translations.
 *
 * @return void
 */
function woocommerce_lyra_embedded_load_textdomain()
{
    load_plugin_textdomain('woo-lyra-embedded-payment', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('init', 'woocommerce_lyra_embedded_load_textdomain');

/**
 * Initialize the gateway class only when WooCommerce is available.
 *
 * @return void
 */
function woocommerce_lyra_embedded_init_gateway()
{
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'class-wc-gateway-lyra-embedded.php';
}

add_action('plugins_loaded', 'woocommerce_lyra_embedded_init_gateway', 20);

/**
 * Register the Lyra Embedded gateway in WooCommerce payment methods list.
 *
 * @param  array $methods Registered payment gateway class names.
 * @return array
 */
function woocommerce_lyra_embedded_add_gateway($methods)
{
    $methods[] = 'WC_Gateway_LyraEmbedded';

    return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_lyra_embedded_add_gateway');

/**
 * Add a settings link on the plugin's entry in the plugins list.
 *
 * @param  array $links Existing action links for the plugin.
 * @param  string $file Path to the plugin file (unused, required by filter signature).
 * @return array
 */
function woocommerce_lyra_embedded_add_link($links, $file)
{
    $links[] = '<a href="' . lyra_embedded_admin_url('lyra_embedded') . '">' . __('Settings', 'woocommerce') . '</a>';

    return $links;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woocommerce_lyra_embedded_add_link', 10, 2);

/**
 * Build the admin URL for a given payment gateway section.
 *
 * @param  string $id Gateway method ID.
 * @return string
 */
function lyra_embedded_admin_url($id)
{
    $base_url = 'admin.php?page=wc-settings&tab=checkout&section=';
    $section = strtolower($id); // Method id in lower case.

    return admin_url($base_url . $section);
}

/**
 * Declare compatibility with WooCommerce feature flags (HPOS, Cart/Checkout Blocks).
 *
 * @return void
 */
function lyra_embedded_features_compatibility()
{
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

add_action('before_woocommerce_init', 'lyra_embedded_features_compatibility');

/**
 * Register Lyra Embedded payment method integration for WooCommerce Blocks.
 *
 * @return void
 */
function woocommerce_lyra_embedded_woocommerce_block_support()
{
    if (! class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . '/includes/LyraEmbeddedTools.php';
    if (! LyraEmbeddedTools::has_checkout_block()) {
        return;
    }

    if (! class_exists('WC_Gateway_Lyra_Embedded_Blocks_Support')) {
        require_once plugin_dir_path(__FILE__) . '/includes/class-wc-gateway-lyra-embedded-blocks-support.php';
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function(PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_Gateway_Lyra_Embedded_Blocks_Support());
        }
    );
}

add_action('woocommerce_blocks_loaded', 'woocommerce_lyra_embedded_woocommerce_block_support');

/**
 * Manages condition to render Refund button on orders paid via Lyra Embedded method.
 *
* @param bool $should_render
* @param int|string $order_id
* @param WC_Order|false|null $order
* @return bool
*/
function lyra_embedded_should_render_refunds($should_render, $order_id, $order = null) {
    if (! $order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }

    if (! $order || ! $should_render || ('lyra_embedded' !== $order->get_payment_method())) {
        return $should_render;
    }

    return $order->get_status() !== 'refunded';
}

add_filter('woocommerce_admin_order_should_render_refunds', 'lyra_embedded_should_render_refunds', 10, 2);

/**
 * Handle IPN notifications multisite environments.
 * This ensures IPN requests are processed regardless of gateway loading state.
 */
add_action('wp_loaded', function() {
    if (! is_multisite()) {
        return;
    }

    // Check if this is an IPN request.
    $wc_api = isset($_GET['wc-api']) ? sanitize_text_field($_GET['wc-api']) : null;
    if ($wc_api !== 'WC_Gateway_Lyra_Embedded_Notify') {
        return;
    }

    // Load required includes.
    if (! class_exists('LyraEmbeddedTools')) {
        require_once plugin_dir_path(__FILE__) . 'includes/LyraEmbeddedTools.php';
    }

    if (! class_exists('WC_Gateway_LyraEmbedded')) {
        require_once plugin_dir_path(__FILE__) . 'class-wc-gateway-lyra-embedded.php';
    }

    if (! class_exists('Lyranetwork\LyraEmbedded\Sdk\Form\Api')) {
        require_once plugin_dir_path(__FILE__) . 'sdk/sdk-autoload.php';
    }

    // Validate IPN data structure.
    if (! LyraEmbeddedTools::check_response_validity($_POST)) {
        return;
    }

    // Parse the answer.
    $data = (array) stripslashes_deep($_POST);
    $answer = json_decode($data['kr-answer'], true);
    if (! is_array($answer)) {
        return;
    }

    // In multisite, ensure we're on the correct blog (may have been set earlier at plugin load).
    global $wpdb, $current_blog, $current_site;

    $transactions = $answer['transactions'] ?? [];
    $blog = null;

    // Check metadata for blog_id.
    if (!empty($transactions) && isset($transactions[0]['metadata']['blog_id'])) {
        $blog = (int) $transactions[0]['metadata']['blog_id'];
    }

    // Switch to the correct blog if different from current.
    if ($blog && (int) $blog !== get_current_blog_id()) {
        switch_to_blog((int) $blog);

        // Set current_blog global var.
        $current_blog = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $wpdb->blogs WHERE blog_id = %s", $blog)
        );
    }

    if (! isset($current_blog) || ! is_object($current_blog)) {
        $current_blog = get_site(get_current_blog_id());
    }

    if (! $current_blog || ! isset($current_blog->site_id)) {
        return;
    }

    // Set current_site global var.
    $network_fnc = function_exists('get_network') ? 'get_network' : 'wp_get_network';
    $current_site = $network_fnc($current_blog->site_id);

    if (! $current_site || empty($current_site->domain) || empty($current_site->path)) {
        return;
    }

    $site_blog_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT blog_id FROM $wpdb->blogs WHERE domain = %s AND path = %s",
            $current_site->domain,
            $current_site->path
       )
    );

    if ($site_blog_id) {
        $current_site->blog_id = $site_blog_id;
    }

    $current_site->site_name = get_site_option('site_name');
    if (! $current_site->site_name && ! empty($current_site->domain)) {
        $current_site->site_name = ucfirst($current_site->domain);
    }
}, 1); // Priority 1 to run early

