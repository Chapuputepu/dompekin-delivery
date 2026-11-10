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
        'pickup_enabled' => false,
        'dispatch_mode' => 'external',
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

/** Самовывоз выключен по решению владельца: сайт работает только на доставку. */
function dpd_pickup_enabled() {
    return (bool) dpd_settings()['pickup_enabled'];
}

/**
 * Кто создаёт заявки: external — официальный плагин Достависты (по умолчанию),
 * own — наш прямой клиент. Два писателя одновременно запрещены: это дубли.
 */
function dpd_dispatch_is_external() {
    return (string) dpd_settings()['dispatch_mode'] !== 'own';
}
