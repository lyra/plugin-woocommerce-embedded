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

    var data = getPaymentFieldsData();
    var formConfig = data.formConfig || null;
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

        $('#place_order').on('click', function(event) {
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

        $('form.checkout').on('checkout_place_order_lyra_embedded', function() {
            $('form.checkout').removeClass('processing').unblock();
            $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
                if ((originalOptions.url.indexOf('wc-ajax=checkout') == -1)
                    && (originalOptions.url.indexOf('action=woocommerce-checkout') == -1)
                    && (originalOptions.url.indexOf('wc-ajax=complete_order') == -1)) {
                    return;
                }

                if (options.data.indexOf('payment_method=lyra_embedded') == -1) {
                    return;
                }

                $('.kr-form-error').html('');
                var originalSuccess = options.success;

                options.success = async function(response, status, xhr) {
                    var result = (response && response.result) ? response.result : false;

                    if (result !== 'success') {
                        if (typeof originalSuccess === 'function') {
                            originalSuccess.call(this, response, status, xhr);
                        }
                        return;
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
        });

        // Wait for KR to be ready before initialising the payment form.
        var krInitAttempts = 0;
        var krMaxAttempts = 50; // 50 × 100 ms = 5 s max.

        function initWhenKrReady() {
            if (typeof KR === 'undefined') {
                if (++krInitAttempts < krMaxAttempts) {
                    setTimeout(initWhenKrReady, 100);
                } else {
                    console.error('[Lyra Embedded] KR not available after 5 s.');
                }

                return;
            }

            lyraEmbeddedInitRestEvents();

            if (!formConfig) {
                console.error('[Lyra Embedded] Missing form configuration for payment fields initialisation.');
                return;
            }

            KR.setFormConfig(formConfig).then(function(v) {
                KR = v.KR;
            });
        }

        initWhenKrReady();
    });
})(jQuery);

