/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * External dependencies.
 */
import { getSetting } from '@woocommerce/settings';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// jQuery is loaded globally by WordPress; reference it safely in this bundled scope.
const jQuery = window.jQuery;

const PAYMENT_METHOD_NAME = 'lyra_embedded';

var lyraData = getSetting(PAYMENT_METHOD_NAME + '_data', {});
var paymentMethodTitle = typeof lyraData?.title === 'string' && lyraData.title.length > 0
    ? lyraData.title
    : __('Payment by credit card', 'woo-lyra-embedded-payment');
var paymentMethodSupports = Array.isArray(lyraData?.supports) ? lyraData.supports : [];
var paymentMethodTokenUrl = typeof lyraData?.getTokenUrl === 'string' ? lyraData.getTokenUrl : '';
var isPaymentMethodConfigured = paymentMethodTokenUrl.length > 0;

// Guard to prevent onCheckoutSuccess from firing more than once per checkout attempt.
var checkoutSuccessHandled = false;
var directSubmitInProgress = false;

window.lyraEmbeddedResetDirectSubmitState = function () {
    directSubmitInProgress = false;
};

if (!isPaymentMethodConfigured) {
    console.warn('[Lyra Embedded] WooCommerce Blocks payment data is incomplete. The payment method will stay hidden.');
}

const styles = {
    divWidth: {
        width: '100%'
    }
}

const Content = (props) => {
    const { activePaymentMethod, eventRegistration } = props || {};
    const { onPaymentSetup, onCheckoutSuccess } = eventRegistration || {};
    const displayFieldsCleanup = useRef(null);

    useEffect(() => {
        const syncDisplayFields = () => {
            if (isLyraPaymentMethodSelected(activePaymentMethod)) {
                if (typeof displayFieldsCleanup.current !== 'function') {
                    displayFieldsCleanup.current = displayFields();
                }
            } else if (typeof displayFieldsCleanup.current === 'function') {
                displayFieldsCleanup.current();
                displayFieldsCleanup.current = null;

                // Allow a fresh checkout flow when the buyer switches payment method.
                checkoutSuccessHandled = false;
                directSubmitInProgress = false;
            }
        };

        syncDisplayFields();

        if (jQuery) {
            jQuery(document.body).on(
                'change.lyraEmbeddedBlocks',
                'input[name=radio-control-wc-payment-method-options]',
                syncDisplayFields
            );
        }

        return () => {
            if (jQuery) {
                jQuery(document.body).off(
                    'change.lyraEmbeddedBlocks',
                    'input[name=radio-control-wc-payment-method-options]',
                    syncDisplayFields
                );
            }

            if (typeof displayFieldsCleanup.current === 'function') {
                displayFieldsCleanup.current();
                displayFieldsCleanup.current = null;
            }
        };
    }, [activePaymentMethod]);

    useEffect(() => {
        const unsubscribes = [];
        if (typeof onPaymentSetup === 'function') {
            let unsubscribe = onPaymentSetup(validateForm);
            if (typeof unsubscribe === 'function') {
                unsubscribes.push(unsubscribe);
            }
        }

        // Only subscribe when this payment method is active.
        const isLyraSelected = isLyraPaymentMethodSelected(activePaymentMethod);
        if (isLyraSelected && typeof onCheckoutSuccess === 'function') {
            let unsubscribe = onCheckoutSuccess(async (payload) => {
                if (checkoutSuccessHandled) {
                    return { type: 'success' };
                }

                const result = await processPayment(payload);
                if (result?.type === 'success') {
                    checkoutSuccessHandled = true;
                } else {
                    // Re-open checkout if processing fails so the buyer can retry.
                    checkoutSuccessHandled = false;
                }

                return result;
            });

            if (typeof unsubscribe === 'function') {
                unsubscribes.push(unsubscribe);
            }
        }

        return () => {
            unsubscribes.forEach((unsubscribe) => unsubscribe());
        };
    }, [activePaymentMethod, onPaymentSetup, onCheckoutSuccess]);

    return (
        <div id="lyra_embedded_rest_wrapper">
            <div style={ styles.divWidth } className="kr-smart-form" kr-single-payment-button=""></div>
        </div>
    );
};

