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
            );
        }

        public function is_available($package = array()) {
            if ($this->enabled !== 'yes') { return false; }
            return parent::is_available($package);
        }

        /** Проверка города без mbstring: PCRE с флагом u. */
        public function dpd_city_allowed($city) {
            $wanted = trim((string) $this->get_option('city', 'Новосибирск'));
            if ($wanted === '') { return true; }
            $city = trim((string) $city);
            if ($city === '') { return false; }
            $pattern = '/' . preg_quote($wanted, '/') . '/iu';
            return (bool) preg_match($pattern, $city);
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
            if (!$this->dpd_city_allowed($city)) { return; }

            $cost = (float) $this->get_option('cost', 145);
            $free_from = (float) $this->get_option('free_from', 4000);
            $subtotal = $this->dpd_cart_subtotal($package);
            if ($free_from > 0 && $subtotal >= $free_from) { $cost = 0.0; }

            $this->add_rate(array(
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => $cost,
                'package' => $package,
                'meta_data' => array('dpd_subtotal' => $subtotal),
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
