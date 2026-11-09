<?php
if (!defined('ABSPATH')) { exit; }

function dpd_register_admin() {
    add_submenu_page('woocommerce', 'Dompekin Delivery', 'Dompekin Delivery', 'manage_woocommerce', 'dompekin-delivery', 'dpd_admin_page');
    add_action('admin_post_dpd_save', 'dpd_admin_save');
    add_action('admin_post_dpd_diagnose', 'dpd_admin_diagnose');
    add_action('admin_post_dpd_clear_cache', 'dpd_admin_clear_cache');
    add_action('admin_post_dpd_relay', 'dpd_admin_relay');
    add_action('admin_post_dpd_dispatch_now', 'dpd_admin_dispatch_now');
    add_action('admin_post_dpd_check_geo', 'dpd_admin_check_geo');
}

function dpd_diag_key() { return 'dpd_diag_' . get_current_user_id(); }

function dpd_admin_diagnose() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_diag');
    $order_id = absint($_POST['order_id'] ?? 0);
    set_transient(dpd_diag_key(), array('order_id' => $order_id, 'steps' => dpd_diagnose($order_id), 'relay' => null), 600);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'diag' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_admin_clear_cache() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_diag');
    $order_id = absint($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    $result = 'fail';
    if ($order) {
        $src = dpd_address_sources($order);
        if ($src['address'] !== '') { $result = dpd_clear_address_cache($src['address']) ? 'ok' : 'fail'; }
    }
    $steps = $order ? dpd_diagnose($order_id) : array(array('step' => 'Заказ', 'status' => 'fail', 'detail' => 'Заказ не найден.'));
    set_transient(dpd_diag_key(), array('order_id' => $order_id, 'steps' => $steps, 'relay' => null, 'cache_cleared' => $result), 600);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'diag' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_admin_relay() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_diag');
    $order_id = absint($_POST['order_id'] ?? 0);
    $relay = dpd_relay_check_address($order_id);
    set_transient(dpd_diag_key(), array('order_id' => $order_id, 'steps' => dpd_diagnose($order_id), 'relay' => $relay), 600);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'diag' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_admin_dispatch_now() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_diag');
    $order_id = absint($_POST['order_id'] ?? 0);
    if (($_POST['confirm'] ?? '') !== 'yes') {
        set_transient(dpd_diag_key(), array('order_id' => $order_id, 'steps' => $order_id ? dpd_diagnose($order_id) : array(), 'relay' => null, 'message' => 'Создание отменено: нужно подтверждение.'), 600);
    } else {
        dpd_run_dispatch($order_id);
        $lock = dpd_lock_read($order_id);
        $state = is_array($lock) ? ($lock['state'] ?? 'unknown') : 'none';
        $carrier = $lock['carrier_id'] ?? null;
        $messages = array(
            'created' => 'Заявка создана, ID ' . (int) $carrier . '.',
            'uncertain' => 'Результат неизвестен: заявка могла быть создана. Проверьте кабинет Достависты вручную; повторно не отправляем.',
            'blocked' => 'Заявка не отправлена: см. заметку заказа с причиной.',
            'reserved' => 'Попытка зарезервирована, но не завершена. Требуется сверка.',
            'none' => 'Отправка не запустилась: плагин выключен, нет токена, заказ не оплачен/не принят, либо среда не готова.',
        );
        set_transient(dpd_diag_key(), array('order_id' => $order_id, 'steps' => dpd_diagnose($order_id), 'relay' => null, 'message' => $messages[$state] ?? ('Состояние: ' . $state)), 600);
    }
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'diag' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_admin_check_geo() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_geo_check');
    set_transient('dpd_geo_check_' . get_current_user_id(), dpd_check_geocoder_now(), 300);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'geo' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_render_geo_check() {
    $data = get_transient('dpd_geo_check_' . get_current_user_id());
    echo '<h2>Проверка геокодера Яндекса</h2>';
    echo '<p>Вызывает тот же ключ и функцию, что и сайт. Ничего не создаёт и не меняет.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('dpd_geo_check');
    echo '<input type="hidden" name="action" value="dpd_check_geo" />';
    submit_button('Проверить геокодер сейчас', 'secondary', 'submit', false);
    echo '</form>';
    if (!is_array($data)) { return; }
    echo '<table class="widefat striped"><tbody>';
    foreach ($data as $step) {
        $color = $step['status'] === 'ok' ? '#1a7f37' : ($step['status'] === 'fail' ? '#b32d2e' : '#996800');
        echo '<tr><td><strong>' . esc_html($step['step']) . '</strong></td><td style="color:' . esc_attr($color) . '"><strong>' . esc_html(strtoupper($step['status'])) . '</strong></td><td>' . esc_html($step['detail']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function dpd_render_diagnostics() {
    $data = get_transient(dpd_diag_key());
    echo '<h2>Диагностика адреса</h2>';
    echo '<p>Показывает, на каком шаге теряется адрес. Ничего не создаёт и не меняет заказ.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
    wp_nonce_field('dpd_diag');
    echo '<input type="hidden" name="action" value="dpd_diagnose" />';
    echo '<input type="number" min="1" name="order_id" placeholder="ID заказа" value="' . esc_attr(is_array($data) ? (int) $data['order_id'] : '') . '" /> ';
    submit_button('Проверить адрес', 'secondary', 'submit', false);
    echo '</form>';
    if (!is_array($data)) { echo '<p>Введите ID проблемного заказа.</p>'; return; }
    echo '<table class="widefat striped"><thead><tr><th>Шаг</th><th>Результат</th><th>Что это значит</th></tr></thead><tbody>';
    foreach ($data['steps'] as $step) {
        $color = $step['status'] === 'ok' ? '#1a7f37' : ($step['status'] === 'fail' ? '#b32d2e' : '#996800');
        echo '<tr><td><strong>' . esc_html($step['step']) . '</strong></td><td style="color:' . esc_attr($color) . '"><strong>' . esc_html(strtoupper($step['status'])) . '</strong></td><td>' . esc_html($step['detail']) . '</td></tr>';
    }
    echo '</tbody></table>';
    if (!empty($data['message'])) { echo '<p><strong>' . esc_html($data['message']) . '</strong></p>'; }
    if (is_array($data['relay'] ?? null)) {
        $r = $data['relay'];
        echo '<p><strong>Проверка в Достависте:</strong> ' . esc_html(strtoupper($r['status'])) . ' — ' . esc_html($r['detail']) . '</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:10px">';
    wp_nonce_field('dpd_diag');
    echo '<input type="hidden" name="action" value="dpd_clear_cache" /><input type="hidden" name="order_id" value="' . esc_attr((int) $data['order_id']) . '" />';
    submit_button('Сбросить кэш адреса', 'secondary', 'submit', false);
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
    wp_nonce_field('dpd_diag');
    echo '<input type="hidden" name="action" value="dpd_relay" /><input type="hidden" name="order_id" value="' . esc_attr((int) $data['order_id']) . '" />';
    submit_button('Проверить адрес в Достависте (только тест)', 'secondary', 'submit', false);
    echo '</form>';
    echo '<p class="description">Проверка в Достависте выполняет только расчёт (calculate-order) в тестовой среде. Заявка не создаётся.</p>';
    echo '<h3>Ручная команда API</h3>';
    echo '<p>Создаёт заявку в обход официального модуля. Среда: <strong>' . esc_html(dpd_is_test() ? 'тестовая' : 'БОЕВАЯ') . '</strong>. Повторная заявка по тому же заказу не создаётся.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('dpd_diag');
    echo '<input type="hidden" name="action" value="dpd_dispatch_now" /><input type="hidden" name="order_id" value="' . esc_attr((int) $data['order_id']) . '" />';
    echo '<label><input type="checkbox" name="confirm" value="yes" /> Подтверждаю отправку заявки в Достависту</label><br />';
    submit_button('Создать заявку сейчас', 'primary', 'submit', false);
    echo '</form>';
}

function dpd_admin_save() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_save');
    $current = dpd_settings();
    $post = wp_unslash($_POST);
    $next = $current;
    $next['enabled'] = !empty($post['enabled']);
    $next['environment'] = ($post['environment'] ?? 'test') === 'production' ? 'production' : 'test';
    foreach (array('token_test', 'token', 'callback_secret') as $key) {
        $value = trim((string) ($post[$key] ?? ''));
        if (preg_match('/[\x00-\x20\x7f]/', $value) || strlen($value) > 512) { $value = ''; }
        if ($value !== '') { $next[$key] = $value; } // blank field keeps the stored secret
    }
    $next['restaurant_address'] = mb_substr(sanitize_text_field((string) ($post['restaurant_address'] ?? '')), 0, 1000);
    $next['restaurant_phone'] = mb_substr(sanitize_text_field((string) ($post['restaurant_phone'] ?? '')), 0, 80);
    $walk = (float) ($post['walk_max_km'] ?? 2);
    $next['walk_max_km'] = ($walk > 0 && $walk <= 20) ? number_format($walk, 2, '.', '') : '2.00';
    if (($post['clear_tokens'] ?? '') === '1') { $next['token'] = ''; $next['token_test'] = ''; }
    update_option(DPD_OPT, $next, 'no');
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery', 'saved' => '1'), admin_url('admin.php')));
    exit;
}

function dpd_admin_page() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    $s = dpd_settings();
    $env = dpd_is_test() ? 'тестовая' : 'БОЕВАЯ';
    $has_token = dpd_active_token() !== '';
    echo '<div class="wrap"><h1>Dompekin Delivery</h1>';
    if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>'; }
    echo '<p><strong>Среда: ' . esc_html($env) . '</strong> · Автовызов: ' . (dpd_enabled() ? '<strong>включён</strong>' : 'выключен') . ' · Токен: ' . ($has_token ? 'задан' : 'не задан') . '</p>';
    echo '<p>Токен вводится только здесь и не отображается. Он не передаётся в чат и не пишется в отчёты. Ключи хранятся в базе сайта в открытом виде — доступ к базе должен быть ограничен.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('dpd_save');
    echo '<input type="hidden" name="action" value="dpd_save" />';
    echo '<table class="form-table">';
    echo '<tr><th>Автовызов после принятия</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked(dpd_enabled(), true, false) . ' /> Вызывать курьера автоматически после принятия оплаченного заказа</label></td></tr>';
    echo '<tr><th>Среда API</th><td><select name="environment"><option value="test" ' . selected($s['environment'], 'test', false) . '>Тестовая</option><option value="production" ' . selected($s['environment'], 'production', false) . '>Боевая</option></select><p class="description">Тестовая среда не создаёт реальных доставок.</p></td></tr>';
    echo '<tr><th>Токен (тест)</th><td><input type="password" name="token_test" class="regular-text" autocomplete="off" placeholder="' . ($s['token_test'] !== '' ? 'сохранён' : 'не задан') . '" /></td></tr>';
    echo '<tr><th>Токен (боевой)</th><td><input type="password" name="token" class="regular-text" autocomplete="off" placeholder="' . ($s['token'] !== '' ? 'сохранён' : 'не задан') . '" /></td></tr>';
    echo '<tr><th>Адрес ресторана</th><td><input type="text" name="restaurant_address" class="large-text" value="' . esc_attr($s['restaurant_address']) . '" /></td></tr>';
    echo '<tr><th>Телефон ресторана</th><td><input type="text" name="restaurant_phone" class="regular-text" value="' . esc_attr($s['restaurant_phone']) . '" /></td></tr>';
    echo '<tr><th>Пеший курьер до, км</th><td><input type="number" step="0.1" min="0.1" max="20" name="walk_max_km" value="' . esc_attr($s['walk_max_km']) . '" /></td></tr>';
    echo '<tr><th>Секрет уведомлений</th><td><input type="password" name="callback_secret" class="regular-text" autocomplete="off" placeholder="' . ($s['callback_secret'] !== '' ? 'сохранён' : 'не задан') . '" /><p class="description">Адрес уведомлений: ' . esc_html(rest_url('dpd/v1/callback')) . '</p></td></tr>';
    echo '<tr><th>Очистить токены</th><td><label><input type="checkbox" name="clear_tokens" value="1" /> Удалить сохранённые токены</label></td></tr>';
    echo '</table>';
    submit_button('Сохранить');
    echo '</form>';
    echo '<h2>Состояние</h2><ul>';
    echo '<li>Геокодер темы: ' . (function_exists('dp_zone_geocode') ? 'доступен' : 'недоступен') . '</li>';
    echo '<li>Координаты ресторана: ' . (is_wp_error(dpd_restaurant_point()) ? 'не определены' : 'определены') . '</li>';
    echo '<li>Старый автовызов темы: ' . (get_option('dp_dv_auto', 0) ? '<strong>включён — возможны дубли</strong>' : 'выключен') . '</li>';
    echo '<li>Плагин Достависта активен: ' . (class_exists('Dostavista') || function_exists('dostavista_init') ? 'да' : 'нет') . '</li>';
    echo '</ul><p><strong>Блокировка:</strong> повторная заявка по тому же заказу в той же среде не создаётся. При неизвестном результате автоматического повтора нет — требуется сверка в кабинете.</p>';
    echo '<p>Плагин не завершает заказ автоматически по событию «доставлено»: событие фиксируется, решение — за оператором. Автоматическое завершение будет добавлено после проверки callback на реальных событиях.</p>';
    dpd_render_geo_check();
    dpd_render_diagnostics();
    echo '</div>';
}
