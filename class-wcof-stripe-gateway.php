<?php
if (!defined('ABSPATH')) exit;

class WCOF_Stripe_Gateway extends WC_Payment_Gateway {
    public function __construct(){
        $this->id = 'stripe_wcof';
        $this->method_title = __('Stripe (Manual Capture)', 'wc-order-flow');
        $this->method_description = __('Authorize card payments via Stripe and capture after admin approval.', 'wc-order-flow');
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title   = $this->get_option('title');

        add_action('wp_enqueue_scripts', [$this,'payment_scripts']);
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this,'process_admin_options']);
    }

    public function init_form_fields(){
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'wc-order-flow'),
                'type' => 'checkbox',
                'label' => __('Enable Stripe payment', 'wc-order-flow'),
                'default' => 'no'
            ],
            'title' => [
                'title' => __('Title', 'wc-order-flow'),
                'type' => 'text',
                'default' => __('Credit Card', 'wc-order-flow')
            ],
        ];
    }

    public function payment_fields(){
        echo '<div id="wcof-stripe-card-element"></div>';
        echo '<input type="hidden" name="wcof_stripe_pm" id="wcof_stripe_pm" />';
    }

    public function payment_scripts(){
        if (!is_checkout() || !$this->is_available()) return;
        $settings = get_option(WCOF_Plugin::OPTION_KEY, []);
        $pk = $settings['stripe_pk'] ?? '';
        if (!$pk) return;
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/');
        wp_enqueue_script('wcof-stripe-gateway', plugins_url('assets/stripe-gateway.js', __FILE__), ['jquery', 'stripe-js'], '1.0', true);
        wp_localize_script('wcof-stripe-gateway', 'wcofStripeGateway', [
            'pk' => $pk,
            'gateway' => $this->id,
        ]);
    }

    public function process_payment($order_id){
        $order = wc_get_order($order_id);
        $settings = get_option(WCOF_Plugin::OPTION_KEY, []);
        $secret = $settings['stripe_sk'] ?? '';
        $pm = isset($_POST['wcof_stripe_pm']) ? sanitize_text_field($_POST['wcof_stripe_pm']) : '';
        if (!$secret || !$pm) {
            wc_add_notice(__('Payment error, please try again.', 'wc-order-flow'), 'error');
            return ['result' => 'fail'];
        }
        $body = [
            'amount' => round($order->get_total() * 100),
            'currency' => strtolower($order->get_currency()),
            'payment_method' => $pm,
            'confirmation_method' => 'automatic',
            'confirm' => 'true',
            'capture_method' => 'manual',
            'description' => 'Order '.$order->get_order_number(),
        ];
        $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer '.$secret],
            'body' => $body,
            'timeout' => 45,
        ]);
        if (is_wp_error($resp)) {
            wc_add_notice(__('Connection error.', 'wc-order-flow'), 'error');
            return ['result' => 'fail'];
        }
        $res = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($res['error'])) {
            wc_add_notice($res['error']['message'], 'error');
            return ['result' => 'fail'];
        }
        $intent_id = $res['id'];
        $order->update_meta_data('_stripe_intent_id', $intent_id);
        $order->save();
        WC()->cart->empty_cart();
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        ];
    }
}
