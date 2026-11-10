<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Класс объявляется не при загрузке плагина, а когда WooCommerce загрузил
 * свои классы доставки. Плагин грузится раньше WooCommerce, поэтому
 * проверка class_exists('WC_Shipping_Method') на старте всегда падала.
 */
function dpd_define_courier_method() {
    if (class_exists('WC_Shipping_Dpd_Courier')) { return; }
    if (!class_exists('WC_Shipping_Method')) { return; }

    /**
     * Курьерская доставка Доставистой.
     * Цена берётся из настроек метода, а не из темы: нулевой тариф не появится молча.
     * Порог бесплатной доставки и город ограничены, чтобы не увезти заказ в другой регион.
     */
    class WC_Shipping_Dpd_Courier extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {
            $this->id = 'dpd_courier';
            $this->instance_id = absint($instance_id);
            $this->method_title = 'Доставка Доставистой';
            $this->method_description = 'Курьерская доставка по Новосибирску. Стоимость задаётся здесь; при сумме от порога — бесплатно.';
            $this->supports = array('shipping-zones', 'instance-settings', 'instance-settings-modal');
            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'Доставка курьером');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => 'Название',
                    'type' => 'text',
                    'default' => 'Доставка курьером',
                ),
                'cost' => array(
                    'title' => 'Стоимость, ₽',
                    'type' => 'number',
                    'default' => '145',
                    'description' => 'Берётся из тестового расчёта Достависты. Меняется без деплоя.',
                ),
                'free_from' => array(
                    'title' => 'Бесплатно от, ₽',
                    'type' => 'number',
                    'default' => '4000',
                ),
                'city' => array(
                    'title' => 'Город доставки',
                    'type' => 'text',
                    'default' => 'Новосибирск',
                    'description' => 'Если адрес в другом городе, курьерский тариф не показывается. Пустое поле — без ограничения.',
                ),
                'price_mode' => array(
                    'title' => 'Как считать цену',
                    'type' => 'select',
                    'default' => 'bands',
                    'options' => array(
                        'bands' => 'По расстоянию (таблица ниже)',
                        'live' => 'Онлайн-расчёт Достависты (кэш 15 мин)',
                        'fixed' => 'Фиксированная стоимость',
                    ),
                    'description' => 'При онлайн-расчёте любая ошибка API возвращает табличную цену, а не пустой тариф.',
                ),
                'bands' => array(
                    'title' => 'Таблица: до_км | пеший | авто',
                    'type' => 'textarea',
                    'default' => "2|250|320\n5|320|450\n10|450|600\n20|600|800",
                    'description' => 'Одна строка — одна полоса расстояния. Пример: 2|250|320 значит «до 2 км: пеший 250 ₽, авто 320 ₽». Цифры нужно сверить с Доставистой.',
                ),
            );
        }

        public function is_available($package = array()) {
            if ($this->enabled !== 'yes') { return false; }
            return parent::is_available($package);
        }

        /** Проверка города без mbstring: PCRE с флагом u. Индекс Новосибирска — 630xxx. */
        public function dpd_city_allowed($city, $postcode = '') {
            $wanted = trim((string) $this->get_option('city', 'Новосибирск'));
            if ($wanted === '') { return true; }
            $city = trim((string) $city);
            if ($city !== '') {
                $pattern = '/' . preg_quote($wanted, '/') . '/iu';
                if (preg_match($pattern, $city)) { return true; }
            }
            $postcode = trim((string) $postcode);
            return (bool) preg_match('/^630\d{3}$/', $postcode);
        }

        public function dpd_cart_subtotal($package) {
            if (function_exists('WC') && WC()->cart && method_exists(WC()->cart, 'get_subtotal')) {
                $subtotal = (float) WC()->cart->get_subtotal();
                if ($subtotal > 0) { return $subtotal; }
            }
            return isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0;
        }

        public function calculate_shipping($package = array()) {
            $city = isset($package['destination']['city']) ? $package['destination']['city'] : '';
            $postcode = isset($package['destination']['postcode']) ? $package['destination']['postcode'] : '';
            if (!$this->dpd_city_allowed($city, $postcode)) { return; }

            $fallback = (float) $this->get_option('cost', 250);
            $free_from = (float) $this->get_option('free_from', 4000);
            $subtotal = $this->dpd_cart_subtotal($package);

            $mode = (string) $this->get_option('price_mode', 'bands');
            $cost = null;
            $note = '';

            if ($mode === 'fixed') {
                $cost = $fallback;
                $note = 'фиксированная';
            } else {
                $street = isset($package['destination']['address_1']) ? trim((string) $package['destination']['address_1']) : '';
                if ($street === '' && isset($package['destination']['address'])) { $street = trim((string) $package['destination']['address']); }
                $address = trim($city . ($street !== '' ? ', ' . $street : ''));

                if ($address === '') {
                    $note = 'адрес не заполнен — запасная цена';
                } elseif (!function_exists('dpd_distance_km')) {
                    $note = 'геокодер недоступен — запасная цена';
                } else {
                    $distance = dpd_distance_km($address);
                    if (is_wp_error($distance)) {
                        $note = 'расстояние не определено — запасная цена';
                    } else {
                        $vehicle = function_exists('dpd_vehicle_for') ? (int) dpd_vehicle_for($distance) : 7;
                        $note = sprintf('%.2f км, транспорт %d', (float) $distance, $vehicle);

                        if ($mode === 'live' && function_exists('dpd_quote_amount')) {
                            $quote = dpd_quote_amount($address, $vehicle);
                            if ($quote['state'] === 'ok') {
                                $cost = (float) $quote['amount'];
                                $note .= ', онлайн';
                            } else {
                                $note .= ', онлайн не удался';
                            }
                        }
                        if ($cost === null && function_exists('dpd_price_from_bands')) {
                            $bands = dpd_price_bands((string) $this->get_option('bands', ''));
                            $cost = dpd_price_from_bands($bands, (float) $distance, $vehicle, $fallback);
                            $note .= ', таблица';
                        }
                    }
                }
            }

            if ($cost === null) { $cost = $fallback; }
            if ($free_from > 0 && $subtotal >= $free_from) { $cost = 0.0; }

            $this->add_rate(array(
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => $cost,
                'package' => $package,
                'meta_data' => array('dpd_subtotal' => $subtotal, 'dpd_price_note' => $note),
            ));
        }
    }
}

