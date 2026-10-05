/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

(function($) {
    // Clear form errors whenever the payment button is clicked (works for dynamically injected buttons).
    $(document).on('click', '.kr-payment-button', function() {
        $('.kr-form-error').html('');
    });

    /**
     * Unblock the payment UI (hides the processing overlay and unblocks WooCommerce forms).
     */
    var lyraEmbeddedUnblockPayment = function() {
        $('form.checkout').removeClass('processing').unblock();
        $('#order_review').unblock();
    };

    /**
     * Register KR event listeners (onError, onOrderUpdate, onFocus).
     * KR must be available before calling this function;
     */
    var lyraEmbeddedInitRestEvents = function() {
        if (typeof KR === 'undefined') {
            return;
        }

        KR.onError(function(e) {
            lyraEmbeddedUnblockPayment();
            lyraEmbeddedResetBlocksCheckout();

            if (typeof window.lyraEmbeddedResetDirectSubmitState === 'function') {
                window.lyraEmbeddedResetDirectSubmitState();
            }
        });

        KR.onOrderUpdate(function(callback) {
            if (callback._name === 'billingPartialPayment') {
                lyraEmbeddedUnblockPayment();
                lyraEmbeddedResetBlocksCheckout();

                if (typeof window.lyraEmbeddedResetDirectSubmitState === 'function') {
                    window.lyraEmbeddedResetDirectSubmitState();
                }
            }
        });

        KR.onFocus(function() {
            $('.kr-form-error').html('');
            if (typeof window.lyraEmbeddedClearBlocksPaymentNotice === 'function') {
                window.lyraEmbeddedClearBlocksPaymentNotice();
            }
        });
    };

    var lyraEmbeddedClearBlocksPaymentNotice = function() {
        if (typeof wp === 'undefined' || typeof wp.data === 'undefined') {
            return;
        }

        try {
            var noticesStore = wp.data.dispatch('core/notices');
            if (noticesStore && typeof noticesStore.removeNotice === 'function') {
                noticesStore.removeNotice('wc-payment-error', 'wc/checkout/payments');
            }
        } catch (e) {
            console.warn('[Lyra Embedded] Could not clear WooCommerce payment notices:', e);
        }
    };

    /**
     * Reset WooCommerce Blocks checkout state to IDLE so the "Place Order" button
     * becomes clickable again after KR takes over the payment flow.
     */
    var lyraEmbeddedResetBlocksCheckout = function() {
        if (typeof wp === 'undefined' || typeof wp.data === 'undefined') {
            return;
        }

        try {
            var checkoutStore = wp.data.dispatch('wc/store/checkout');
            if (!checkoutStore) {
                return;
            }

            // Prefer the public API when available; keep internal fallback for older Blocks versions.
            if (typeof checkoutStore.setIdle === 'function') {
                checkoutStore.setIdle();
            } else if (typeof checkoutStore.__internalSetIdle === 'function') {
                checkoutStore.__internalSetIdle();
            }

            lyraEmbeddedClearBlocksPaymentNotice();
        } catch (e) {
            console.warn('[Lyra Embedded] Could not reset WooCommerce checkout state:', e);
        }
    };

    var lyraEmbeddedSubmitPayment = function () {
        if (!KR) {
            return;
        }

        let isSmartform = jQuery('.kr-smart-form');
        let smartformModalButton = jQuery('.kr-smart-form-modal-button');
        if (isSmartform.length > 0 || smartformModalButton.length > 0) {
            KR.openSelectedPaymentMethod();
            lyraEmbeddedResetBlocksCheckout();
        } else {
            KR.submit();
        }
    };

    // Expose functions globally so they can be called from inline scripts and the Blocks module.
    window.lyraEmbeddedInitRestEvents = lyraEmbeddedInitRestEvents;
    window.lyraEmbeddedClearBlocksPaymentNotice = lyraEmbeddedClearBlocksPaymentNotice;
    window.lyraEmbeddedResetBlocksCheckout = lyraEmbeddedResetBlocksCheckout;
    window.lyraEmbeddedSubmitPayment = lyraEmbeddedSubmitPayment;
})(jQuery);
