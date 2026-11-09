<?php
if (!defined('ABSPATH')) { exit; }

/** Сборка адреса через нормализованные поля (город + улица + дом). */
function dpd_address_sources($order) {
    $norm = dpd_order_address($order);

    $out = array();
    $out['used'] = $norm['source'];
    $out['address'] = $norm['text'];
    $out['length'] = $norm['length'];
    $out['raw_formatted'] = $norm['raw_formatted'];
    $out['has_number'] = $norm['has_street_number'];
    $out['has_shipping'] = dpd_clean_text($order->get_shipping_address_1()) !== '';
    $out['has_billing'] = dpd_clean_text($order->get_billing_address_1()) !== '';
    $out['phone'] = trim((string) $order->get_billing_phone());
    return $out;
}

function dpd_geocoder_key_present() {
    if (!function_exists('dp_zone_key')) { return null; }
    $key = dp_zone_key();
    return is_string($key) && $key !== '';
}

/** Runs the same geocoder used by the site; обходит отрицательный кэш повторной попыткой. */
function dpd_diagnose($order_id) {
    $steps = array();
    $order = wc_get_order((int) $order_id);
    if (!$order) { return array(array('step' => 'Заказ', 'status' => 'fail', 'detail' => 'Заказ не найден по ID.')); }

    $steps[] = array('step' => 'Оплата', 'status' => $order->is_paid() ? 'ok' : 'fail', 'detail' => $order->is_paid() ? 'Оплата подтверждена.' : 'Оплата НЕ подтверждена — доставка не будет создана.');
    $stage = (string) $order->get_meta('_dp_stage');
    $steps[] = array('step' => 'Принятие рестораном', 'status' => $stage === 'cooking' ? 'ok' : 'warn', 'detail' => $stage === 'cooking' ? 'Заказ принят (cooking).' : 'Стадия: ' . ($stage === '' ? 'не задана' : $stage));
    $existing = $order->get_meta('_dpd_carrier_id');
    $steps[] = array('step' => 'Существующая заявка', 'status' => $existing ? 'warn' : 'ok', 'detail' => $existing ? 'Уже создана заявка ID ' . (int) $existing . '. Повторно не создаём.' : 'Заявки нет.');

    $src = dpd_address_sources($order);
    if ($src['address'] === '') {
        $steps[] = array('step' => 'Сборка адреса', 'status' => 'fail', 'detail' => 'Пустой адрес во всех полях WooCommerce. Проблема в данных заказа, а не в геокодере.');
    } else {
        $note = $src['has_shipping'] ? '' : ' Адрес доставки пуст — используется платёжный адрес, это может дать адрес не того типа.';
        $steps[] = array('step' => 'Сборка адреса', 'status' => $note ? 'warn' : 'ok', 'detail' => 'Источник: ' . $src['used'] . '; длина ' . $src['length'] . ' симв.' . $note . ' Адрес: ' . $src['address']);
    }
    if ($src['phone'] === '') { $steps[] = array('step' => 'Телефон', 'status' => 'fail', 'detail' => 'Телефон получателя пуст — Достависта не примет заявку.'); }

    $keyPresent = dpd_geocoder_key_present();
    $steps[] = array(
        'step' => 'Ключ геокодера',
        'status' => $keyPresent === true ? 'ok' : ($keyPresent === false ? 'fail' : 'warn'),
        'detail' => $keyPresent === true ? 'Ключ геокодера задан.' : ($keyPresent === false ? 'Ключ геокодера ПУСТ — геокодирование не работает.' : 'Функция ключа не найдена в теме.'),
    );

    $origin = function_exists('dp_dv_origin') ? dp_dv_origin() : null;
    $originOk = is_array($origin) && isset($origin['lat'], $origin['lon']) && is_numeric($origin['lat']) && is_numeric($origin['lon']);
    $steps[] = array('step' => 'Координаты ресторана', 'status' => $originOk ? 'ok' : 'fail', 'detail' => $originOk ? 'Определены.' : 'НЕ определены: проблема в точке отправления, а не в адресе клиента.');

    $geo = $src['address'] === '' ? array('state' => 'skipped') : dpd_geocode_report($src['address']);
    $map = array(
        'found' => array('ok', 'Адрес распознан.'),
        'not_found' => array('fail', 'Геокодер не подтвердил адрес с нужной точностью (или не нашёл его).'),
        'wp_error' => array('fail', 'Геокодер вернул ошибку — адрес клиента не виноват.'),
        'bad_shape' => array('fail', 'Неожиданный формат ответа геокодера.'),
        'missing_function' => array('fail', 'Функция геокодирования отсутствует.'),
        'skipped' => array('warn', 'Пропущено: адрес пуст.'),
    );
    $state = $map[$geo['state']] ?? array('warn', 'Неизвестное состояние.');
    $detail = $state[1];
    if ($geo['state'] === 'wp_error') { $detail .= ' Код: ' . $geo['code'] . '. ' . $geo['message']; }
    if ($geo['state'] === 'found' && $geo['precision'] !== null) { $detail .= ' Точность: ' . $geo['precision'] . '.'; }
    $steps[] = array('step' => 'Геокодирование адреса', 'status' => $state[0], 'detail' => $detail);

    $cache = $src['address'] === '' ? null : dpd_cached_geocode_state($src['address']);
    if (is_array($cache)) {
        if (!$cache['present']) { $steps[] = array('step' => 'Кэш адреса', 'status' => 'ok', 'detail' => 'Записи нет.'); }
        elseif ($cache['found'] === true) { $steps[] = array('step' => 'Кэш адреса', 'status' => 'ok', 'detail' => 'Есть положительный результат.'); }
        else { $steps[] = array('step' => 'Кэш адреса', 'status' => 'warn', 'detail' => 'Есть сохранённый отрицательный результат до 30 дней. Он может блокировать адрес, который геокодер уже способен найти. Можно сбросить.'); }
    }

    if ($geo['state'] === 'found' && $originOk) {
        $distance = dpd_distance_km($src['address']);
        if (is_wp_error($distance)) {
            $steps[] = array('step' => 'Расстояние', 'status' => 'fail', 'detail' => $distance->get_error_message());
        } else {
            $steps[] = array('step' => 'Расстояние и транспорт', 'status' => 'ok', 'detail' => sprintf('%.2f км, транспорт: %s (%d).', $distance, $distance <= dpd_walk_max_km() ? 'пеший' : 'авто', dpd_vehicle_for($distance)));
        }
    }
    return $steps;
}

