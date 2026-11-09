<?php
/**
 * Plugin Name: Dompekin Delivery
 * Description: РЈРїСЂР°РІР»СЏРµРјС‹Р№ РІС‹Р·РѕРІ РєСѓСЂСЊРµСЂР° Р”РѕСЃС‚Р°РІРёСЃС‚С‹ РїРѕСЃР»Рµ РїСЂРёРЅСЏС‚РёСЏ РѕРїР»Р°С‡РµРЅРЅРѕРіРѕ Р·Р°РєР°Р·Р°.
 * Version: 0.3.1
 * Requires PHP: 7.4
 * Author: Dompekin
 */
if (!defined('ABSPATH')) { exit; }

// Р—Р°С‰РёС‚Р° РѕС‚ РґРІРѕР№РЅРѕР№ Р·Р°РіСЂСѓР·РєРё: РµСЃР»Рё РєРѕРїРёСЏ РїР»Р°РіРёРЅР° СѓР¶Рµ Р·Р°РіСЂСѓР¶РµРЅР°, РІС‚РѕСЂР°СЏ РЅРµ РѕР±СЉСЏРІР»СЏРµС‚ С„СѓРЅРєС†РёРё РїРѕРІС‚РѕСЂРЅРѕ.
if (defined('DPD_VERSION')) { return; }

define('DPD_VERSION', '0.3.1');
define('DPD_OPT', 'dpd_settings');
define('DPD_DIR', plugin_dir_path(__FILE__));

$dpd_includes = array('settings', 'address', 'origin', 'api', 'lock', 'dispatch', 'callback', 'admin', 'diagnostics');
foreach ($dpd_includes as $dpd_include) {
    $dpd_path = DPD_DIR . 'includes/' . $dpd_include . '.php';
    if (!is_readable($dpd_path)) {
        if (function_exists('error_log')) { error_log('Dompekin Delivery: РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ ' . $dpd_path); }
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

/** РќРёС‡РµРіРѕ РЅРµ РІС‹РїРѕР»РЅСЏРµС‚СЃСЏ РґРѕ РіРѕС‚РѕРІРЅРѕСЃС‚Рё WordPress Рё WooCommerce. */
function dpd_boot() {
    if (!class_exists('WooCommerce')) { return; }
    if (is_admin()) { dpd_register_admin(); }
    add_action('added_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('updated_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('dpd_dispatch_order', 'dpd_run_dispatch', 10, 1);
}
add_action('init', 'dpd_boot', 20);

add_action('rest_api_init', function () {
    if (class_exists('WooCommerce')) { dpd_register_callback(); }
});

