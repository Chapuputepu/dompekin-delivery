<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Наблюдение за HTTP-запросами к Достависте.
 * Пишет только то, что нужно для разбора: хост, путь, метод, тело (с маскировкой
 * телефонов и почты) и код ответа. Ничего не блокирует и не меняет.
 * Журнал хранится в опции, виден только администратору магазина.
 */

function dpd_http_log() {
    $log = get_option('dpd_http_log', array());
    return is_array($log) ? $log : array();
}

function dpd_http_log_push($entry) {
    $log = dpd_http_log();
    array_unshift($log, $entry);
    $log = array_slice($log, 0, 20);
    update_option('dpd_http_log', $log, false);
}

function dpd_redact_text($text) {
    $text = is_string($text) ? $text : '';
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $text);
    $text = preg_replace('/\+?\d[\d\s\-()]{9,}/u', '[phone]', $text);
    if (function_exists('mb_substr')) { return mb_substr($text, 0, 1200, 'UTF-8'); }
    return substr($text, 0, 1200);
}

function dpd_is_dostavista_url($url) {
    return (bool) preg_match('#dostavista\.ru#i', (string) $url);
}

add_filter('http_request_args', 'dpd_observe_http_request', 10, 2);
function dpd_observe_http_request($args, $url) {
    if (!dpd_is_dostavista_url($url)) { return $args; }

    $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
    $source = 'чужой код';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'x-dpd-plugin') { $source = 'Dompekin Delivery'; }
    }

    dpd_http_log_push(array(
        'kind' => 'request',
        'time' => time(),
        'source' => $source,
        'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'path' => (string) wp_parse_url($url, PHP_URL_PATH),
        'method' => isset($args['method']) ? (string) $args['method'] : 'GET',
        'body' => dpd_redact_text(isset($args['body']) ? $args['body'] : ''),
        'status' => null,
    ));
    return $args;
}

add_action('http_response', 'dpd_observe_http_response', 10, 3);
function dpd_observe_http_response($response, $args, $url) {
    if (!dpd_is_dostavista_url($url)) { return; }
    $status = is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    $body = '';
    if (is_array($response) && isset($response['body'])) { $body = (string) $response['body']; }

    dpd_http_log_push(array(
        'kind' => 'response',
        'time' => time(),
        'source' => '',
        'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'path' => (string) wp_parse_url($url, PHP_URL_PATH),
        'method' => '',
        'body' => dpd_redact_text($body),
        'status' => $status,
    ));
}

add_action('admin_post_dpd_clear_http_log', 'dpd_clear_http_log');
function dpd_clear_http_log() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_http_log');
    delete_option('dpd_http_log');
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery'), admin_url('admin.php')));
    exit;
}

function dpd_render_http_log() {
    if (!current_user_can('manage_woocommerce')) { return; }
    $log = dpd_http_log();

    echo '<h2>Журнал запросов к Достависте</h2>';
    echo '<p>Показывает, что реально уходит на их серверы. Телефоны и почта маскируются. Хранятся последние 20 записей.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
    wp_nonce_field('dpd_http_log');
    echo '<input type="hidden" name="action" value="dpd_clear_http_log" />';
    submit_button('Очистить журнал', 'secondary', 'submit', false);
    echo '</form>';

    if (!$log) {
        echo '<p><strong>Записей нет.</strong> Если официальный плагин уже вызывал Достависту, значит он ходит не через wp_remote_* (например, напрямую через cURL) — тогда нужен другой перехват.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Время</th><th>Тип</th><th>Источник</th><th>Запрос</th><th>Ответ</th><th>Тело</th></tr></thead><tbody>';
    foreach ($log as $row) {
        $when = date_i18n('d.m H:i:s', (int) ($row['time'] ?? 0));
        $is_request = ($row['kind'] ?? '') === 'request';
        echo '<tr>';
        echo '<td>' . esc_html($when) . '</td>';
        echo '<td>' . esc_html($is_request ? 'запрос' : 'ответ') . '</td>';
        echo '<td>' . esc_html((string) ($row['source'] ?? '')) . '</td>';
        echo '<td>' . esc_html(trim(($row['method'] ?? '') . ' ' . ($row['host'] ?? '') . ($row['path'] ?? ''))) . '</td>';
        echo '<td>' . esc_html($row['status'] === null ? '—' : (string) (int) $row['status']) . '</td>';
        echo '<td><code style="white-space:pre-wrap;word-break:break-all">' . esc_html((string) ($row['body'] ?? '')) . '</code></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
