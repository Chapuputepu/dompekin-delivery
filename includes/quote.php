<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Цена доставки: три источника, по возрастанию точности.
 * fixed  — фиксированная цифра;
 * bands  — таблица «до_км | пеший | авто», работает без API;
 * live   — calculate-order Достависты с кэшем на 15 минут.
 * При любой ошибке цена не исчезает: берётся запасная (fallback).
 */

/** "145.00" -> 145.0; null, если формат не тот. */
function dpd_parse_amount($raw) {
    if (is_int($raw) || is_float($raw)) { return (float) $raw; }
    if (!is_string($raw)) { return null; }
    if (!preg_match('/\A(0|[1-9][0-9]{0,7})\.([0-9]{1,2})\z/', $raw, $m)) { return null; }
    $fraction = strlen($m[2]) === 1 ? (int) $m[2] * 10 : (int) $m[2];
    return (float) $m[1] + $fraction / 100.0;
}

/** Разбор таблицы: строки "2|250|350", "5|350|450". Комментарии с # игнорируются. */
function dpd_price_bands($raw) {
    $bands = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) { continue; }
        $max = (float) str_replace(',', '.', $parts[0]);
        $walk = (float) str_replace(',', '.', $parts[1]);
        $car = (float) str_replace(',', '.', $parts[2]);
        if ($max <= 0 || $walk < 0 || $car < 0) { continue; }
        $bands[] = array('max' => $max, 'walk' => $walk, 'car' => $car);
    }
    usort($bands, function ($a, $b) { return $a['max'] <=> $b['max']; });
    return $bands;
}

/** Цена по таблице; за пределами последней полосы — цена последней полосы. */
function dpd_price_from_bands($bands, $distance, $vehicle, $fallback) {
    if (empty($bands)) { return (float) $fallback; }
    $key = ((int) $vehicle === 6) ? 'walk' : 'car';
    foreach ($bands as $band) {
        if ($distance <= $band['max']) { return (float) $band[$key]; }
    }
    $last = end($bands);
    return (float) $last[$key];
}

/** Онлайн-расчёт Достависты с кэшем 15 минут. Никогда не бросает исключений. */
function dpd_quote_amount($address, $vehicle = 6) {
    $address = trim((string) $address);
    if ($address === '') { return array('state' => 'error', 'message' => 'Пустой адрес.'); }
    if (dpd_active_token() === '') { return array('state' => 'error', 'message' => 'Не задан токен Достависты для текущей среды.'); }

    $origin = dpd_origin();
    if (is_wp_error($origin)) { return array('state' => 'error', 'message' => $origin->get_error_message()); }

    $key = 'dpd_quote_' . md5($address . '|' . (int) $vehicle);
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['state'])) { return $cached; }

    $phone = '';
    if (function_exists('WC') && WC()->customer) {
        $phone = trim((string) WC()->customer->get_billing_phone());
    }
    if ($phone === '') { $phone = $origin['phone']; }

    $payload = array(
        'matter' => 'Расчёт доставки',
        'vehicle_type_id' => (int) $vehicle,
        'points' => array(
            array('address' => $origin['address'], 'contact_person' => array('phone' => $origin['phone'])),
            array('address' => $address, 'contact_person' => array('phone' => $phone)),
        ),
    );

    $response = dpd_api_request('calculate-order', $payload);
    if (is_wp_error($response)) {
        return array('state' => 'error', 'message' => $response->get_error_message());
    }
    $data = dpd_json($response);
    if (!is_array($data) || (($data['is_successful'] ?? null) !== true)) {
        $errors = array();
        if (is_array($data['errors'] ?? null)) {
            foreach (array_slice($data['errors'], 0, 3) as $error) {
                if (is_array($error)) {
                    $errors[] = trim(((string) ($error['code'] ?? '')) . ' ' . ((string) ($error['message'] ?? '')));
                } else {
                    $errors[] = (string) $error;
                }
            }
        }
        $message = $errors ? implode('; ', $errors) : ('HTTP ' . (int) ($response['status'] ?? 0));
        return array('state' => 'error', 'message' => 'Достависта: ' . $message);
    }

    $amount = dpd_parse_amount($data['order']['payment_amount'] ?? null);
    if ($amount === null) { return array('state' => 'error', 'message' => 'Не удалось разобрать сумму расчёта.'); }

    $out = array('state' => 'ok', 'amount' => $amount);
    set_transient($key, $out, 15 * MINUTE_IN_SECONDS);
    return $out;
}

/** Полная расшифровка для панели: расстояние, транспорт, онлайн-цифра и таблица. */
function dpd_price_probe($address, $bands_raw, $mode, $fallback) {
    $out = array('address' => $address, 'mode' => $mode, 'fallback' => (float) $fallback);
    $distance = function_exists('dpd_distance_km') ? dpd_distance_km($address) : new WP_Error('dpd_geo_missing', 'Геокодер недоступен.');
    if (is_wp_error($distance)) {
        $out['distance'] = null;
        $out['distance_error'] = $distance->get_error_message();
        $out['vehicle'] = null;
    } else {
        $out['distance'] = (float) $distance;
        $out['distance_error'] = '';
        $out['vehicle'] = function_exists('dpd_vehicle_for') ? (int) dpd_vehicle_for($distance) : 7;
    }
    $bands = dpd_price_bands($bands_raw);
    $out['bands_count'] = count($bands);
    $out['bands_price'] = $out['distance'] === null ? null : dpd_price_from_bands($bands, $out['distance'], $out['vehicle'], $fallback);
    $out['quote'] = $out['distance'] === null ? array('state' => 'skipped') : dpd_quote_amount($address, $out['vehicle']);
    return $out;
}
