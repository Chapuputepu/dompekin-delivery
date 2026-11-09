<?php
if (!defined('ABSPATH')) { exit; }

/** Имя callback-а WordPress без раскрытия исходного кода. */
function dpd_callback_name($cb) {
    if (is_string($cb)) { return $cb; }
    if (is_array($cb) && count($cb) === 2) {
        $obj = $cb[0];
        $cls = is_object($obj) ? get_class($obj) : (string) $obj;
        return $cls . '::' . (string) $cb[1];
    }
    if ($cb instanceof Closure) {
        try {
            $ref = new ReflectionFunction($cb);
            return 'Closure ' . basename((string) $ref->getFileName()) . ':' . (int) $ref->getStartLine();
        } catch (Exception $e) {
            return 'Closure';
        }
    }
    if (is_object($cb)) { return get_class($cb) . '::__invoke'; }
    return 'callable';
}

/** Список callback-ов, подписанных на хук. */
function dpd_hook_callbacks($hook) {
    global $wp_filter;
    $out = array();
    if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook])) { return $out; }
    foreach ($wp_filter[$hook]->callbacks as $priority => $entries) {
        foreach ($entries as $entry) {
            $out[] = array('priority' => (int) $priority, 'name' => dpd_callback_name($entry['function']));
        }
    }
    return $out;
}

/** Методы одной зоны: id, instance, включён, заголовок, стоимость. */
function dpd_zone_methods($zone) {
    $rows = array();
    foreach ($zone->get_shipping_methods(false) as $method) {
        $cost = '';
        if (method_exists($method, 'get_option')) {
            $cost = (string) $method->get_option('cost');
            if ($cost === '') { $cost = (string) $method->get_option('min_amount'); }
        }
        $rows[] = array(
            'id' => (string) $method->id,
            'instance' => (int) $method->instance_id,
            'enabled' => (string) $method->enabled,
            'title' => (string) $method->title,
            'cost' => $cost,
        );
    }
    return $rows;
}

function dpd_zone_locations($zone) {
    $out = array();
    foreach ($zone->get_zone_locations() as $loc) {
        $out[] = $loc->type . ':' . $loc->code;
    }
    return $out;
}

/** Тарифы, которые WooCommerce реально отдаёт для тестового адреса. */
function dpd_test_package_rates() {
    if (!function_exists('WC') || !WC()->shipping()) { return array('error' => 'WC()->shipping() недоступен.'); }
    $package = array(
        'contents' => array(),
        'contents_cost' => 1560,
        'applied_coupons' => array(),
        'user_id' => get_current_user_id(),
        'destination' => array(
            'country' => 'RU',
            'state' => '',
            'postcode' => '630099',
            'city' => 'Новосибирск',
            'address' => 'Сибирская 57',
            'address_1' => 'Сибирская 57',
            'address_2' => '',
        ),
    );
    $result = WC()->shipping()->calculate_shipping_for_package($package);
    $rates = isset($result['rates']) && is_array($result['rates']) ? $result['rates'] : array();
    $out = array();
    foreach ($rates as $rate) {
        $out[] = array(
            'id' => (string) $rate->get_id(),
            'label' => (string) $rate->get_label(),
            'cost' => (string) $rate->get_cost(),
        );
    }
    return array('rates' => $out, 'error' => '');
}

