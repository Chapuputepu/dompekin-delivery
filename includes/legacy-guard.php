<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Замок на старый путь темы.
 * После выключения официального плагина Достависты функция dp_dv_create обращается
 * к его классам через Reflection и падает с фатальной ошибкой в момент «Принять».
 * Поэтому события dp_dv_auto_call не планируются, а уже стоящие в очереди снимаются.
 */

function dpd_legacy_hook() {
    return 'dp_dv_auto_call';
}

function dpd_legacy_state() {
    $state = get_option('dpd_legacy_state', array());
    return is_array($state) ? $state : array();
}

function dpd_legacy_note($what, $count = 1) {
    $state = dpd_legacy_state();
    $state[$what] = (int) ($state[$what] ?? 0) + (int) $count;
    $state['time'] = time();
    update_option('dpd_legacy_state', $state, false);
}

/** Не даёт теме снова поставить в очередь вызов через выключенный плагин. */
add_filter('pre_schedule_event', 'dpd_block_legacy_schedule', 10, 2);
function dpd_block_legacy_schedule($event, $wp_error = false) {
    if (!is_object($event) || !isset($event->hook)) { return $event; }
    if ($event->hook !== dpd_legacy_hook()) { return $event; }
    dpd_legacy_note('blocked');

    if ($wp_error) {
        return new WP_Error('dpd_legacy_disabled', 'Старый автовызов темы отключён: заявки создаёт Dompekin Delivery.');
    }
    return false;
}

/** Снимает уже запланированные события старого пути; не чаще раза в час. */
add_action('wp_loaded', 'dpd_clear_legacy_events', 25);
function dpd_clear_legacy_events() {
    if (get_transient('dpd_legacy_check')) { return; }
    set_transient('dpd_legacy_check', 1, HOUR_IN_SECONDS);
    if (!function_exists('wp_next_scheduled')) { return; }

    $removed = 0;
    while ($removed < 50) {
        $timestamp = wp_next_scheduled(dpd_legacy_hook());
        if ($timestamp === false) { break; }
        wp_unschedule_event($timestamp, dpd_legacy_hook());
        $removed++;
    }
    if ($removed > 0) { dpd_legacy_note('removed', $removed); }
}
