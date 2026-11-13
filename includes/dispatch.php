<?php
if (!defined('ABSPATH')) { exit; }

function dpd_on_order_saved($order, $data_store = null) {
    if (!($order instanceof WC_Order) || !dpd_enabled()) { return; }
    if (function_exists('dpd_dispatch_is_external') && dpd_dispatch_is_external()) { return; }
    if (!$order->is_paid() || $order->get_meta('_dp_stage') !== 'cooking' || $order->get_meta('_dpd_carrier_id')) { return; }
    if (function_exists('dpd_dispatch_channel') && dpd_dispatch_channel() === '') { return; }
    $id = (int) $order->get_id();
    if ($id <= 0 || is_array(dpd_lock_read($id))) { return; }
    $args = array($id, dpd_is_test() ? 'test' : 'production');
    if (!wp_next_scheduled('dpd_dispatch_order', $args)) {
        wp_schedule_single_event(time() + 5, 'dpd_dispatch_order', $args);
    }
}

function dpd_on_stage_meta($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_dp_stage' || $meta_value !== 'cooking') { return; }
    // Заявки создаёт наш плагин. Официальный плагин автовызова не имеет — дублей нет.
    if (function_exists('dpd_dispatch_is_external') && dpd_dispatch_is_external()) { return; }
    if (!dpd_enabled()) { return; }
    if (function_exists('dpd_dispatch_channel')) { if (dpd_dispatch_channel() === '') { return; } } elseif (!dpd_active_token()) { return; }
    if (get_post_meta($object_id, '_dpd_carrier_id', true)) { return; }
    if (function_exists('dpd_lock_read') && is_array(dpd_lock_read($object_id))) { return; }
    $args = array((int) $object_id, dpd_is_test() ? 'test' : 'production');
    if (wp_next_scheduled('dpd_dispatch_order', $args)) { return; }
    wp_schedule_single_event(time() + 5, 'dpd_dispatch_order', $args);
}

/** A legacy or cross-environment event must never dispatch blindly. */
function dpd_dispatch_scheduled($order_id, $environment = null) {
    $current = dpd_is_test() ? 'test' : 'production';
    if (!is_string($environment) || !in_array($environment, array('test', 'production'), true) || $environment !== $current) {
        if (function_exists('error_log')) { error_log('Dompekin Delivery: scheduled dispatch skipped due to missing or changed environment. Order ID: ' . (int) $order_id); }
        return;
    }
    dpd_run_dispatch($order_id, false);
}

function dpd_order_ready($order) {
    if (!dpd_enabled()) { return new WP_Error('dpd_off', 'Плагин выключен.'); }
    $has_token = function_exists('dpd_dispatch_channel') ? (dpd_dispatch_channel() !== '') : (dpd_active_token() !== '');
    if (!$has_token) { return new WP_Error('dpd_nokey', 'Нет токена Достависты. Для боевой среды войдите в визард официального плагина.'); }
    if (!$order->is_paid()) { return new WP_Error('dpd_unpaid', 'Оплата не подтверждена.'); }
    if ($order->get_meta('_dp_stage') !== 'cooking') { return new WP_Error('dpd_notaccepted', 'Заказ ещё не принят рестораном.'); }
    if ($order->get_meta('_dpd_carrier_id')) { return new WP_Error('dpd_exists', 'Доставка уже создана.'); }
    if ($order->get_meta('_dp_dv_id')) { return new WP_Error('dpd_legacy_exists', 'У заказа уже есть заявка старой интеграции. Повтор запрещён.'); }
    if ($order->get_meta('_dp_dv_run')) { return new WP_Error('dpd_legacy_pending', 'Старая интеграция отметила ожидающий вызов. Проверьте его перед повтором.'); }
    if (!dpd_is_delivery_order($order)) { return new WP_Error('dpd_nodelivery', 'Заказ оформлен как самовывоз, курьер не вызывается.'); }
    return true;
}

/** Доставка ли это. Самовывоз должен отсекаться всегда. */
function dpd_is_delivery_order($order) {
    if (!is_object($order) || !method_exists($order, 'get_shipping_methods')) { return false; }
    $items = $order->get_shipping_methods();
    if (!is_array($items) || count($items) === 0) { return false; }
    foreach ($items as $item) {
        $id = method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '';
        if ($id === 'local_pickup') { return false; }
    }
    return (bool) $order->needs_shipping_address() || count($items) > 0;
}

