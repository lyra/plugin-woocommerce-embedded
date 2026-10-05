<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

use Lyranetwork\LyraEmbedded\Sdk\Refund\Processor as RefundProcessor;

class LyraEmbeddedRefundProcessor implements RefundProcessor
{
    /**
     * The WooCommerce order ID this processor instance is bound to.
     *
     * @var int
     */
    private $order_id;

    /**
     * @param int $order_id WooCommerce order ID whose refund is being processed.
     */
    public function __construct(int $order_id)
    {
        $this->order_id = $order_id;
    }

    /**
     * Returns the transient key scoped to the current order.
     *
     * @return string
     */
    private function get_transient_key(): string
    {
        return 'lyra_embedded_refund_result_' . $this->order_id;
    }

    /**
     * Action to do in case of error during refund process.
     *
     * @param int|string $error_code Gateway error code.
     * @param string     $message    Human-readable error message.
     * @return void
     */
    public function doOnError($error_code, $message)
    {
        $notice = '<div class="notice notice-error inline" style="text-align: center;"><p>' . esc_html($message) . '</p></div>';

        set_transient($this->get_transient_key(), $notice, 60);
    }

    /**
     * Action to do after successful refund process.
     *
     * @param mixed  $operation_response Gateway operation response.
     * @param string $operation_type     Type of operation performed.
     * @return void
     */
    public function doOnSuccess($operation_response, $operation_type)
    {
        if ($operation_type == 'frac_update') {
            $this->doOnError(
                -1,
                sprintf(
                    $this->translate('Refund of split payment is not supported. Please, consider making necessary changes in %1$s Back Office.'),
                    LyraEmbeddedTools::get_default('BACKOFFICE_NAME')
                )
            );
        }
    }

    /**
     * Action to do after failed refund process.
     *
     * @param int|string $error_code Gateway error code.
     * @param string     $message    Human-readable error message.
     * @return void
     */
    public function doOnFailure($error_code, $message)
    {
        $this->doOnError($error_code, $message);
    }

    /**
     * Log informations.
     *
     * @param string $message Log message.
     * @param string $level   Log level ('INFO' or 'ERROR').
     * @return void
     */
    public function log($message, $level = 'INFO')
    {
        if ($level === 'ERROR') {
            WC_Gateway_LyraEmbedded::log_error($message);
        } else {
            WC_Gateway_LyraEmbedded::log_info($message);
        }
    }

    /**
     * Translate given message.
     *
     * @param string $message Message to translate.
     * @return string
     */
    public function translate($message)
    {
        return __($message, 'woo-lyra-embedded-payment');
    }
}
