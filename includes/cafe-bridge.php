<?php
/** Safe bridge from the restaurant panel's manual action to this dispatcher. */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('dpd_cafe_call_now')) {
    function dpd_cafe_call_now($order) {
        if (!($order instanceof WC_Order) || (int) $order->get_id() <= 0) {
            return 'Заказ не найден.';
        }
        if (!dpd_enabled() || (function_exists('dpd_dispatch_is_external') && dpd_dispatch_is_external())) {
            return 'Вызов нашим плагином отключён. Проверьте настройки доставки.';
        }
        if (function_exists('dpd_dispatch_channel') && dpd_dispatch_channel() === '') {
            return 'Для выбранной среды нет токена Достависты.';
        }
        $ready = dpd_order_ready($order);
        if (is_wp_error($ready)) { return $ready->get_error_message(); }
        $order_id = (int) $order->get_id();
        $lock = dpd_lock_read($order_id);
        $state = is_array($lock) ? (string) ($lock['state'] ?? '') : '';
        if ($state !== '' && $state !== 'blocked') {
            return 'Повтор запрещён: заявка уже создана или результат предыдущей попытки неизвестен. Проверьте Достависту и заметки заказа.';
        }
        if (is_array($lock) && $state === '') {
            return 'Повтор запрещён: состояние предыдущей попытки неизвестно.';
        }
        dpd_run_dispatch($order_id, true);
        $result = dpd_lock_read($order_id);
        $result_state = is_array($result) ? (string) ($result['state'] ?? '') : '';
        if ($result_state === 'created') { return null; }
        if ($result_state === 'blocked') {
            return 'Курьер не вызван: заявка заблокирована. Проверьте заметки заказа и устраните причину перед повтором.';
        }
        return 'Результат вызова не подтверждён. Не повторяйте запрос до проверки заявки в Достависте и заметках заказа.';
    }
}
