<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Embedded plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

spl_autoload_register('lyraEmbeddedSdkAutoload', true, true);

/**
 * Autoload classes from the Lyra Embedded SDK namespace.
 *
 * @param  string $className Fully-qualified class name.
 * @return void
 */
function lyraEmbeddedSdkAutoload($className)
{
    if (empty($className) || strpos($className, 'Lyranetwork\\LyraEmbedded\\Sdk') !== 0) {
        // Not Lyra SDK classes.
        return;
    }

    $className = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $className);
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . $className . '.php';
}