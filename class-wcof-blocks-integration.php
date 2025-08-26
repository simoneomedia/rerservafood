<?php
class WCOF_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
    }

    public function get_name() {
        return 'wcof-checkout-address';
    }

    public function initialize() {}

    public function get_script_handles() {
        wp_register_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_register_script('wcof-checkout-address', plugins_url('assets/checkout-address.js', __FILE__), ['leaflet'], '1.0', true);
        return ['wcof-checkout-address'];
    }

    public function get_editor_script_handles() { return []; }

    public function get_style_handles() {
        wp_register_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        return ['leaflet'];
    }

    public function get_editor_style_handles() { return []; }

    public function enqueue_assets() {
        $codes = $this->plugin->delivery_postal_codes();
        wp_localize_script('wcof-checkout-address', 'wcofCheckoutAddress', [
            'postalCodes' => $codes,
        ]);
    }
}