/** Standalone geocoder check; uses the exact function the site relies on. */
function dpd_check_geocoder_now() {
    $steps = array();
    $keyPresent = dpd_geocoder_key_present();
    $steps[] = array(
        'step' => 'Ключ геокодера в теме',
        'status' => $keyPresent === true ? 'ok' : ($keyPresent === false ? 'fail' : 'warn'),
        'detail' => $keyPresent === true ? 'Функция ключа есть, ключ не пустой.' : ($keyPresent === false ? 'Ключ ПУСТ — геокодирование не работает.' : 'Функция ключа не найдена в теме.'),
    );
    $steps[] = array('step' => 'Функция геокодирования', 'status' => function_exists('dp_zone_geocode') ? 'ok' : 'fail', 'detail' => function_exists('dp_zone_geocode') ? 'dp_zone_geocode() доступна.' : 'dp_zone_geocode() отсутствует.');
    $address = trim((string) dpd_settings()['restaurant_address']);
    if ($address === '') {
        $steps[] = array('step' => 'Проверочный адрес', 'status' => 'fail', 'detail' => 'Заполните адрес ресторана выше — нужен адрес для проверки.');
        return $steps;
    }
    if (!function_exists('dp_zone_geocode')) { return $steps; }
    $started = microtime(true);
    $geo = dpd_geocode_report($address);
    $elapsed = round((microtime(true) - $started) * 1000);
    if ($geo['state'] === 'found') {
        $prec = $geo['precision'] === null ? 'нет данных о точности' : 'точность: ' . $geo['precision'];
        $steps[] = array('step' => 'Геокодирование ресторана', 'status' => 'ok', 'detail' => 'Адрес распознан (' . $prec . '), ' . $elapsed . ' мс.');
    } elseif ($geo['state'] === 'wp_error') {
        $steps[] = array('step' => 'Геокодирование ресторана', 'status' => 'fail', 'detail' => 'Ошибка геокодера: ' . $geo['code'] . ' — ' . $geo['message'] . ' (' . $elapsed . ' мс). Это ошибка сервиса или ключа, не адреса.');
    } elseif ($geo['state'] === 'not_found') {
        $steps[] = array('step' => 'Геокодирование ресторана', 'status' => 'fail', 'detail' => 'Геокодер не подтвердил адрес (' . $elapsed . ' мс). Проверьте точность адреса или ключ.');
    } else {
        $steps[] = array('step' => 'Геокодирование ресторана', 'status' => 'fail', 'detail' => 'Неожиданный ответ геокодера: ' . $geo['state'] . ' (' . $elapsed . ' мс).');
    }
    return $steps;
}