function dpd_run_dispatch($order_id, $manual = false) {
    // Даже если событие уже стояло в очереди — в режиме внешнего транспорта не создаём.
    if (function_exists('dpd_dispatch_is_external') && dpd_dispatch_is_external()) { return; }
    $order_id = (int) $order_id;
    $order = wc_get_order($order_id);
    if (!$order) { return; }
    $ready = dpd_order_ready($order);
    if (is_wp_error($ready)) { return; }
    $existing = dpd_lock_read($order_id);
    $token = bin2hex(random_bytes(16));
    if (is_array($existing)) {
        // Автоматический путь попытку не переигрывает никогда.
        // Оператор — только состояние blocked: uncertain могла уже создать заявку,
        // created — точно создала. Повтор по ним был бы дублем и двойной оплатой.
        if (!$manual || ($existing['state'] ?? '') !== 'blocked') { return; }
        if (!function_exists('dpd_lock_reopen') || !dpd_lock_reopen($order_id, $token)) { return; }
        $order->add_order_note('Dompekin Delivery: повторный запуск оператором после блокировки.');
    } elseif (!dpd_lock_reserve($order_id, $token)) {
        return;
    }

    // Persist uncertainty BEFORE any network request: a crash must never cause a blind retry.
    if (!dpd_lock_transition($order_id, $token, 'reserved', 'uncertain')) { return; }

    $origin = dpd_origin();
    if (is_wp_error($origin)) { dpd_finish_blocked($order, $token, $origin->get_error_message()); return; }

    $address = dpd_delivery_address($order);
    if (is_wp_error($address)) { dpd_finish_blocked($order, $token, $address->get_error_message()); return; }

    // Геокодер темы периодически отдаёт 'ошибка: http'. Решение владельца:
    // без расстояния заявку НЕ создавать. Транспорт (пеший/авто) определяет расстояние,
    // а угадывать его — значит платить за авто там, где хватило бы пешего.
    // Заказ не теряется: причина уходит в заметку, после починки геокодера
    // автовызов повторяют кнопкой в панели.
    $distance = dpd_distance_km($address);
    if (is_wp_error($distance)) { dpd_finish_blocked($order, $token, $distance->get_error_message()); return; }
    $vehicle_id = dpd_vehicle_for($distance);

    $channel = dpd_dispatch_channel();
    if ($channel === 'business') {
        $payload = dpd_build_payload($order, $origin, $vehicle_id);
        if (is_wp_error($payload)) { dpd_finish_blocked($order, $token, $payload->get_error_message()); return; }
        $calc = dpd_json(dpd_api_request('calculate-order', $payload));
    } else {
        $payload = dpd_build_cms_payload($order, $origin, $vehicle_id);
        if (is_wp_error($payload)) { dpd_finish_blocked($order, $token, $payload->get_error_message()); return; }
        $calc = dpd_json(dpd_cms_request('calculate-order', $payload));
    }

    if (!is_array($calc) || ($calc['is_successful'] ?? null) !== true) {
        dpd_finish_blocked($order, $token, 'Достависта не подтвердила расчёт доставки.');
        return;
    }

    $warnings = (isset($calc['warnings']) && is_array($calc['warnings'])) ? $calc['warnings'] : array();
    $raw_amount = $calc['order']['payment_amount'] ?? null;
    $amount = function_exists('dpd_parse_amount') ? dpd_parse_amount($raw_amount) : null;
    if ($amount === null) {
        $minor = dpd_amount_minor($raw_amount);
        $amount = ($minor === null) ? null : ($minor / 100);
    }
    if ($amount === null) { dpd_finish_blocked($order, $token, 'Некорректная сумма расчёта.'); return; }

    $created = ($channel === 'business') ? dpd_api_request('create-order', $payload) : dpd_cms_request('create-order', $payload);
    $data = dpd_json($created);
    $carrier_id = (is_array($data) && ($data['is_successful'] ?? null) === true) ? ($data['order']['order_id'] ?? null) : null;
    if (!is_int($carrier_id) || $carrier_id <= 0) {
        // Unknown outcome: keep 'uncertain' and require manual reconciliation.
        $order->add_order_note('Dompekin Delivery: результат создания заявки неизвестен. Автоматического повтора не будет; требуется сверка в кабинете Достависты.');
        return;
    }
    if (!dpd_lock_transition($order_id, $token, 'uncertain', 'created', $carrier_id)) {
        $order->add_order_note('Dompekin Delivery: заявка создана, но состояние не сохранилось. Требуется сверка; не создавать повторно.');
        return;
    }

    $order->update_meta_data('_dpd_carrier_id', $carrier_id);
    $order->update_meta_data('_dpd_state', 'created');
    if ($warnings) { $order->update_meta_data('_dpd_warnings', wp_json_encode($warnings)); }

    $note = sprintf('Dompekin Delivery: заявка создана, ID %d. Среда: %s. Расчёт Достависты: %s ₽.', $carrier_id, dpd_is_test() ? 'тест' : 'боевая', number_format_i18n($amount, 2));
    $paid = method_exists($order, 'get_shipping_total') ? (float) $order->get_shipping_total() : 0.0;
    if ($paid > 0) {
        $note .= ' Клиент заплатил за доставку: ' . number_format_i18n($paid, 2) . ' ₽.';
        if ($amount - $paid > 1) {
            $note .= ' ВНИМАНИЕ: доставка дороже оплаченного на ' . number_format_i18n($amount - $paid, 2) . ' ₽.';
        }
    }
    if ($warnings) { $note .= ' Предупреждения Достависты: ' . wp_json_encode($warnings); }
    $order->add_order_note($note);
    $order->save();
}

function dpd_finish_blocked($order, $token, $reason) {
    dpd_lock_transition($order->get_id(), $token, 'uncertain', 'blocked');
    $order->update_meta_data('_dpd_state', 'blocked');
    $order->add_order_note('Dompekin Delivery: заявка не отправлена. ' . $reason);
    $order->save();
}

function dpd_delivery_address($order) {
    // Берём город + улица + дом из полей заказа.
    // Старый вариант склеивал HTML-адрес без разделителей ("...домофон 17Новосибирск").
    $normalized = dpd_order_address($order);
    $address = $normalized['text'];
    if ($address === '') { return new WP_Error('dpd_noaddress', 'В заказе нет адреса доставки.'); }
    if (!$normalized['has_street_number']) { return new WP_Error('dpd_nonumber', 'В адресе клиента нет номера дома.'); }
    return $address;
}