var Label = () => {
    return (
        <div style={ styles.divWidth }>
            <span>{ paymentMethodTitle }</span>
        </div>
    );
};

registerPaymentMethod({
    name: PAYMENT_METHOD_NAME,
    label: <Label />,
    ariaLabel: paymentMethodTitle,
    canMakePayment: () => isPaymentMethodConfigured,
    content: <Content />,
    edit: <Content />,
    supports: {
        features: paymentMethodSupports,
    },
});

var getProcessingFormConfig = function (payload) {
    var formConfig = payload?.processingResponse?.paymentDetails?.formConfig ?? payload?.paymentDetails?.formConfig ?? null;

    if (!formConfig) {
        return null;
    }

    if (typeof formConfig === 'string') {
        try {
            return JSON.parse(formConfig);
        } catch (error) {
            console.error('[Lyra Embedded] Invalid form configuration returned by WooCommerce Blocks.', error, formConfig);
            return null;
        }
    }

    return formConfig;
};

var isLyraPaymentMethodSelected = function (activePaymentMethod) {
    if (activePaymentMethod === PAYMENT_METHOD_NAME) {
        return true;
    }

    return jQuery("input[type=radio][name=radio-control-wc-payment-method-options]:checked").val() === PAYMENT_METHOD_NAME;
};

var displayFields = function () {
    var cancelled = false;

    // Wait for KR (Lyra SDK) to be ready before initialising events.
    var krInitAttempts = 0;
    var krMaxAttempts = 50; // 50 × 100 ms = 5 s max.

    function initWhenKrReady() {
        if (cancelled) {
            return;
        }

        if (typeof KR === 'undefined' || !KR) {
            if (++krInitAttempts < krMaxAttempts) {
                setTimeout(initWhenKrReady, 100);
            } else {
                console.error('[Lyra Embedded] KR SDK not available after 5 s.');
            }
            return;
        }

        if (typeof window.lyraEmbeddedInitRestEvents === 'function') {
            window.lyraEmbeddedInitRestEvents();
        }

        refreshTempToken();
    }

    initWhenKrReady();

    var observer = new MutationObserver(function(mutations) {
        mutations.forEach((mutation) => {
            if (mutation.addedNodes.length > 0) {
                mutation.addedNodes.forEach((node) => {
                    if (node.className == "wc-block-components-totals-item__value") {
                        refreshTempToken();
                    }
                })
            }
        });
    });

    var target = document.querySelector('.wc-block-components-totals-footer-item');
    if (target) {
        observer.observe(target, {characterData: true, childList: true, subtree: true});
    }

    return function() {
        cancelled = true;
        observer.disconnect();
    };
};

var processPayment = async function (payload) {
    var parsed = getProcessingFormConfig(payload);

    if (!parsed) {
        return {
            type: 'error',
            message: __('Unable to initialise the payment form.', 'woo-lyra-embedded-payment')
        };
    }

    if (typeof KR === 'undefined' || !KR || typeof KR.setFormConfig !== 'function') {
        console.error('[Lyra Embedded] KR SDK is unavailable when attempting to process the payment.', payload);
        return {
            type: 'error',
            message: __('Unable to load the payment form. Please refresh the page and try again.', 'woo-lyra-embedded-payment')
        };
    }

    try {
        var v = await KR.setFormConfig(parsed);
        KR = v.KR;

        if (typeof window.lyraEmbeddedSubmitPayment === 'function') {
            window.lyraEmbeddedSubmitPayment();
        }

        return { type: 'success' };
    } catch (error) {
        console.error('[Lyra Embedded] Unable to update KR form configuration.', error);
        directSubmitInProgress = false;

        return {
            type: 'error',
            message: __('Unable to submit your payment. Please try again.', 'woo-lyra-embedded-payment')
        };
    }
};

