/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

(function () {
    var data = window.lyraEmbeddedData || {};
    var staticUrl = data.staticUrl || '';
    var publicKey = data.publicKey || '';
    var returnUrl = data.returnUrl || '';
    var language = data.language || '';
    var restTheme = data.restTheme || 'neon';

    // Inject the Krypton client script with payment attributes.
    var krScript = document.createElement('script');

    krScript.type = 'text/javascript';
    krScript.src = staticUrl + 'js/krypton-client/V4.0/stable/kr-payment-form.min.js';
    krScript.setAttribute('kr-public-key', publicKey);
    krScript.setAttribute('kr-post-url-success', returnUrl);
    krScript.setAttribute('kr-post-url-refused', returnUrl);
    krScript.setAttribute('kr-language', language);
    document.head.appendChild(krScript);

    // Inject the theme reset stylesheet.
    var themeLink = document.createElement('link');
    themeLink.rel = 'stylesheet';
    themeLink.href = staticUrl + 'js/krypton-client/V4.0/ext/' + restTheme + '-reset.min.css';
    document.head.appendChild(themeLink);

    // Inject the theme script.
    var themeScript = document.createElement('script');
    themeScript.type = 'text/javascript';
    themeScript.src = staticUrl + 'js/krypton-client/V4.0/ext/' + restTheme + '.js';
    document.head.appendChild(themeScript);
})();
