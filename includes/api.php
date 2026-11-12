<?php
if (!defined('ABSPATH')) { exit; }

function dpd_api_host() {
    return dpd_is_test() ? 'https://robotapitest.dostavista.ru' : 'https://robot.dostavista.ru';
}

function dpd_api_url($path) {
    $allowed = array('calculate-order', 'create-order', 'orders');
    if (!in_array($path, $allowed, true)) { return null; }
    return dpd_api_host() . '/api/business/1.5/' . $path;
}

/** Returns array(status, body) or WP_Error. Never follows redirects, never logs payloads. */
function dpd_api_request($path, $payload = null, $method = 'POST') {
    $token = dpd_active_token();
    if ($token === '') { return new WP_Error('dpd_nokey', 'Не задан токен Достависты для текущей среды.'); }
    $url = dpd_api_url($path);
    if ($url === null) { return new WP_Error('dpd_badpath', 'Недопустимый метод API.'); }
    $args = array(
        'method' => $method,
        'timeout' => 20,
        'redirection' => 0,
        'reject_unsafe_urls' => true,
        'sslverify' => true,
        'limit_response_size' => 200001,
        'headers' => array('X-DV-Auth-Token' => $token, 'Content-Type' => 'application/json', 'X-DPD-Plugin' => 'dompekin-delivery'),
    );
    if ($payload !== null) {
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 65536) { return new WP_Error('dpd_payload', 'Некорректный запрос.'); }
        $args['body'] = $json;
    }
    try {
        $response = wp_safe_remote_request($url, $args);
    } catch (Throwable $e) {
        return new WP_Error('dpd_network', 'Нет связи с API Достависты.');
    }
    if (is_wp_error($response)) { return new WP_Error('dpd_network', 'Нет связи с API Достависты.'); }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if (strlen($body) > 200000) { return new WP_Error('dpd_response', 'Слишком большой ответ API.'); }
    return array('status' => $status, 'body' => $body);
}

function dpd_json($response) {
    if (is_wp_error($response) || !is_array($response)) { return null; }
    if ($response['status'] !== 200) { return null; }
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data : null;
}

function dpd_amount_minor($amount) {
    if (!is_string($amount) || !preg_match('/\A(0|[1-9][0-9]{0,7})\.([0-9]{2})\z/', $amount, $m)) { return null; }
    return ((int) $m[1] * 100) + (int) $m[2];
}

/** Builds the documented minimal standard order. */
function dpd_build_payload($order, $origin, $vehicle_id) {
    $normalized = dpd_order_address($order);
    $address = $normalized['text'];
    $phone = trim((string) $order->get_billing_phone());
    if ($phone === '' && method_exists($order, 'get_shipping_phone')) { $phone = trim((string) $order->get_shipping_phone()); }
    if ($address === '' || $phone === '') { return new WP_Error('dpd_missing', 'В заказе нет адреса или телефона получателя.'); }
    if (strlen($address) > 1000 || strlen($phone) > 80) { return new WP_Error('dpd_missing', 'Адрес или телефон слишком длинные.'); }
    $matter = 'Заказ #' . $order->get_order_number();
    $zone = function_exists('wp_timezone') ? wp_timezone() : null;
    $prep = function_exists('dpd_prep_minutes') ? dpd_prep_minutes() : 60;
    $start = new DateTime('now', $zone);
    $start->modify('+' . $prep . ' minutes');
    $finish = clone $start;
    $finish->modify('+30 minutes');
    return array(
        'matter' => mb_substr($matter, 0, 400),
        'vehicle_type_id' => $vehicle_id,
        'points' => array(
            array('address' => $origin['address'], 'contact_person' => array('phone' => $origin['phone']), 'required_start_datetime' => $start->format('c'), 'required_finish_datetime' => $finish->format('c')),
            array('address' => $address, 'contact_person' => array('phone' => $phone)),
        ),
    );
}
