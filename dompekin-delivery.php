<?php
/**
 * Plugin Name: Dompekin Delivery
 * Description: Р Р€Р С—РЎР‚Р В°Р Р†Р В»РЎРЏР ВµР СРЎвЂ№Р в„– Р Р†РЎвЂ№Р В·Р С•Р Р† Р С”РЎС“РЎР‚РЎРЉР ВµРЎР‚Р В° Р вЂќР С•РЎРѓРЎвЂљР В°Р Р†Р С‘РЎРѓРЎвЂљРЎвЂ№ Р С—Р С•РЎРѓР В»Р Вµ Р С—РЎР‚Р С‘Р Р…РЎРЏРЎвЂљР С‘РЎРЏ Р С•Р С—Р В»Р В°РЎвЂЎР ВµР Р…Р Р…Р С•Р С–Р С• Р В·Р В°Р С”Р В°Р В·Р В°.
 * Version: 0.3.6
 * Requires PHP: 7.4
 * Author: Dompekin
 */
if (!defined('ABSPATH')) { exit; }

// Р вЂ”Р В°РЎвЂ°Р С‘РЎвЂљР В° Р С•РЎвЂљ Р Т‘Р Р†Р С•Р в„–Р Р…Р С•Р в„– Р В·Р В°Р С–РЎР‚РЎС“Р В·Р С”Р С‘: Р ВµРЎРѓР В»Р С‘ Р С”Р С•Р С—Р С‘РЎРЏ Р С—Р В»Р В°Р С–Р С‘Р Р…Р В° РЎС“Р В¶Р Вµ Р В·Р В°Р С–РЎР‚РЎС“Р В¶Р ВµР Р…Р В°, Р Р†РЎвЂљР С•РЎР‚Р В°РЎРЏ Р Р…Р Вµ Р С•Р В±РЎР‰РЎРЏР Р†Р В»РЎРЏР ВµРЎвЂљ РЎвЂћРЎС“Р Р…Р С”РЎвЂ Р С‘Р С‘ Р С—Р С•Р Р†РЎвЂљР С•РЎР‚Р Р…Р С•.
if (defined('DPD_VERSION')) { return; }

define('DPD_VERSION', '0.3.6');
define('DPD_OPT', 'dpd_settings');
define('DPD_DIR', plugin_dir_path(__FILE__));

$dpd_includes = array('settings', 'address', 'geocode', 'origin', 'api', 'lock', 'dispatch', 'callback', 'admin', 'diagnostics', 'shipping-diagnostics');
foreach ($dpd_includes as $dpd_include) {
    $dpd_path = DPD_DIR . 'includes/' . $dpd_include . '.php';
    if (!is_readable($dpd_path)) {
        if (function_exists('error_log')) { error_log('Dompekin Delivery: Р С•РЎвЂљРЎРѓРЎС“РЎвЂљРЎРѓРЎвЂљР Р†РЎС“Р ВµРЎвЂљ ' . $dpd_path); }
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

/** Р СњР С‘РЎвЂЎР ВµР С–Р С• Р Р…Р Вµ Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…РЎРЏР ВµРЎвЂљРЎРѓРЎРЏ Р Т‘Р С• Р С–Р С•РЎвЂљР С•Р Р†Р Р…Р С•РЎРѓРЎвЂљР С‘ WordPress Р С‘ WooCommerce. */
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

