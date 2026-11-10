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
        'courier_free_from' => '10000',
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
/**
 * Приводит в порядок ВСЕ зоны, где может оказаться новосибирский адрес.
 * Визард официального плагина умеет создавать собственную зону, поэтому одной
 * зоны по имени недостаточно: без этого чекаут может попасть в пустую зону.
 */
function dpd_repair_all_zones() {
    if (!class_exists('WC_Shipping_Zones')) { return array(); }
    if (get_transient('dpd_repair_lock')) { return array(); }
    set_transient('dpd_repair_lock', 1, 5 * MINUTE_IN_SECONDS);

    $actions = array();
    $zones = array();
    foreach (WC_Shipping_Zones::get_zones() as $row) {
        $zone = WC_Shipping_Zones::get_zone((int) $row['zone_id']);
        if ($zone) { $zones[] = $zone; }
    }

    foreach ($zones as $zone) {
        $name = (string) $zone->get_zone_name();
        $locations = array();
        foreach ($zone->get_zone_locations() as $loc) { $locations[] = $loc->type . ':' . $loc->code; }
        $matches = (bool) preg_match('/Новосибирск/iu', $name) || in_array('country:RU', $locations, true) || in_array('state:RU-NVS', $locations, true);
        if (!$matches) { continue; }

        $own_pricing = function_exists('dpd_pricing_is_external') ? !dpd_pricing_is_external() : true;
        if ($own_pricing) {
            if (!dpd_zone_has_method($zone, 'dpd_courier') && dpd_add_courier_to_zone($zone)) {
                $actions[] = 'курьер добавлен в зону «' . $name . '»';
            }
            $foreign = dpd_remove_foreign_courier($zone);
            if ($foreign > 0) { $actions[] = 'убран чужой метод в зоне «' . $name . '» (' . $foreign . ')'; }
        } else {
            // Цену считает метод официального плагина: наш тариф убираем, чтобы не было двух.
            $ours = dpd_remove_own_courier($zone);
            if ($ours > 0) { $actions[] = 'убран наш тариф в зоне «' . $name . '» (' . $ours . ')'; }
        }
        if (!(function_exists('dpd_pickup_enabled') && dpd_pickup_enabled())) {
            $pickup = dpd_remove_pickup_from_zone($zone);
            if ($pickup > 0) { $actions[] = 'убран самовывоз в зоне «' . $name . '» (' . $pickup . ')'; }
        }
    }

    if ($actions) {
        update_option('dpd_zone_setup_state', array('time' => time(), 'zone' => 'все зоны', 'actions' => $actions), false);
    }
    return $actions;
}

add_action('wp_loaded', 'dpd_repair_on_demand', 22);
function dpd_repair_on_demand() {
    if (!function_exists('is_cart') || !function_exists('is_checkout')) { return; }
    if (is_admin() || is_cart() || is_checkout()) {
        dpd_repair_all_zones();
    }
}

/** Убирает наш тариф из зоны, когда цену считает метод официального плагина. */
function dpd_remove_own_courier($zone) {
    $removed = 0;
    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id !== 'dpd_courier') { continue; }
        $zone->delete_shipping_method((int) $method->instance_id);
        $removed++;
    }
    return $removed;
}

/** Удаляет самовывоз из зоны, если он выключен в настройках. */
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

/** Одноразовое обновление порога бесплатной доставки 4000 -> 10000 в уже созданном методе. */
add_action('admin_init', 'dpd_migrate_free_from', 12);
function dpd_migrate_free_from() {
    if (get_option('dpd_free_from_migrated_v1')) { return; }
    if (!class_exists('WC_Shipping_Zones')) { return; }
    $zone = dpd_target_zone();
    if (!$zone) { return; }

    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id !== 'dpd_courier') { continue; }
        $key = 'woocommerce_dpd_courier_' . (int) $method->instance_id . '_settings';
        $stored = get_option($key, array());
        if (is_array($stored) && isset($stored['free_from']) && (float) $stored['free_from'] === 4000.0) {
            $stored['free_from'] = '10000';
            update_option($key, $stored, false);
        }
        update_option('dpd_free_from_migrated_v1', 1, false);
        return;
    }
}

/** Одноразово переводит уже созданный метод на расчёт калькулятором. */
add_action('admin_init', 'dpd_migrate_live_mode', 13);
function dpd_migrate_live_mode() {
    if (get_option('dpd_live_mode_migrated_v1')) { return; }
    if (!class_exists('WC_Shipping_Zones')) { return; }
    $zone = dpd_target_zone();
    if (!$zone) { return; }

    foreach ($zone->get_shipping_methods(false) as $method) {
        if ($method->id !== 'dpd_courier') { continue; }
        $key = 'woocommerce_dpd_courier_' . (int) $method->instance_id . '_settings';
        $stored = get_option($key, array());
        if (!is_array($stored)) { $stored = array(); }
        $stored['price_mode'] = 'live';
        update_option($key, $stored, false);
        update_option('dpd_live_mode_migrated_v1', 1, false);
        return;
    }
}

add_action('admin_init', 'dpd_clean_foreign_courier', 11);function dpd_clean_foreign_courier() {
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

add_action('admin_post_dpd_fix_zone', 'dpd_admin_fix_zone');
function dpd_admin_fix_zone() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_fix_zone');
    delete_transient('dpd_zone_setup_lock');
    dpd_maybe_provision_zone(true);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery'), admin_url('admin.php')));
    exit;
}

function dpd_maybe_provision_zone($force = false) {
    if (!class_exists('WC_Shipping_Zones')) { return; }
    if (!$force && get_transient('dpd_zone_setup_lock')) { return; }
    set_transient('dpd_zone_setup_lock', 1, 6 * HOUR_IN_SECONDS);

    $zone = dpd_target_zone();
    if (!$zone) { return; }

    $actions = array();
    $pickup_on = function_exists('dpd_pickup_enabled') ? dpd_pickup_enabled() : false;
    $own_pricing = function_exists('dpd_pricing_is_external') ? !dpd_pricing_is_external() : true;
    try {
        if ($own_pricing) {
            if (!dpd_zone_has_method($zone, 'dpd_courier') && dpd_add_courier_to_zone($zone)) {
                $actions[] = 'добавлен курьерский тариф';
            }
            $foreign = dpd_remove_foreign_courier($zone);
            if ($foreign > 0) { $actions[] = 'убран чужой курьерский метод (инстансов: ' . $foreign . ')'; }
        } else {
            $ours = dpd_remove_own_courier($zone);
            if ($ours > 0) { $actions[] = 'убран наш тариф: цену считает метод Достависты'; }
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