function dpd_render_shipping_check() {
    if (!current_user_can('manage_woocommerce')) { return; }
    echo '<h2>Способы получения на чекауте</h2>';
    echo '<p>Показывает зоны, методы и тарифы, которые WooCommerce отдаёт для тестового адреса. Ничего не меняет.</p>';

    if (!class_exists('WC_Shipping_Zones')) {
        echo '<p><strong>WooCommerce не загружен.</strong></p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Зона</th><th>Регионы</th><th>Метод (instance)</th><th>Включён</th><th>Заголовок</th><th>Стоимость</th></tr></thead><tbody>';
    $zones = WC_Shipping_Zones::get_zones();
    foreach ($zones as $zrow) {
        $zone = WC_Shipping_Zones::get_zone((int) $zrow['zone_id']);
        if (!$zone) { continue; }
        $methods = dpd_zone_methods($zone);
        $locations = dpd_zone_locations($zone);
        if (!$methods) {
            echo '<tr><td><strong>' . esc_html($zone->get_zone_name()) . '</strong></td><td>' . esc_html(implode(', ', $locations)) . '</td><td colspan="4"><em>методов нет</em></td></tr>';
            continue;
        }
        $first = true;
        foreach ($methods as $m) {
            echo '<tr>';
            if ($first) {
                echo '<td><strong>' . esc_html($zone->get_zone_name()) . '</strong></td><td>' . esc_html(implode(', ', $locations)) . '</td>';
                $first = false;
            } else {
                echo '<td></td><td></td>';
            }
            echo '<td>' . esc_html($m['id'] . ':' . $m['instance']) . '</td>';
            echo '<td style="color:' . ($m['enabled'] === 'yes' ? '#1a7f37' : '#b32d2e') . '"><strong>' . esc_html($m['enabled'] === 'yes' ? 'да' : 'НЕТ') . '</strong></td>';
            echo '<td>' . esc_html($m['title']) . '</td>';
            echo '<td>' . esc_html($m['cost'] === '' ? '—' : $m['cost']) . '</td>';
            echo '</tr>';
        }
    }
    $world = new WC_Shipping_Zone(0);
    if ($world) {
        $methods = dpd_zone_methods($world);
        echo '<tr><td><strong>' . esc_html($world->get_zone_name()) . ' (zone 0)</strong></td><td>остальной мир</td><td colspan="4">' . esc_html($methods ? count($methods) . ' метод(ов)' : 'методов нет') . '</td></tr>';
    }
    echo '</tbody></table>';

    $calc = dpd_test_package_rates();
    echo '<h3>Расчёт для адреса «Новосибирск, Сибирская 57», корзина 1 560 ₽</h3>';
    if (!empty($calc['error'])) {
        echo '<p style="color:#b32d2e"><strong>' . esc_html($calc['error']) . '</strong></p>';
    } else {
        if (empty($calc['rates'])) {
            echo '<p style="color:#b32d2e"><strong>Ни одного тарифа.</strong></p>';
        } else {
            echo '<ul>';
            foreach ($calc['rates'] as $r) {
                echo '<li><code>' . esc_html($r['id']) . '</code> — ' . esc_html($r['label']) . ' — ' . esc_html($r['cost']) . '</li>';
            }
            echo '</ul>';
        }
    }

    echo '<h3>Кто вмешивается в тарифы</h3>';
    $rates_hooks = dpd_hook_callbacks('woocommerce_package_rates');
    echo '<p><strong>woocommerce_package_rates:</strong> ';
    if (!$rates_hooks) {
        echo 'никто не подписан.';
    } else {
        $parts = array();
        foreach ($rates_hooks as $h) { $parts[] = $h['name'] . ' (' . $h['priority'] . ')'; }
        echo esc_html(implode('; ', $parts));
    }
    echo '</p>';

    $avail_hooks = dpd_hook_callbacks('woocommerce_shipping_methods');
    echo '<p><strong>woocommerce_shipping_methods:</strong> ' . esc_html($avail_hooks ? implode('; ', wp_list_pluck($avail_hooks, 'name')) : 'никто не подписан.') . '</p>';

    $methods_all = array();
    if (function_exists('WC') && WC()->shipping()) {
        foreach (WC()->shipping()->get_shipping_methods() as $key => $method) {
            $methods_all[] = $key;
        }
    }
    echo '<p><strong>Активные методы WooCommerce:</strong> ' . esc_html($methods_all ? implode(', ', $methods_all) : 'нет') . '</p>';

    echo '<h3>Условия темы и магазина</h3><ul>';
    echo '<li>Часы работы (dp_cafe_is_open): ' . (function_exists('dp_cafe_is_open') ? (dp_cafe_is_open() ? '<strong style="color:#1a7f37">открыто</strong>' : '<strong style="color:#b32d2e">закрыто</strong>') : 'функция не найдена') . '</li>';
    echo '<li>Геозона (dp_zone_status): ' . (function_exists('dp_zone_status') ? 'функция доступна' : 'функция не найдена') . '</li>';
    echo '<li>Гость может оформить заказ: ' . (get_option('woocommerce_enable_guest_checkout') === 'yes' ? 'да' : '<strong style="color:#b32d2e">НЕТ</strong>') . '</li>';
    echo '<li>Страна магазина: ' . esc_html((string) get_option('woocommerce_default_country')) . ' · отправка в: ' . esc_html((string) get_option('woocommerce_ship_to_countries', 'все') ) . '</li>';
    echo '<li>Валюта: ' . esc_html((string) get_option('woocommerce_currency')) . '</li>';
    echo '</ul>';

    global $wpdb;
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'dp\\_%' ORDER BY option_name LIMIT 40");
    echo '<p><strong>Опции темы dp_*:</strong> ' . esc_html($names ? implode(', ', $names) : 'не найдены') . '</p>';
}
