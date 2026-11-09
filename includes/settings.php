<?php
if (!defined('ABSPATH')) { exit; }

function dpd_default_settings() {
    return array(
        'enabled' => false,          // explicit opt-in
        'environment' => 'test',     // test | production
        'token_test' => '',
        'token' => '',
        'callback_secret' => '',
        'restaurant_address' => '',   // pickup point address for Dostavista
        'restaurant_phone' => '',
        'walk_max_km' => '2.0',
        'delay_minutes' => '0',
    );
}

function dpd_settings() {
    $stored = get_option(DPD_OPT, array());
    if (!is_array($stored)) { $stored = array(); }
    return array_merge(dpd_default_settings(), $stored);
}

function dpd_is_test() {
    return dpd_settings()['environment'] !== 'production';
}

function dpd_active_token() {
    $s = dpd_settings();
    $raw = dpd_is_test() ? $s['token_test'] : $s['token'];
    $token = is_string($raw) ? trim($raw) : '';
    if ($token === '' || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token)) { return ''; }
    return $token;
}

function dpd_enabled() {
    return (bool) dpd_settings()['enabled'];
}

function dpd_walk_max_km() {
    $value = (float) dpd_settings()['walk_max_km'];
    return ($value > 0 && $value <= 20) ? $value : 2.0;
}
