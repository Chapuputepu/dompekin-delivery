<?php
if (!defined('ABSPATH')) { exit; }

function dpd_register_callback() {
    register_rest_route('dpd/v1', '/callback', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true', // authenticated by HMAC signature below
        'callback' => 'dpd_handle_callback',
    ));
}

function dpd_handle_callback($request) {
    $secret = trim((string) dpd_settings()['callback_secret']);
    if ($secret === '' || strlen($secret) < 16) { return new WP_REST_Response(array('ok' => false), 503); }
    $raw = $request->get_body();
    if (!is_string($raw) || $raw === '' || strlen($raw) > 262144) { return new WP_REST_Response(array('ok' => false), 400); }
    $signature = (string) $request->get_header('x-dv-signature');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $signature) || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        return new WP_REST_Response(array('ok' => false), 401);
    }
    $data = json_decode($raw, true);
    $carrier_id = is_array($data) ? ($data['order']['order_id'] ?? null) : null;
    if (!is_int($carrier_id) || $carrier_id <= 0) { return new WP_REST_Response(array('ok' => false), 400); }

    $order = dpd_find_order_by_carrier($carrier_id);
    if (!$order) { return new WP_REST_Response(array('ok' => true, 'ignored' => 'unknown_carrier_id')); }

    $status = $data['order']['status'] ?? null;
    $delivery = $data['order']['delivery'] ?? null;
    if (is_array($delivery) && isset($delivery['status'])) { $status = $delivery['status']; }
    if (!is_string($status)) { return new WP_REST_Response(array('ok' => true, 'ignored' => 'no_status')); }

    $state = $order->get_meta('_dpd_state');
    $order->add_order_note(sprintf('Dompekin Delivery: статус Достависты «%s».', sanitize_text_field($status)));
    if ($status === 'finished') {
        // Recorded for operator review: completion requires confirmed delivery, not just this event.
        $order->update_meta_data('_dpd_delivery_reported', 'finished');
        $order->add_order_note('Dompekin Delivery: сервис сообщил о завершении доставки. Заказ не завершается автоматически без подтверждения оператора.');
    } elseif (in_array($status, array('canceled', 'delayed', 'deleted'), true)) {
        $order->update_meta_data('_dpd_delivery_problem', $status);
    }
    $order->save();
    return new WP_REST_Response(array('ok' => true), 200);
}

function dpd_find_order_by_carrier($carrier_id) {
    $orders = wc_get_orders(array(
        'limit' => 1,
        'meta_key' => '_dpd_carrier_id',
        'meta_value' => (int) $carrier_id,
        'return' => 'objects',
    ));
    return (is_array($orders) && isset($orders[0]) && $orders[0] instanceof WC_Order) ? $orders[0] : null;
}
