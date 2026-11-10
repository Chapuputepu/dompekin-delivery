<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Поиск кода и настроек Telegram на сайте. Только чтение:
 * сканирует темы, плагины, mu-plugins, REST-маршруты, опции и cron.
 * Секреты (токены ботов, длинные ключи) маскируются.
 */

function dpd_mask_secrets($text) {
    $text = is_string($text) ? $text : '';
    // Токен бота: 123456789:AA... (иногда с префиксом bot)
    $text = preg_replace('/\b(?:bot)?\d{6,12}:[A-Za-z0-9_-]{25,}/', '[bot-token]', $text);
    $text = preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[hex-secret]', $text);
    $text = preg_replace('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/', '[jwt]', $text);
    if (function_exists('mb_substr')) { return mb_substr($text, 0, 220, 'UTF-8'); }
    return substr($text, 0, 220);
}

function dpd_telegram_scan_dirs() {
    $dirs = array();
    $theme = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : '';
    $parent = function_exists('get_template_directory') ? get_template_directory() : '';
    foreach (array($theme, $parent) as $dir) {
        if ($dir && is_dir($dir)) { $dirs['theme:' . basename($dir)] = $dir; }
    }
    if (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)) { $dirs['plugins'] = WP_PLUGIN_DIR; }
    if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) { $dirs['mu-plugins'] = WPMU_PLUGIN_DIR; }
    return $dirs;
}

function dpd_scan_telegram_files($limit_matches = 80, $limit_files = 2500) {
    $patterns = array(
        'telegram.org' => '/telegram\.org/i',
        'tgWebApp' => '/tgWebApp|Telegram\.WebApp|initData/i',
        'bot token' => '/\b(?:bot)?\d{6,12}:[A-Za-z0-9_-]{25,}/',
        'api.telegram' => '/api\.telegram\.org/i',
        'dp_tg' => '/\bdp_tg_[a-z_]+/i',
        'webhook' => '/setWebhook|getWebhookInfo|webhook/i',
    );
    $ext = array('php', 'js', 'mjs', 'html', 'htm', 'json', 'txt');

    $matches = array();
    $files_seen = 0;
    $skipped_big = 0;

    foreach (dpd_telegram_scan_dirs() as $group => $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($files_seen >= $limit_files || count($matches) >= $limit_matches) { break 2; }
            if (!$file->isFile()) { continue; }
            $path = $file->getPathname();
            if (preg_match('#/(node_modules|vendor|\.git|uploads)/#', str_replace('\\', '/', $path))) { continue; }
            if (!in_array(strtolower($file->getExtension()), $ext, true)) { continue; }
            if ($file->getSize() > 1048576) { $skipped_big++; continue; }

            $files_seen++;
            $content = @file_get_contents($path);
            if (!is_string($content) || $content === '') { continue; }
            $lines = preg_split('/\r\n|\r|\n/', $content);
            if (!is_array($lines)) { continue; }

            foreach ($lines as $number => $line) {
                if (count($matches) >= $limit_matches) { break 2; }
                foreach ($patterns as $label => $regex) {
                    if (preg_match($regex, $line)) {
                        $matches[] = array(
                            'group' => $group,
                            'file' => str_replace(WP_CONTENT_DIR, '', $path),
                            'line' => $number + 1,
                            'what' => $label,
                            'snippet' => dpd_mask_secrets(trim($line)),
                        );
                        break;
                    }
                }
            }
        }
    }

    return array('matches' => $matches, 'files_scanned' => $files_seen, 'skipped_big' => $skipped_big);
}

function dpd_scan_telegram_routes() {
    $out = array();
    if (!function_exists('rest_get_server')) { return $out; }
    $routes = rest_get_server()->get_routes();
    foreach ($routes as $route => $handlers) {
        if (!preg_match('/tg|telegram|dp\/v1|dpd\/v1/i', $route)) { continue; }
        $methods = array();
        if (is_array($handlers)) {
            foreach ($handlers as $handler) {
                if (isset($handler['methods']) && is_array($handler['methods'])) {
                    foreach ($handler['methods'] as $method => $enabled) { if ($enabled) { $methods[] = $method; } }
                }
            }
        }
        $out[] = array('route' => $route, 'methods' => implode(',', array_unique($methods)));
    }
    return $out;
}