var validateForm = async function() {
   // After the first successful checkout cycle, submit payment directly
   // without going through onCheckoutSuccess again.
   if (checkoutSuccessHandled) {
       if (directSubmitInProgress) {
           return { type: 'error', message: '' };
       }

       if (typeof KR === 'undefined' || !KR) {
            console.error('[Lyra Embedded] KR SDK is unavailable during direct payment submission.');

            return {
                type: 'error',
                message: __('The payment form is not ready yet. Please wait a few seconds and try again.', 'woo-lyra-embedded-payment')
            };
       }

       if (typeof window.lyraEmbeddedSubmitPayment === 'function') {
           directSubmitInProgress = true;
           window.lyraEmbeddedSubmitPayment();
           if (typeof window.lyraEmbeddedClearBlocksPaymentNotice === 'function') {
               window.lyraEmbeddedClearBlocksPaymentNotice();
           }

           return { type: 'error', message: '' };
       }

       return {
            type: 'error',
            message: __('Unable to submit your payment. Please refresh the page and try again.', 'woo-lyra-embedded-payment')
        };
   }

   if (typeof KR === 'undefined' || !KR || typeof KR.validateForm !== 'function') {
        console.error('[Lyra Embedded] KR SDK is unavailable during payment validation.');
        return {
            type: 'error',
            message: __('The payment form is not ready yet. Please wait a few seconds and try again.', 'woo-lyra-embedded-payment')
        };
   }

   // After the first successful checkout cycle, submit payment directly
   // without going through onCheckoutSuccess again.
   if (checkoutSuccessHandled) {
       console.log('[Lyra Embedded] Subsequent Place Order click – submitting payment directly.');

       if (typeof window.lyraEmbeddedSubmitPayment === 'function') {
           window.lyraEmbeddedSubmitPayment();
       }

       // Return error with empty message to block the WooCommerce Blocks checkout flow.
       return { type: 'error', message: '' };
   }

   let isCard = KR?.$store?.state?.smartForm?.selectedMethod == 'CARDS';

   if (isCard) {
        try {
            await KR.validateForm();
            return { type: 'success' };
        } catch(v) {
            return {
                type: 'error',
                message: __('Please check your card details and try again.', 'woo-lyra-embedded-payment')
            };
        }
    }

   return { type: 'success' };
};

var refreshTempToken = function () {
    if (jQuery("#radio-control-wc-payment-method-options-" + PAYMENT_METHOD_NAME).is(":checked")) {
        if (!paymentMethodTokenUrl) {
            console.error('[Lyra Embedded] Missing temporary token URL for WooCommerce Blocks.');
            return;
        }

        jQuery.ajax({
            method: 'POST',
            url: paymentMethodTokenUrl,
            success: function(data) {
                var parsed = typeof data === 'string' ? JSON.parse(data) : data;

                if (parsed?.result !== 'success' || !parsed?.formConfig) {
                    console.error('[Lyra Embedded] Temporary token refresh failed.', parsed);
                    return;
                }

                if (typeof KR === 'undefined' || !KR || typeof KR.setFormConfig !== 'function') {
                    console.error('[Lyra Embedded] KR SDK is unavailable when refreshing the temporary token.');
                    return;
                }

                KR.setFormConfig(
                    parsed.formConfig
                ).then(function(v) {
                    KR = v.KR;
                }).catch(function(error) {
                    console.error('[Lyra Embedded] Unable to refresh KR form configuration.', error);
                });
            },
            error: function(xhr, status, error) {
                console.error('[Lyra Embedded] AJAX error while refreshing the temporary token.', status, error, xhr?.responseText);
            }
        });
    }
};
