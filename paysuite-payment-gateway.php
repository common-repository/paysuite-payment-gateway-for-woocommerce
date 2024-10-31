<?php
/*
Plugin Name: Paysuite Payment Gateway for WooCommerce
Plugin URI: https://wordpress.org/plugins/paysuite-payment-gateway-for-woocommerce/
Description: Receive payments directly to your store through the M-Pesa and Emola, .
Version: 1.2.3
WC requires at least: 4.0.0
WC tested up to: 8.4.0
Author: Paysuite <suporte@paysuite.co.mz>
Author URI: http://paysuite.co.mz

    Copyright: © 2023 PaySuite <suporte@paysuite.co.mz>.
    License: GNU General Public License v3.0
    License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/


require plugin_dir_path(__FILE__) . '/vendor/autoload.php';
add_action('plugins_loaded', 'paysuite_init', 0);
function paysuite_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    /**
     * Localisation
     */
    load_plugin_textdomain('paysuite-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');


    /**
     * Gateway class
     */
    class WC_Gateway_PaySuite extends WC_Payment_Gateway
    {
        public string $secret_key;
        public string $test;
        public function __construct()
        {
            $this->id                 = 'paysuite-payment-gateway';
            $this->icon               = apply_filters('paysuite_icon', plugins_url('assets/img/logo.png', __FILE__));
            $this->has_fields         = false;
            $this->method_title       = __('PaySuite for WooCommerce', 'paysuite-payment-gateway');
            $this->method_description = __('Accept Mpesa and Emola Payments for WooCommerce', 'paysuite-payment-gateway');

            // Load the settings.
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title         = $this->get_option('title');
            $this->description   = $this->get_option('description');
            $this->secret_key = $this->get_option('secret_key');
            $this->test = $this->get_option('test');
            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_paysuite_process_action', array($this, 'paysuite_process_action'));
        }





        /**
         * Create form fields for the payment gateway
         *
         * @return void
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'paysuite-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable PaySuite payment gateway', 'paysuite-payment-gateway'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'paysuite-payment-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout', 'paysuite-payment-gateway'),
                    'default' => __('PaySuite for WooCommerce', 'paysuite-payment-gateway'),
                    'desc_tip'      => true,
                ),
                'description' => array(
                    'title' => __('Customer Message', 'paysuite-payment-gateway'),
                    'type' => 'textarea',
                    'default' => __('Pay Via PaySuite', 'paysuite-payment-gateway')
                ),
                'secret_key' => array(
                    'title' => __('Secret Key', 'paysuite-payment-gateway'),
                    'type' => 'password',
                    'default' => __('', 'paysuite-payment-gateway')
                ),
                'test' => array(
                    'title' => __('Test Mode', 'paysuite-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Test Mode', 'paysuite-payment-gateway'),
                    'default' => 'yes',
                ),

            );
        }

        public function payment_fields()
        {
            if ($this->description) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ('yes' == $this->test) {
                    $this->description .= __('<br/> TEST MODE ENABLED.', 'paysuite-payment-gateway');
                    $this->description  = trim($this->description);
                }
                // display the description with <p> tags etc.
                echo wpautop(wp_kses_post($this->description));
            }

        }

        public function validate_fields()
        {
            if ('MZN' != get_woocommerce_currency()) {
                wc_add_notice(__('Currency not supported!', 'paysuite-payment-gateway'), 'error');
                return false;
            }
            return true;
        }


        /**
         * Process the order payment status
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {


            $order = wc_get_order($order_id);
            $tx_ref = $this->paysuite_generate_reference();
            $order->update_meta_data('tx_ref', $tx_ref);
            $callback_url = add_query_arg('wc-api', 'paysuite_process_action', home_url('/'));
            $paysuite = new Hypertech\Paysuite\Client($this->secret_key);

            if ('yes' == $this->test) {
                $paysuite->enableTestMode();
            }
            $result = $paysuite->checkout([
                "tx_ref" => $tx_ref,
                "currency" => "MZN",
                "purpose" => "Pagamento do pedido: {$order->get_order_number()}",
                "amount" => $order->get_total(),
                "callback_url" => $callback_url,
                "redirect_url" => $order->get_checkout_order_received_url()
            ]);

            if ($result->isSuccessfully()) {
                $order->update_status('on-hold', __('Awaiting for payment', 'paysuite-payment-gateway'));
                return array(
                    'result'    => 'success',
                    'redirect'  => str_replace('http:', 'https:', $result->getCheckoutUrl()));
            } else {
                wc_add_notice(__('Payment error:', 'paysuite-payment-gateway') . $result->getMessage(), 'error');
            }
            return;
        }

        public function paysuite_process_action()
        {
            $response['status'] = 'failed';
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            if ($data && $data['status'] == 'success') {
                $order_query = wc_get_orders(array(
                    'limit' => 1,
                    'meta_key' => 'tx_ref',
                    'meta_value' => $data['tx_ref'],
                    'meta_compare' => '='
                ));
                if (count($order_query) > 0) {
                    $order = $order_query[0];
                    if ($order->get_status() == 'on-hold') {
                        $order->payment_complete($data['transaction_id']);
                        $order->add_order_note('Your order is paid! Thank you!', true);
                        WC()->cart->empty_cart();
                        $response['status'] = 'success';
                    }
                }
            }
            wp_send_json(['status' => $response['status']]);
        }

        private function paysuite_generate_reference()
        {
            return bin2hex(random_bytes(10));
        }

    } //END  WC_Gateway_PaySuite

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_gateway_paysuite_gateway($methods)
    {
        $methods[] = 'WC_Gateway_PaySuite';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_paysuite_gateway');
}

/**
 * Handle a custom 'tx_ref' query var to get orders with the 'tx_ref' meta.
 * @param array $query - Args for WP_Query.
 * @param array $query_vars - Query vars from WC_Order_Query.
 * @return array modified $query
 */

add_filter('woocommerce_order_data_store_cpt_get_orders_query', function ($query, $query_vars) {
    if (!empty($query_vars['tx_ref'])) {
        $query['meta_query'][] = array(
            'key' => 'tx_ref',
            'value' => esc_attr($query_vars['tx_ref']),
        );
    }

    return $query;
}, 10, 2);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
