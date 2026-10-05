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

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Lyra embedded payment method integration
 *
 */
final class WC_Gateway_Lyra_Embedded_Blocks_Support extends AbstractPaymentMethodType
{
    /**
     * Identifier of the payment method.
     *
     * @var string
     */
    protected $name;

    /**
     * Label of the payment method.
     *
     * @var string
     */
    protected $label = null;

    /**
     * List of the features supported by the payment method.
     *
     * @var array
     */
    protected $supports;

    /**
     * Initialize blocks payment method identifiers and display label.
     */
    public function __construct()
    {
        $this->name = 'lyra_embedded';
        $this->label = LyraEmbeddedTools::get_title();
    }

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
       $this->settings = get_option('woocommerce_lyra_embedded_settings', []);
       $this->supports = WC_Gateway_LyraEmbedded::get_supported_features();
    }

    /**
     * Indicates whether the payment method is available for Blocks.
     *
     * @return bool
     */
    public function is_active(): bool
    {
        return ! empty(LyraEmbeddedTools::get_active_form_config())
            && ! empty(LyraEmbeddedTools::get_public_key())
            && ! empty(LyraEmbeddedTools::get_private_key());
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array<int, string>
     */
    public function get_payment_method_script_handles()
    {
        $asset_path = WC_LYRA_EMBEDDED_PLUGIN_PATH . 'build/lyra_embedded.asset.php';
        $version = LyraEmbeddedTools::get_contrib();

        $dependencies = [];
        if (file_exists($asset_path)) {
            $asset = require $asset_path;
            $version = is_array($asset) && isset($asset['version']) ? $asset['version'] : $version;
            $dependencies = is_array($asset) && isset($asset['dependencies']) ? $asset['dependencies']
                : $dependencies;
        }

        if (! in_array('wc-blocks-registry', $dependencies, true)) {
            $dependencies[] = 'wc-blocks-registry';
        }

        if (! wp_script_is('wc-' . $this->get_name() . '-blocks-integration', 'registered')) {
            wp_register_script(
                'wc-' . $this->name . '-blocks-integration',
                WC_LYRA_EMBEDDED_PLUGIN_URL . 'build/' . $this->get_name() . '.js',
                $dependencies,
                $version,
                true
            );
        }

        wp_set_script_translations(
            'wc-' . $this->name . '-blocks-integration',
            'woo-lyra-embedded-payment',
            WC_LYRA_EMBEDDED_PLUGIN_PATH . '/languages'
        );

        return ['wc-' . $this->name . '-blocks-integration'];
    }

    /**
     * Returns an array of key => value pairs of data made available to the payment methods script.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data()
    {
        $data = [
            'title' => $this->label,
            'supports' => $this->supports,
            'getTokenUrl' => add_query_arg('wc-api', 'WC_Gateway_' . ucfirst($this->get_name()) . '_Form_Token', home_url('/'))
        ];

        return $data;
    }
}
