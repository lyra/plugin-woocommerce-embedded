/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

(function($) {
    var getPaymentFieldsData = function() {
        var dataNodes = document.querySelectorAll('.lyra-embedded-payment-fields-data');

        if (!dataNodes.length) {
            return {};
        }

        for (var i = 0; i < dataNodes.length; i++) {
            try {
                var parsed = JSON.parse(dataNodes[i].textContent || '{}');
                if (parsed && parsed.formConfig) {
                    return parsed;
                }
            } catch (error) {
                console.error('[Lyra Embedded] Invalid payment fields data node.', error);
            }
        }

        return {};
    };

    var formIsValidated = false;

    $(document).ready(function() {
        // Register form submit events immediately — they do not require KR at registration time.
        $('#order_review').on('submit', function(e) {
            if (! $('#payment_method_lyra_embedded').is(':checked')) {
                return true;
            }

            e.preventDefault();
            lyraEmbeddedSubmitPayment();
        });

        $(document).on('click', '#place_order', function(event) {
            if (! $('#payment_method_lyra_embedded').is(':checked')) {
                return true;
            }

            if (formIsValidated) {
                formIsValidated = false;
                return true;
            }

            if (typeof KR === 'undefined' || !KR || typeof KR.validateForm !== 'function') {
                event.preventDefault();
                console.error('[Lyra Embedded] KR.validateForm is unavailable when clicking Place Order.');
                return false;
            }

            let isCard = KR?.$store?.state?.smartForm?.selectedMethod == 'CARDS';
            if (! isCard) {
                return true;
            }

            event.preventDefault();
            KR.validateForm().then(function(v) {
                // There is no errors.
                formIsValidated = true;
                $('#place_order').trigger('click');
            }).catch(function(v) {
                // Display error message.
                var result = v.result;
                return result.doOnError();
            });
        });

        var checkoutForm = null;
        $('form.checkout').on('checkout_place_order_lyra_embedded', function(event, form) {
            checkoutForm = form;
            $('form.checkout').removeClass('processing').unblock();
        });

        $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
            var requestUrl = originalOptions && originalOptions.url;
            if (typeof requestUrl !== 'string') {
                requestUrl = options.url;
            }

            if (typeof requestUrl !== 'string'
                || ((requestUrl.indexOf('wc-ajax=checkout') == -1)
                    && (requestUrl.indexOf('action=woocommerce-checkout') == -1)
                    && (requestUrl.indexOf('wc-ajax=complete_order') == -1))) {
                return;
            }

            if (typeof options.data !== 'string'
                || options.data.indexOf('payment_method=lyra_embedded') == -1) {
                return;
            }

            var originalSuccess = options.success;

            options.success = async function(response, status, xhr) {
                var result = (response && response.result) ? response.result : false;

                if (result !== 'success') {
                    if (typeof originalSuccess === 'function') {
                        originalSuccess.call(this, response, status, xhr);
                    }

                    return;
                }

                if (checkoutForm) {
                    checkoutForm.detachUnloadEventsOnSubmit();
                }

                // Unblock screen.
                $('form.checkout').unblock();

                var responseFormConfig = response.formConfig;
                if (typeof responseFormConfig === 'string') {
                    responseFormConfig = JSON.parse(responseFormConfig);
                }

                await KR.setFormConfig(responseFormConfig);
                lyraEmbeddedSubmitPayment();
            };
        });

        // Wait for KR to be ready before initialising the payment form.
        var krInitAttempts = 0;
        var krMaxAttempts = 50; // 50 × 100 ms = 5 s max.
        var krInitTimer = null;
        var krEventsRegistered = false;
        var krInitializing = false;
        var krRefreshPending = false;
        var krInitializedWrapper = null;

        function initWhenKrReady() {
            clearTimeout(krInitTimer);
            krInitTimer = null;

            if (krInitializing) {
                krRefreshPending = true;
                return;
            }

            var wrapper = document.querySelector('#lyra_embedded_rest_wrapper');
            if (! wrapper || wrapper === krInitializedWrapper) {
                return;
            }

            if (typeof KR === 'undefined') {
                if (++krInitAttempts < krMaxAttempts) {
                    krInitTimer = setTimeout(initWhenKrReady, 100);
                } else {
                    console.error('[Lyra Embedded] KR not available after 5 s.');
                }

                return;
            }

            if (! krEventsRegistered) {
                lyraEmbeddedInitRestEvents();
                krEventsRegistered = true;
            }

            var formConfig = getPaymentFieldsData().formConfig;
            if (! formConfig) {
                console.error('[Lyra Embedded] Missing form configuration for payment fields initialisation.');
                return;
            }

            krInitializing = true;
            Promise.resolve().then(function() {
                return KR.setFormConfig(formConfig);
            }).then(function(v) {
                KR = v.KR;
                krInitializedWrapper = wrapper;
            }).catch(function(error) {
                console.error('[Lyra Embedded] Unable to initialise payment fields.', error);
            }).finally(function() {
                krInitializing = false;
                if (krRefreshPending) {
                    krRefreshPending = false;
                    initWhenKrReady();
                }
            });
        }

        $(document.body).on('updated_checkout', function() {
            formIsValidated = false;
            krInitAttempts = 0;
            initWhenKrReady();
        });

        initWhenKrReady();
    });
})(jQuery);
