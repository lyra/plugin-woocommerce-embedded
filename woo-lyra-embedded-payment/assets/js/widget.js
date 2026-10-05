/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
*/

(function ($) {
    'use strict';

    const epsilonBaseUrl = (window.lyraEmbeddedData && window.lyraEmbeddedData.epsilonUrl) ? window.lyraEmbeddedData.epsilonUrl : '';
    const widgetRequestUrl = window.lyraEmbeddedData ? window.lyraEmbeddedData.widgetRequestUrl : '';
    const widgetRequestNonce = window.lyraEmbeddedData ? window.lyraEmbeddedData.widgetRequestNonce : '';
    const isWhiteLabelAll = (window.lyraEmbeddedData && window.lyraEmbeddedData.isWhiteLabelAll);
    const widgetLocale = (window.lyraEmbeddedData && window.lyraEmbeddedData.locale)
        ? window.lyraEmbeddedData.locale
        : (document.documentElement.lang || 'en').replace('_', '-');

    function loadAssetOnce(selector, createElement) {
        return new Promise(function (resolve, reject) {
            const existing = document.querySelector(selector);
            if (existing) {
                if (existing.tagName === 'SCRIPT' && existing.dataset.loaded !== 'true') {
                    existing.addEventListener('load', function () {
                        resolve(existing);
                    }, { once: true });
                    existing.addEventListener('error', function () {
                        reject(new Error('Unable to load Epsilon script.'));
                    }, { once: true });
                    return;
                }

                resolve(existing);
                return;
            }

            const element = createElement();
            element.addEventListener('load', function () {
                if (element.tagName === 'SCRIPT') {
                    element.dataset.loaded = 'true';
                }
                resolve(element);
            }, { once: true });
            element.addEventListener('error', function () {
                reject(new Error('Unable to load Epsilon asset.'));
            }, { once: true });

            document.head.appendChild(element);
        });
    }

    function loadEpsilonAssets() {
        if (!epsilonBaseUrl) {
            return Promise.reject(new Error('Missing Epsilon base URL.'));
        }

        const stylesheetPromise = loadAssetOnce('link[data-lyra-epsilon="css"]', function () {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = epsilonBaseUrl + 'epsilon.css';
            link.setAttribute('data-lyra-epsilon', 'css');

            return link;
        });

        const scriptPromise = loadAssetOnce('script[data-lyra-epsilon="js"]', function () {
            const script = document.createElement('script');
            script.type = 'module';
            script.src = epsilonBaseUrl + 'epsilon.js';
            script.setAttribute('data-lyra-epsilon', 'js');

            return script;
        });

        return Promise.all([stylesheetPromise, scriptPromise]);
    }

    function postWidgetAction(action, payload = null) {
        return new Promise((resolve, reject) => {
            $.ajax({
                method: 'POST',
                url: widgetRequestUrl,
                contentType: 'application/json; charset=utf-8',
                dataType: 'json',
                processData: false,
                data: JSON.stringify({
                    action: action,
                    nonce: widgetRequestNonce,
                    data: payload
                }),
                success: resolve,
                error: reject,
            });
        });
    }

    async function saveModels(modelConfig) {
        return postWidgetAction('saveModels', modelConfig);
    }

    async function getWidgetToken(model) {
        const response = await postWidgetAction('getWidgetToken', model);

        return response.formToken;
    }

    async function initWidget() {
        if (typeof window.epsilon === 'undefined') {
            console.error('Widget JS not loaded or window.epsilon not found.');

            return;
        }

        // Resolves once getModels + setModels have completed, so open() is never called before models are set.
        let resolveReady;
        const readyPromise = new Promise(function (resolve) { resolveReady = resolve; });

        // Bind the click handler immediately so clicks during initialisation are never missed,
        // but defer open() until models are loaded.
        $('#widget_button').on('click', async function () {
            await readyPromise;

            if (typeof window.epsilon.open === 'function') {
                window.epsilon.open();
            } else {
                console.error('window.epsilon.open not found.');
            }
        });

        try {
            const response = await postWidgetAction('getModels');

            if (response && response.models && typeof window.epsilon.setModels === 'function') {
                const models = (typeof response.models === 'string') ? JSON.parse(response.models) : response.models;
                window.epsilon.setModels(models);
            }
        } catch (error) {
            console.error('Failed to load models.', error);
        } finally {
            resolveReady();
        }

        if (typeof window.epsilon.setLocale === 'function') {
            window.epsilon.setLocale(widgetLocale);
        }

        if (typeof window.epsilon.onModelsUpdate === 'function') {
            window.epsilon.onModelsUpdate(async (modelsConfig) => {
                await saveModels(modelsConfig);
            });
        }

        if (typeof window.epsilon.onFormTokenRequest === 'function') {
            window.epsilon.onFormTokenRequest(async (model) => {
                return await getWidgetToken(model);
            });
        }

        if (typeof window.epsilon.showPanelElement === 'function') {
            window.epsilon.showPanelElement('cmsPaymentMethodLabel');

            if (isWhiteLabelAll) {
                window.epsilon.showPanelElement('payzenWhiteLabel');
            }
        }
    }

    $(document).ready(function () {
        loadEpsilonAssets().then(initWidget).catch(function (error) {
            console.error(error);
        });
    });
})(jQuery);