add_action('woocommerce_shipping_init', 'dpd_define_courier_method');
add_action('init', 'dpd_define_courier_method', 30);

add_filter('woocommerce_shipping_methods', 'dpd_register_shipping_method');
function dpd_register_shipping_method($methods) {
    dpd_define_courier_method();
    if (class_exists('WC_Shipping_Dpd_Courier')) {
        $methods['dpd_courier'] = 'WC_Shipping_Dpd_Courier';
    }
    return $methods;
}

/**
 * Доставляем только по Новосибирску. Пустой город (гость ещё не ввёл адрес) считаем своим:
 * самовывоз не требует адреса, и чекаут не должен оставаться без вариантов.
 */
function dpd_is_local_destination($package) {
    $city = isset($package['destination']['city']) ? trim((string) $package['destination']['city']) : '';
    $postcode = isset($package['destination']['postcode']) ? trim((string) $package['destination']['postcode']) : '';
    if ($city === '' && $postcode === '') { return true; }
    if ($city !== '' && preg_match('/Новосибирск/iu', $city)) { return true; }
    return (bool) preg_match('/^630\d{3}$/', $postcode);
}

/**
 * Замок на самовывоз: если сторонний фильтр срезал ВСЕ тарифы в Новосибирске,
 * возвращаем самовывоз из настроек зоны. Вне Новосибирска вариантов не добавляем.
 */
add_filter('woocommerce_package_rates', 'dpd_ensure_pickup_rate', 999, 2);
function dpd_ensure_pickup_rate($rates, $package) {
    if (!empty($rates)) { return $rates; }
    if (!class_exists('WC_Shipping_Rate') || !class_exists('WC_Shipping_Zones')) { return $rates; }
    if (!dpd_is_local_destination($package)) { return $rates; }

    $zone = WC_Shipping_Zones::get_zone_matching_package($package);
    if (!$zone) { return $rates; }

    foreach ($zone->get_shipping_methods(true) as $method) {
        if ($method->id !== 'local_pickup' || $method->enabled !== 'yes') { continue; }
        $rate = new WC_Shipping_Rate(
            $method->get_rate_id(),
            $method->get_title(),
            0,
            array(),
            $method->id,
            $method->instance_id
        );
        $rates[$rate->get_id()] = $rate;
    }
    return $rates;
}
