<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Приведение зоны к рабочему состоянию.
 * Самовывоз по умолчанию выключен: сайт работает только на доставку.
 * Курьерский тариф добавляется, если его нет. Проверка не чаще раза в 6 часов.
 */

function dpd_zone_defaults() {
    return array(
        'pickup_title' => 'Самовывоз — Мичурина, 12',
        'pickup_address' => 'Новосибирск, Мичурина 12',
        'courier_title' => 'Доставка курьером',
        'courier_cost' => '250',
        'courier_free_from' => '4000',
        'courier_city' => 'Новосибирск',
    );
}

add_action('admin_init', 'dpd_maybe_provision_zone');
// Тот же контроль на обычной загрузке: после деплоя настройка применяется без визита в админку.
// Частоту ограничивает transient-замок, пользовательского ввода здесь нет.
add_action('wp_loaded', 'dpd_maybe_provision_zone', 20);

function dpd_zone_setup_state() {
    $state = get_option('dpd_zone_setup_state', array());
    return is_array($state) ? $state : array();
}

/** Находит целевую зону: по названию, иначе первую зону с Россией. */
function dpd_target_zone() {
    if (!class_exists('WC_Shipping_Zones')) { return null; }

    $zones = WC_Shipping_Zones::get_zones();
    foreach ($zones as $row) {
        $name = isset($row['zone_name']) ? (string) $row['zone_name'] : '';
        if (preg_match('/Новосибирск/iu', $name)) {
            return WC_Shipping_Zones::get_zone((int) $row['zone_id']);
        }
    }
    foreach ($zones as $row) {
        $zone = WC_Shipping_Zones::get_zone((int) $row['zone_id']);
        if (!$zone) { continue; }
        foreach ($zone->get_zone_locations() as $loc) {
            if ($loc->type === 'country' && $loc->code === 'RU') { return $zone; }
        }
    }
    return null;
}

function dpd_zone_has_method($zone, $method_id) {
    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id === $method_id) { return true; }
    }
    return false;
}

function dpd_add_pickup_to_zone($zone) {
    $d = dpd_zone_defaults();
    $instance_id = $zone->add_shipping_method('local_pickup');
    if (!$instance_id) { return false; }
    update_option('woocommerce_local_pickup_' . (int) $instance_id . '_settings', array(
        'title' => $d['pickup_title'],
        'tax_status' => 'none',
        'cost' => '0',
        'address' => $d['pickup_address'],
    ), false);
    return true;
}

function dpd_add_courier_to_zone($zone) {
    $d = dpd_zone_defaults();
    $instance_id = $zone->add_shipping_method('dpd_courier');
    if (!$instance_id) { return false; }
    update_option('woocommerce_dpd_courier_' . (int) $instance_id . '_settings', array(
        'title' => $d['courier_title'],
        'cost' => $d['courier_cost'],
        'free_from' => $d['courier_free_from'],
        'city' => $d['courier_city'],
    ), false);
    return true;
}

/** Удаляет самовывоз из зоны, если он выключен в настройках. Возвращает число удалённых. */
function dpd_remove_pickup_from_zone($zone) {
    $removed = 0;
    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id !== 'local_pickup') { continue; }
        $zone->delete_shipping_method((int) $method->instance_id);
        $removed++;
    }
    return $removed;
}

/**
 * Убирает курьерский метод официального плагина (`dostavista`).
 * У нас свой `dpd_courier`; два тарифа на чекауте — это два разных расчёта и путаница.
 */
function dpd_remove_foreign_courier($zone) {
    $removed = 0;
    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id !== 'dostavista') { continue; }
        $zone->delete_shipping_method((int) $method->instance_id);
        $removed++;
    }
    return $removed;
}

add_action('admin_init', 'dpd_clean_foreign_courier', 11);
function dpd_clean_foreign_courier() {
    if (!class_exists('WC_Shipping_Zones')) { return; }
    if (function_exists('dpd_dispatch_is_external') && dpd_dispatch_is_external()) { return; }
    $zone = dpd_target_zone();
    if (!$zone) { return; }
    $removed = dpd_remove_foreign_courier($zone);
    if ($removed > 0) {
        $state = dpd_zone_setup_state();
        $state['time'] = time();
        $state['zone'] = $zone->get_zone_name();
        $state['actions'] = array('убран чужой курьерский метод (инстансов: ' . $removed . ')');
        update_option('dpd_zone_setup_state', $state, false);
    }
}

function dpd_maybe_provision_zone() {
    if (!class_exists('WC_Shipping_Zones')) { return; }
    if (get_transient('dpd_zone_setup_lock')) { return; }
    set_transient('dpd_zone_setup_lock', 1, 6 * HOUR_IN_SECONDS);

    $zone = dpd_target_zone();
    if (!$zone) { return; }

    $actions = array();
    $pickup_on = function_exists('dpd_pickup_enabled') ? dpd_pickup_enabled() : false;
    try {
        if (!dpd_zone_has_method($zone, 'dpd_courier') && dpd_add_courier_to_zone($zone)) {
            $actions[] = 'добавлен курьерский тариф';
        }
        if ($pickup_on) {
            if (!dpd_zone_has_method($zone, 'local_pickup') && dpd_add_pickup_to_zone($zone)) {
                $actions[] = 'добавлен самовывоз';
            }
        } else {
            $removed = dpd_remove_pickup_from_zone($zone);
            if ($removed > 0) { $actions[] = 'убран самовывоз (инстансов: ' . $removed . ')'; }
        }
    } catch (Throwable $e) {
        $actions[] = 'ошибка: ' . $e->getMessage();
    }

    if ($actions) {
        update_option('dpd_zone_setup_state', array(
            'time' => time(),
            'zone' => $zone->get_zone_name(),
            'actions' => $actions,
        ), false);
    }
}
