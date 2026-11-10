<?php
if (!defined('ABSPATH')) { exit; }

/**
 * API Достависты cms-module 1.0 — тот же контур, с которым работает официальный плагин.
 * Токен берём из его таблицы: владелец один раз входит в визард, дальше мы работаем сами.
 * Формат отличается от business/1.5: нужны вес, интервалы времени и contact_person{phone,name}.
 */

function dpd_cms_base_url() {
    return dpd_is_test()
        ? 'https://robotapitest.dostavista.ru/api/cms-module'
        : 'https://robot.dostavista.ru/api/cms-module';
}

/** Таблица официального плагина: имя без префикса, это его собственная схема. */
function dpd_cms_token() {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) { $cached = ''; return $cached; }
    $table = 'woo_dostavista_settings';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) { $cached = ''; return $cached; }

    $key = dpd_is_test()
        ? 'dostavista_cms_module_api_test_auth_token'
        : 'dostavista_cms_module_api_prod_auth_token';
    $value = $wpdb->get_var($wpdb->prepare("SELECT `value` FROM `{$table}` WHERE `key` = %s", $key));
    $cached = trim((string) $value);
    return $cached;
}

/** Какой контур использовать: введённый business-токен приоритетнее cms-токена плагина. */
function dpd_dispatch_channel() {
    if (dpd_active_token() !== '') { return 'business'; }
    if (dpd_cms_token() !== '') { return 'cms'; }
    return '';
}

/** Доступен ли cms-module API с сервера сайта. Без токена: только проверка связи. */
function dpd_ping_cms() {
    $url = dpd_cms_base_url() . '/1.0/generate-captcha-token';
    $args = array(
        'method' => 'POST',
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'limit_response_size' => 20000,
        'headers' => array('Content-Type' => 'application/json', 'User-Agent' => 'WooCommerce Dostavista plugin'),
        'body' => '{}',
    );
    try {
        $response = wp_safe_remote_request($url, $args);
    } catch (Throwable $e) {
        return array('ok' => false, 'code' => 0, 'detail' => 'Исключение: ' . $e->getMessage());
    }
    if (is_wp_error($response)) {
        return array('ok' => false, 'code' => 0, 'detail' => 'Нет связи: ' . $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $ok = ($code === 200 && is_array($data) && !empty($data['captcha_token']));
    return array(
        'ok' => $ok,
        'code' => $code,
        'detail' => $ok ? 'API отвечает' : ('HTTP ' . $code),
        'snippet' => function_exists('dpd_redact_text') ? dpd_redact_text(substr($body, 0, 300)) : substr($body, 0, 300),
        'url' => $url,
    );
}

function dpd_cms_request($path, $payload) {
    $allowed = array('calculate-order', 'create-order', 'orders');
    if (!in_array($path, $allowed, true)) { return new WP_Error('dpd_cms_path', 'Недопустимый метод API.'); }

    $token = dpd_cms_token();
    if ($token === '') {
        return new WP_Error('dpd_cms_token', 'Нет токена cms-module. Войдите в визард официального плагина Достависты.');
    }

    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > 65536) { return new WP_Error('dpd_cms_payload', 'Некорректный запрос.'); }

    $args = array(
        'method' => 'POST',
        'timeout' => 20,
        'redirection' => 0,
        'reject_unsafe_urls' => true,
        'sslverify' => true,
        'limit_response_size' => 200001,
        'headers' => array(
            'X-DV-Auth-Token' => $token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WooCommerce Dostavista plugin',
            'X-DPD-Plugin' => 'dompekin-delivery',
        ),
        'body' => $json,
    );

    try {
        $response = wp_safe_remote_request(dpd_cms_base_url() . '/1.0/' . $path, $args);
    } catch (Throwable $e) {
        return new WP_Error('dpd_cms_network', 'Нет связи с API Достависты.');
    }
    if (is_wp_error($response)) { return new WP_Error('dpd_cms_network', 'Нет связи с API Достависты.'); }

    return array(
        'status' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
    );
}

/** Общее ядро заявки cms-module 1.0: используется и для расчёта на чекауте, и для вызова курьера. */
function dpd_cms_payload_core($matter, $weight_kg, $vehicle_id, $origin, $dest_address, $dest_phone, $dest_name, $client_order_id = null) {
    $zone = function_exists('wp_timezone') ? wp_timezone() : null;
    $prep = function_exists('dpd_prep_minutes') ? dpd_prep_minutes() : 60;
    $start = new DateTime('now', $zone);
    $start->modify('+' . $prep . ' minutes');
    $finish = new DateTime('now', $zone);
    $finish->modify('+' . ($prep + 30) . ' minutes');

    $delivery = array(
        'point_type' => 'plain',
        'address' => $dest_address,
        'contact_person' => array('phone' => $dest_phone, 'name' => $dest_name),
        'required_start_datetime' => $start->format('c'),
        'required_finish_datetime' => $finish->format('c'),
    );
    if ($client_order_id !== null) { $delivery['client_order_id'] = (string) $client_order_id; }

    return array(
        'matter' => mb_substr($matter, 0, 400),
        'total_weight_kg' => max(1, (int) ceil($weight_kg)),
        'insurance_amount' => 0,
        'vehicle_type_id' => (int) $vehicle_id,
        // Оплата с баланса Достависты. Без этого поля API может выбрать наличные,
        // и курьер потребует деньги с клиента, который уже оплатил заказ онлайн.
        'payment_method' => 'non_cash',
        'points' => array(
            array(
                'point_type' => 'plain',
                'address' => $origin['address'],
                'contact_person' => array('phone' => $origin['phone'], 'name' => 'Dompekin'),
                'required_start_datetime' => $start->format('c'),
                'required_finish_datetime' => $finish->format('c'),
            ),
            $delivery,
        ),
    );
}

/** Заявка для конкретного заказа WooCommerce. */
function dpd_build_cms_payload($order, $origin, $vehicle_id) {
    $normalized = dpd_order_address($order);
    $address = $normalized['text'];
    if ($address === '') { return new WP_Error('dpd_cms_noaddress', 'В заказе нет адреса доставки.'); }

    $phone = trim((string) $order->get_billing_phone());
    if ($phone === '' && method_exists($order, 'get_shipping_phone')) {
        $phone = trim((string) $order->get_shipping_phone());
    }
    if ($phone === '') { return new WP_Error('dpd_cms_nophone', 'В заказе нет телефона получателя.'); }

    $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    if ($name === '') { $name = 'Получатель'; }

    $weight = 0.0;
    foreach ($order->get_items() as $item) {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        if ($product && method_exists($product, 'get_weight') && $product->get_weight() !== '') {
            $weight += (float) $product->get_weight() * (float) $item->get_quantity();
        }
    }

    return dpd_cms_payload_core('Заказ #' . $order->get_order_number(), $weight, $vehicle_id, $origin, $address, $phone, $name, $order->get_id());
}

/** Расчёт на чекауте: заказа ещё нет, берём адрес и вес корзины. */
function dpd_cms_quote_payload($address, $origin, $vehicle_id, $phone) {
    $weight = 0.0;
    if (function_exists('WC') && WC()->cart && method_exists(WC()->cart, 'get_cart_contents_weight')) {
        $weight = (float) WC()->cart->get_cart_contents_weight();
    }
    return dpd_cms_payload_core('Расчёт доставки', $weight, $vehicle_id, $origin, $address, $phone, 'Получатель');
}
