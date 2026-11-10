<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Правка страницы «Доставка и оплата».
 * После перехода на локальную доставку два обещания стали неправдой:
 * «вне зоны доступен самовывоз» и «рассчитывается по тарифу Достависты».
 * Замена одноразовая, с ревизией страницы и записью результата в панель.
 */

function dpd_content_fix_pairs() {
    $phone = '+7 913 066-11-59';
    return array(
        '<h2>Способы получения</h2>' => '<h2>Доставка</h2>',
        '<p><strong>Самовывоз.</strong> Бесплатно. Адрес: г. Новосибирск, ул. Мичурина, 12. Время приготовления обычно 20–30 минут; точное время сообщим при подтверждении заказа.</p>'
            => '<p>Готовим обычно 20–30 минут; точное время сообщим при подтверждении заказа.</p>',
        'Если адрес находится вне зоны, при оформлении заказа вы увидите сообщение об этом; в таком случае доступен самовывоз из ресторана.'
            => 'Доставка только по Новосибирску. Если ваш адрес вне зоны, оформить заказ на сайте нельзя — позвоните нам: ' . $phone . '.',
        'Стоимость доставки рассчитывается автоматически по вашему адресу при оформлении заказа (по тарифу сервиса «Достависта») и показывается до подтверждения заказа.'
            => 'Стоимость доставки рассчитывается по вашему адресу при оформлении заказа и показывается до оплаты. Доставку выполняет сервис «Достависта».',
        'Жёлтым выделена зона курьерской доставки. Если ваш адрес вне зоны, доступен самовывоз.'
            => 'Жёлтым выделена зона курьерской доставки. Если ваш адрес вне зоны, оформить заказ на сайте нельзя — позвоните нам.',
    );
}

/** Замена с учётом неразрывных пробелов и &nbsp; в разметке. */
function dpd_replace_sentence($content, $from, $to) {
    if (strpos($content, $from) !== false) {
        return array('content' => str_replace($from, $to, $content), 'replaced' => true);
    }
    $words = preg_split('/\s+/u', trim($from));
    if (!$words) { return array('content' => $content, 'replaced' => false); }
    $parts = array();
    foreach ($words as $word) {
        if ($word === '') { continue; }
        $parts[] = preg_quote($word, '/');
    }
    $pattern = '/' . implode('(?:&nbsp;|[\s\x{00A0}])+', $parts) . '/u';
    $result = preg_replace($pattern, str_replace(array('\\', '$'), array('\\\\', '\\$'), $to), $content, 1, $count);
    if ($result === null) { return array('content' => $content, 'replaced' => false); }
    return array('content' => $result, 'replaced' => ((int) $count) > 0);
}

function dpd_content_state() {
    $state = get_option('dpd_content_fix_state', array());
    return is_array($state) ? $state : array();
}

add_action('wp_loaded', 'dpd_maybe_fix_delivery_page', 26);
function dpd_maybe_fix_delivery_page() {
    if (get_option('dpd_content_fix_v1')) { return; }
    if (get_transient('dpd_content_fix_lock')) { return; }
    set_transient('dpd_content_fix_lock', 1, 10 * MINUTE_IN_SECONDS);

    $page = get_page_by_path('delivery-payment');
    if (!$page || empty($page->ID)) {
        update_option('dpd_content_fix_state', array('time' => time(), 'result' => 'страница delivery-payment не найдена'), false);
        return;
    }

    $content = (string) $page->post_content;
    $replaced = 0;
    $missing = array();
    foreach (dpd_content_fix_pairs() as $from => $to) {
        $attempt = dpd_replace_sentence($content, $from, $to);
        if ($attempt['replaced']) {
            $content = $attempt['content'];
            $replaced++;
        } else {
            $missing[] = (function_exists('mb_substr') ? mb_substr($from, 0, 40, 'UTF-8') : substr($from, 0, 40)) . '…';
        }
    }

    if ($replaced === 0) {
        update_option('dpd_content_fix_state', array(
            'time' => time(),
            'result' => 'ничего не заменено; не найдено: ' . implode(' | ', $missing),
        ), false);
        return;
    }

    $result = wp_update_post(array('ID' => (int) $page->ID, 'post_content' => $content), true);
    if (is_wp_error($result)) {
        update_option('dpd_content_fix_state', array('time' => time(), 'result' => 'ошибка сохранения: ' . $result->get_error_message()), false);
        return;
    }

    update_option('dpd_content_fix_v1', 1, false);
    update_option('dpd_content_fix_state', array(
        'time' => time(),
        'result' => 'заменено фрагментов: ' . $replaced . ($missing ? '; не найдено: ' . implode(' | ', $missing) : ''),
        'page' => (int) $page->ID,
    ), false);
}
