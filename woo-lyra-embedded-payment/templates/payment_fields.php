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
    exit;
}
?>

<div id="lyra_embedded_rest_wrapper">
    <div style="width: 85%;" class="kr-smart-form" <?php echo $smartform_attributes; ?>></div>
</div>

<script type="application/json" class="lyra-embedded-payment-fields-data"><?php
echo wp_json_encode([
    'formConfig' => $form_config,
]);
?></script>

