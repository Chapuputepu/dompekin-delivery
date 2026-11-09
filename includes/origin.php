<?php
if (!defined('ABSPATH')) { exit; }

/** Pickup point from explicit settings only; never guess the restaurant address. */
function dpd_origin() {
    $s = dpd_settings();
    $address = trim((string) $s['restaurant_address']);
    $phone = trim((string) $s['restaurant_phone']);
    if ($address === '' || $phone === '') {
        return new WP_Error('dpd_origin_missing', 'Не заполнен адрес и телефон ресторана в настройках плагина.');
    }
    return array('address' => mb_substr($address, 0, 1000), 'phone' => mb_substr($phone, 0, 80));
}

/** Restaurant coordinates: theme helper first, otherwise geocode the configured address. */
function dpd_restaurant_point() {
    if (function_exists('dp_dv_origin')) {
        $origin = dp_dv_origin();
        if (is_array($origin) && isset($origin['lat'], $origin['lon']) && is_numeric($origin['lat']) && is_numeric($origin['lon'])) {
            return array('lat' => (float) $origin['lat'], 'lon' => (float) $origin['lon']);
        }
    }
    $address = trim((string) dpd_settings()['restaurant_address']);
    if ($address === '' || !function_exists('dp_zone_geocode')) { return new WP_Error('dpd_origin', 'Не удалось определить координаты ресторана.'); }
    $geo = dp_zone_geocode($address);
    if (is_wp_error($geo) || !is_array($geo) || empty($geo['found'])) { return new WP_Error('dpd_origin', 'Не удалось определить координаты ресторана.'); }
    return array('lat' => (float) $geo['lat'], 'lon' => (float) $geo['lon']);
}

/** Distance in km, or WP_Error with a specific reason. No guessing on failure. */
function dpd_distance_km($address) {
    if (!function_exists('dp_zone_geocode')) { return new WP_Error('dpd_geo_missing', 'Геокодер темы недоступен.'); }
    $origin = dpd_restaurant_point();
    if (is_wp_error($origin)) { return $origin; }
    // dpd_geocode_report повторяет запрос после сброса отрицательного кэша.
    $report = dpd_geocode_report($address);
    if ($report['state'] === 'wp_error') { return new WP_Error('dpd_geo_error', 'Геокодер вернул ошибку: ' . $report['code'] . '. Адрес клиента ни при чём.'); }
    if ($report['state'] === 'missing_function') { return new WP_Error('dpd_geo_missing', 'Геокодер темы недоступен.'); }
    if ($report['state'] !== 'found') { return new WP_Error('dpd_geo_notfound', 'Геокодер не подтвердил адрес доставки. Проверьте адрес: ' . $address); }
    $geo = $report;
    $lat1 = deg2rad($origin['lat']); $lat2 = deg2rad((float) $geo['lat']);
    $dLat = $lat2 - $lat1; $dLon = deg2rad((float) $geo['lon'] - $origin['lon']);
    $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    return 2 * 6371 * asin(min(1, sqrt(max(0, $a))));
}

function dpd_vehicle_for($distance) {
    // 6 — пеший, 7 — автомобильный; порог задаётся в настройках.
    return ($distance <= dpd_walk_max_km()) ? 6 : 7;
}
