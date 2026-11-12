<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Наблюдение за исходящими HTTP-запросами, важными для доставки:
 * Достависта и геокодер Яндекса.
 *
 * Второй источник — не роскошь: `geo_http` означает отказ HTTP-запроса геокодера,
 * а не «плохой адрес клиента». Без журнала причину не установить.
 *
 * Пишет хост, путь, метод, код ответа и тело с маскировкой телефонов, почты и
 * ключа API. Ничего не блокирует и не меняет настройки.
 * Ограничение: перехват идёт через wp_remote_*; прямой cURL темы не виден.
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
    // Ключ геокодера и любые длинные токены в query/JSON не должны попадать в журнал.
    $text = preg_replace('/(apikey|api_key|key|token)(["\']?\s*[=:]\s*["\']?)[^\s&"\']+/iu', '$1$2[скрыт]', $text);
    if (function_exists('mb_substr')) { return mb_substr($text, 0, 1200, 'UTF-8'); }
    return substr($text, 0, 1200);
}

function dpd_is_dostavista_url($url) {
    return (bool) preg_match('#dostavista\.ru#i', (string) $url);
}

/** Геокодер темы: именно его отказ даёт geo_http и блокирует заявку. */
function dpd_is_geocoder_url($url) {
    return (bool) preg_match('#(geocode-maps\.yandex|yandex\.ru/maps/api/geocode|nominatim|2gis)#i', (string) $url);
}

function dpd_is_watched_url($url) {
    return dpd_is_dostavista_url($url) || dpd_is_geocoder_url($url);
}

add_filter('http_request_args', 'dpd_observe_http_request', 10, 2);
function dpd_observe_http_request($args, $url) {
    if (!dpd_is_watched_url($url)) { return $args; }

    $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
    $source = dpd_is_geocoder_url($url) ? 'геокодер темы' : 'чужой код';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'x-dpd-plugin') { $source = 'Dompekin Delivery'; }
    }

    dpd_http_log_push(array(
        'kind' => 'request',
        'time' => time(),
        'source' => $source,
        'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'path' => dpd_redact_text((string) wp_parse_url($url, PHP_URL_PATH)),
        'method' => isset($args['method']) ? (string) $args['method'] : 'GET',
        'body' => dpd_redact_text(isset($args['body']) ? $args['body'] : ''),
        'status' => null,
    ));
    return $args;
}

add_action('http_response', 'dpd_observe_http_response', 10, 3);
function dpd_observe_http_response($response, $args, $url) {
    if (!dpd_is_watched_url($url)) { return $response; }
    $status = is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    $body = '';
    if (is_array($response) && isset($response['body'])) { $body = (string) $response['body']; }

    dpd_http_log_push(array(
        'kind' => 'response',
        'time' => time(),
        'source' => '',
        'host' => (string) wp_parse_url($url, PHP_URL_HOST),
        'path' => dpd_redact_text((string) wp_parse_url($url, PHP_URL_PATH)),
        'method' => '',
        'body' => dpd_redact_text($body),
        'status' => $status,
    ));
    return $response;
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

    echo '<h2>Журнал запросов: Достависта и геокодер</h2>';
    echo '<p>Показывает, что реально уходит на серверы Достависты и геокодера. Коды 401/403 у геокодера объясняют <code>geo_http</code>. Телефоны, почта и ключ маскируются. Хранятся последние 20 записей. Запросы напрямую через cURL, мимо <code>wp_remote_*</code>, здесь не видны.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
    wp_nonce_field('dpd_http_log');
    echo '<input type="hidden" name="action" value="dpd_clear_http_log" />';
    submit_button('Очистить журнал', 'secondary', 'submit', false);
    echo '</form>';

    if (!$log) {
        echo '<p><strong>Записей нет.</strong> Если геокодер уже вызывался, значит тема ходит напрямую через cURL — тогда нужен другой перехват: смотреть лог ошибок PHP или Loco/дебаг хостинга.</p>';
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