function dpd_scan_telegram_options() {
    global $wpdb;
    if (!is_object($wpdb)) { return array(); }
    $rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%tg%' OR option_name LIKE '%telegram%' ORDER BY option_name LIMIT 50");
    return is_array($rows) ? $rows : array();
}

function dpd_scan_telegram_cron() {
    $out = array();
    if (!function_exists('_get_cron_array')) { return $out; }
    $cron = _get_cron_array();
    if (!is_array($cron)) { return $out; }
    foreach ($cron as $timestamp => $hooks) {
        if (!is_array($hooks)) { continue; }
        foreach (array_keys($hooks) as $hook) {
            if (preg_match('/tg|telegram/i', $hook)) {
                $out[] = array('hook' => $hook, 'next' => (int) $timestamp);
            }
        }
    }
    return $out;
}

function dpd_run_telegram_scan() {
    return array(
        'time' => time(),
        'files' => dpd_scan_telegram_files(),
        'routes' => dpd_scan_telegram_routes(),
        'options' => dpd_scan_telegram_options(),
        'cron' => dpd_scan_telegram_cron(),
    );
}

add_action('admin_post_dpd_tg_scan', 'dpd_admin_tg_scan');
function dpd_admin_tg_scan() {
    if (!current_user_can('manage_woocommerce')) { wp_die('Недостаточно прав.'); }
    check_admin_referer('dpd_tg_scan');
    set_transient('dpd_tg_scan_' . get_current_user_id(), dpd_run_telegram_scan(), 600);
    wp_safe_redirect(add_query_arg(array('page' => 'dompekin-delivery'), admin_url('admin.php')));
    exit;
}

function dpd_render_telegram_scan() {
    if (!current_user_can('manage_woocommerce')) { return; }
    $data = get_transient('dpd_tg_scan_' . get_current_user_id());

    echo '<h2>Где Telegram</h2>';
    echo '<p>Сканирует темы, плагины и mu-plugins на упоминания Telegram, показывает REST-маршруты, опции и задачи cron. Только чтение, токены маскируются.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
    wp_nonce_field('dpd_tg_scan');
    echo '<input type="hidden" name="action" value="dpd_tg_scan" />';
    submit_button('Найти код Telegram', 'secondary', 'submit', false);
    echo '</form>';

    if (!is_array($data)) { return; }

    $files = $data['files'];
    echo '<p><strong>Файлов просмотрено:</strong> ' . (int) $files['files_scanned'] . ' · <strong>Совпадений:</strong> ' . count($files['matches']) . ' · <strong>Пропущено крупных:</strong> ' . (int) $files['skipped_big'] . '</p>';

    if ($files['matches']) {
        echo '<table class="widefat striped"><thead><tr><th>Файл</th><th>Строка</th><th>Что</th><th>Фрагмент</th></tr></thead><tbody>';
        foreach ($files['matches'] as $m) {
            echo '<tr><td><code>' . esc_html($m['file']) . '</code></td><td>' . (int) $m['line'] . '</td><td>' . esc_html($m['what']) . '</td><td><code>' . esc_html($m['snippet']) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p><strong>В файлах сайта Telegram не найден.</strong> Значит мини-приложение живёт не в WordPress: отдельный хостинг, Vercel/Netlify, или файл в самом расширении Chrome.</p>';
    }

    if ($data['routes']) {
        echo '<h3>REST-маршруты</h3><ul>';
        foreach ($data['routes'] as $r) {
            echo '<li><code>' . esc_html($r['route']) . '</code> → ' . esc_html($r['methods']) . '</li>';
        }
        echo '</ul>';
    }

    if ($data['options']) {
        echo '<h3>Опции с tg/telegram</h3><p><code>' . esc_html(implode('</code>, <code>', $data['options'])) . '</code></p>';
    }

    if ($data['cron']) {
        echo '<h3>Задачи cron</h3><ul>';
        foreach ($data['cron'] as $c) {
            echo '<li><code>' . esc_html($c['hook']) . '</code> — следующая ' . esc_html(date_i18n('d.m H:i', $c['next'])) . '</li>';
        }
        echo '</ul>';
    }
}
