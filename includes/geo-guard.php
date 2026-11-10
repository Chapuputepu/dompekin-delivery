<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Защита от падения WooCommerce на геолокации.
 * WooCommerce просит внешний IP у api.ipify.org. Если запрос не проходит,
 * возвращается null, а WordPress 7.x в rest_is_ip_address(null) бросает
 * InvalidArgument — необработанное исключение и 500 на всём фронте.
 * Подставляем безопасный адрес и один раз переводим определение местоположения
 * на адрес магазина: для локальной доставки внешний IP не нужен.
 */

add_filter('woocommerce_geolocation_ip_address', 'dpd_geo_guard_ip', 5);
function dpd_geo_guard_ip($ip) {
    return $ip ? $ip : '127.0.0.1';
}

add_filter('woocommerce_geolocation_ip_address_result', 'dpd_geo_guard_result', 5);
function dpd_geo_guard_result($result) {
    return is_string($result) ? $result : '127.0.0.1';
}

add_action('admin_init', 'dpd_geo_default_location', 9);
function dpd_geo_default_location() {
    if (get_option('dpd_geo_base_migrated_v1')) { return; }
    if (get_option('woocommerce_default_customer_address') === 'base') {
        update_option('dpd_geo_base_migrated_v1', 1, false);
        return;
    }
    update_option('woocommerce_default_customer_address', 'base');
    update_option('dpd_geo_base_migrated_v1', 1, false);
}