/** Обрезает ответ API и убирает почту и телефоны, чтобы не тащить персональные данные в админку и логи. */
function dpd_safe_snippet($text) {
    $text = is_string($text) ? $text : '';
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $text);
    $text = preg_replace('/\+?\d[\d\s\-()]{9,}/u', '[phone]', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') { return 'пусто'; }
    return (function_exists('mb_substr') ? mb_substr($text, 0, 300, 'UTF-8') : substr($text, 0, 300));
}

/** Optional: ask Dostavista itself to calculate for this exact address.
 * В боевой среде требует явного разрешения; выполняется только calculate-order,
 * заявка не создаётся и курьер не вызывается.
 */
function dpd_relay_check_address($order_id, $allow_production = false) {
    if (!dpd_is_test() && $allow_production !== true) { return array('status' => 'fail', 'detail' => 'Боевой расчёт не разрешён. Поставь галочку «Разрешить расчёт в боевой среде».'); }
    $order = wc_get_order((int) $order_id);
    if (!$order) { return array('status' => 'fail', 'detail' => 'Заказ не найден.'); }
    $origin = dpd_origin();
    $src = dpd_address_sources($order);
    if (is_wp_error($origin)) { return array('status' => 'fail', 'detail' => 'Точка забора не задана: ' . $origin->get_error_message()); }
    if ($src['address'] === '') { return array('status' => 'fail', 'detail' => 'В заказе нет адреса клиента (ни доставки, ни платёжного).'); }
    if (trim((string) $src['phone']) === '') { return array('status' => 'fail', 'detail' => 'В заказе не заполнен телефон получателя — Достависта его требует.'); }
    $payload = dpd_build_payload($order, $origin, 6);
    if (is_wp_error($payload)) { return array('status' => 'fail', 'detail' => $payload->get_error_message()); }
    $token = (string) dpd_active_token();
    $env_label = 'Хост: ' . dpd_api_host() . ' | токен: ' . (dpd_is_test() ? 'тестовый' : 'БОЕВОЙ') . ' | длина ' . strlen($token) . '. ';
    $response = dpd_api_request('calculate-order', $payload);
    if (is_wp_error($response)) { return array('status' => 'fail', 'detail' => $env_label . 'Сеть или ключ: ' . $response->get_error_message()); }
    $status = is_array($response) ? (int) ($response['status'] ?? 0) : 0;
    $raw = is_array($response) ? (string) ($response['body'] ?? '') : '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return array('status' => 'fail', 'detail' => $env_label . 'HTTP ' . $status . '. Ответ не JSON: ' . dpd_safe_snippet($raw));
    }
    if (($data['is_successful'] ?? null) !== true) {
        $details = array();
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach (array_slice($data['errors'], 0, 5) as $err) {
                if (is_array($err)) { $details[] = trim(((string) ($err['field'] ?? '')) . ' ' . ((string) ($err['code'] ?? '')) . ' ' . ((string) ($err['message'] ?? ''))); }
            }
        }
        return array('status' => 'fail', 'detail' => $env_label . 'HTTP ' . $status . '. Достависта отклонила расчёт: ' . (($details ? implode('; ', $details) : dpd_safe_snippet($raw))));
    }
    $warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : array();
    $amount = $data['order']['payment_amount'] ?? '—';
    return array('status' => $warnings ? 'warn' : 'ok', 'detail' => 'Достависта приняла адрес. Расчёт: ' . $amount . ' ₽.' . ($warnings ? ' Предупреждения: ' . wp_json_encode($warnings) : ''));
}
