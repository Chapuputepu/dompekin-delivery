<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Адрес для геокодера и Достависты.
 * Проблема старого кода: get_formatted_shipping_address() содержит <br/>,
 * а wp_strip_all_tags() удаляет их без пробела — части склеиваются
 * ("...домофон 17Новосибирск"), геокодер такой адрес не распознаёт.
 */
function dpd_clean_text($value) {
    if (!is_string($value)) { return ''; }
    $value = str_replace(array('<br/>', '<br />', '<br>'), ', ', $value);
    $value = wp_strip_all_tags($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = preg_replace('/\s*,\s*(,\s*)+/u', ', ', $value);
    return trim($value, " \t\n\r\0\x0B,;");
}

/** Только город + улица + дом. Квартира, подъезд, домофон не нужны курьеру и мешают геокодеру. */
function dpd_order_address($order) {
    $shipping_city = dpd_clean_text($order->get_shipping_city());
    $billing_city = dpd_clean_text($order->get_billing_city());
    $city = $shipping_city !== '' ? $shipping_city : $billing_city;

    $shipping_line = dpd_clean_text($order->get_shipping_address_1());
    $billing_line = dpd_clean_text($order->get_billing_address_1());
    $line = $shipping_line !== '' ? $shipping_line : $billing_line;
    $source = $shipping_line !== '' ? 'адрес доставки' : ($billing_line !== '' ? 'платёжный адрес (запасной)' : 'нет');

    $parts = array();
    if ($city !== '') { $parts[] = $city; }
    if ($line !== '') { $parts[] = $line; }

    $text = implode(', ', $parts);

    return array(
        'text' => $text,
        'city' => $city,
        'line' => $line,
        'source' => $source,
        'raw_formatted' => dpd_clean_text($order->get_formatted_shipping_address()),
        'has_street_number' => (bool) preg_match('/\d/u', $line),
        'length' => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text),
    );
}
