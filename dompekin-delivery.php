<?php
/**
 * Plugin Name: Dompekin Delivery
 * Description: РЈРїСЂР°РІР»СЏРµРјС‹Р№ РІС‹Р·РѕРІ РєСѓСЂСЊРµСЂР° Р”РѕСЃС‚Р°РІРёСЃС‚С‹ РїРѕСЃР»Рµ РїСЂРёРЅСЏС‚РёСЏ РѕРїР»Р°С‡РµРЅРЅРѕРіРѕ Р·Р°РєР°Р·Р°.
 * Version: 0.1.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

define('DPD_VERSION', '0.1.3');
define('DPD_OPT', 'dpd_settings');
define('DPD_DIR', plugin_dir_path(__FILE__));

require_once DPD_DIR . 'includes/settings.php';
require_once DPD_DIR . 'includes/origin.php';
require_once DPD_DIR . 'includes/api.php';
require_once DPD_DIR . 'includes/lock.php';
require_once DPD_DIR . 'includes/dispatch.php';
require_once DPD_DIR . 'includes/diagnostics.php';
require_once DPD_DIR . 'includes/callback.php';
require_once DPD_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, function () {
    if (!get_option(DPD_OPT)) {
        // Safe defaults: disabled, test environment, no token.
        add_option(DPD_OPT, dpd_default_settings(), '', 'no');
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) { return; }
    dpd_register_admin();
    dpd_register_callback();
    // The theme marks acceptance with _dp_stage=cooking. Hook only after the meta is saved.
    add_action('added_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('updated_post_meta', 'dpd_on_stage_meta', 10, 4);
    add_action('dpd_dispatch_order', 'dpd_run_dispatch', 10, 1);
});



