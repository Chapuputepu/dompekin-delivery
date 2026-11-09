<?php
if (!defined('ABSPATH')) { exit; }

/** Ключ кэша геокодера темы (совпадает с тем, что использует функция темы). */
function dpd_geo_cache_key($address) {
    $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($address)) : strtolower(trim($address));
    return 'dp_geo_' . md5($normalized);
}

function dpd_cached_geocode_state($address) {
    $value = get_transient(dpd_geo_cache_key($address));
    if ($value === false) { return null; }
    if (is_array($value) && array_key_exists('found', $value)) {
        return array('found' => (bool) $value['found']);
    }
    return array('found' => null);
}

function dpd_clear_address_cache($address) {
    return delete_transient(dpd_geo_cache_key($address));
}

/** Один прямой вызов геокодера темы, без повторов. */
function dpd_geocode_once($address) {
    if (!function_exists('dp_zone_geocode')) { return array('state' => 'missing_function'); }
    $result = dp_zone_geocode($address);
    if (is_wp_error($result)) { return array('state' => 'wp_error', 'code' => $result->get_error_code(), 'message' => $result->get_error_message()); }
    if (!is_array($result)) { return array('state' => 'bad_shape'); }
    if (empty($result['found'])) { return array('state' => 'not_found'); }
    return array(
        'state' => 'found',
        'lat' => isset($result['lat']) ? (float) $result['lat'] : null,
        'lon' => isset($result['lon']) ? (float) $result['lon'] : null,
        'precision' => isset($result['precision']) && is_string($result['precision']) ? $result['precision'] : null,
    );
}

/**
 * Геокодирование со сбросом отрицательного кэша.
 * Работает и без темы: если функции нет — сразу missing_function, без фатальной ошибки.
 */
function dpd_geocode_report($address) {
    if (!function_exists('dp_zone_geocode')) { return array('state' => 'missing_function'); }
    $first = dpd_geocode_once($address);
    if ($first['state'] === 'found') { $first['attempts'] = 1; return $first; }
    if ($first['state'] === 'wp_error' && $first['code'] !== 'geo_http') {
        // Ошибка ключа/лимита: сброс кэша её не лечит, не тратим второй запрос.
        $first['attempts'] = 1;
        return $first;
    }
    $cleared = dpd_clear_address_cache($address);
    $second = dpd_geocode_once($address);
    $second['attempts'] = 2;
    $second['cache_cleared'] = (bool) $cleared;
    return $second;
}
