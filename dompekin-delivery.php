<?php
/**
 * Plugin Name: Dompekin Delivery
 * Description: Управляемый вызов курьера Достависты после принятия оплаченного заказа.
 * Version: 0.7.4
 * Requires PHP: 7.4
 * Author: Dompekin
 */
if (!defined('ABSPATH')) { exit; }

// Защита от двойной загрузки: если копия плагина уже загружена, вторая не объявляет функции повторно.
if (defined('DPD_VERSION')) { return; }

define('DPD_VERSION', '0.7.4');
define('DPD_OPT', 'dpd_settings');
define('DPD_DIR', plugin_dir_path(__FILE__));

$dpd_includes = array('settings', 'geo-guard', 'address', 'geocode', 'origin', 'api', 'cms-api', 'lock', 'dispatch', 'cafe-bridge', 'callback', 'admin', 'diagnostics', 'shipping-diagnostics', 'quote', 'shipping-method', 'zone-setup', 'legacy-guard', 'content-fix', 'http-observer', 'telegram-finder');
foreach ($dpd_includes as $dpd_include) {
    $dpd_path = DPD_DIR . 'includes/' . $dpd_include . '.php';
    if (!is_readable($dpd_path)) {
        if (function_exists('error_log')) { error_log('Dompekin Delivery: отсутствует ' . $dpd_path); }
        return;
    }
    require_once $dpd_path;
}
unset($dpd_includes, $dpd_include, $dpd_path);

register_activation_hook(__FILE__, 'dpd_activate');

function dpd_activate() {
    if (!get_option(DPD_OPT)) {
        add_option(DPD_OPT, dpd_default_settings(), '', 'no');
    }
}

/** Ничего не выполняется до готовности WordPress и WooCommerce. */
function dpd_boot() {
    if (!class_exists('WooCommerce')) { return; }
    if (is_admin()) { dpd_register_admin(); }
    add_action('woocommerce_after_order_object_save', 'dpd_on_order_saved', 10, 2);
    add_action('added_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('updated_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('dpd_dispatch_order', 'dpd_dispatch_scheduled', 10, 2);
}
add_action('init', 'dpd_boot', 20);

add_action('rest_api_init', function () {
    if (class_exists('WooCommerce')) { dpd_register_callback(); }
});

