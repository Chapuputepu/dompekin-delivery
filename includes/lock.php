<?php
if (!defined('ABSPATH')) { exit; }

function dpd_lock_key($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) { return null; }
    $env = dpd_is_test() ? 'test' : 'prod';
    return 'dpd_lock_' . $env . '_' . $order_id;
}

/** Reserve an attempt. add_option relies on the unique option_name index. */
function dpd_lock_reserve($order_id, $token) {
    $key = dpd_lock_key($order_id);
    if ($key === null || !preg_match('/\A[a-f0-9]{32}\z/', (string) $token)) { return false; }
    $record = wp_json_encode(array('state' => 'reserved', 'token' => $token, 'at' => time()));
    return add_option($key, $record, '', 'no') === true;
}

function dpd_lock_read($order_id) {
    $key = dpd_lock_key($order_id);
    if ($key === null) { return null; }
    $raw = get_option($key, '');
    if (!is_string($raw) || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array('state' => 'unknown');
}

/** Compare-and-swap transition so only the holder of this exact state can proceed. */
function dpd_lock_transition($order_id, $token, $from, $to, $carrier_id = null) {
    global $wpdb;
    $key = dpd_lock_key($order_id);
    if ($key === null || !preg_match('/\A[a-f0-9]{32}\z/', (string) $token)) { return false; }
    $old = dpd_lock_read($order_id);
    if (!is_array($old) || ($old['state'] ?? '') !== $from || ($old['token'] ?? '') !== $token) { return false; }
    $next = array('state' => $to, 'token' => $token, 'at' => time());
    if (is_int($carrier_id) && $carrier_id > 0) { $next['carrier_id'] = $carrier_id; }
    $old_json = wp_json_encode($old);
    $new_json = wp_json_encode($next);
    $changed = $wpdb->update($wpdb->options, array('option_value' => $new_json), array('option_name' => $key, 'option_value' => $old_json), array('%s'), array('%s', '%s'));
    if ((int) $changed !== 1) { return false; }
    wp_cache_delete($key, 'options');
    return true;
}
