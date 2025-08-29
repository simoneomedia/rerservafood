<?php
/**
 * Plugin Name: Reeservafood
 * Description: App‑style order approvals for WooCommerce with ETA, “Rider on the way”, live order board, and integrated OneSignal Web Push (no extra plugin). Mobile‑first UI.
 * Author: Reeserva
 * Version: 1.9.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: wc-order-flow
 */

if (!defined('ABSPATH')) exit;

final class WCOF_Plugin {
    const OPTION_KEY = 'wcof_settings';
    const META_ETA   = '_wcof_eta_minutes';
    const META_ARRIVAL = '_wcof_arrival_ts';
    const META_DECIDED = '_wcof_decided';
    const META_LOCK    = '_wcof_lock';
    const STATUS_AWAITING = 'wc-on-hold';
    const STATUS_OUT_FOR_DELIVERY = 'wc-out-for-delivery';

    public static function activate(){
        $self = new self();
        $self->register_sw_rewrite();
        if( !get_option('wcof_setup_done') ) add_option('wcof_setup_done', 0);
        add_role('rider', 'Rider', ['read'=>true,'wcof_rider'=>true]);
        flush_rewrite_rules();

        // Switch checkout page to classic shortcode
        if( function_exists('wc_get_page_id') ){
            $checkout_id = wc_get_page_id('checkout');
            if( $checkout_id && $checkout_id > 0 ){
                $shortcode = '[woocommerce_checkout]';
                $content = get_post_field('post_content', $checkout_id);
                if( strpos($content, $shortcode) === false ){
                    wp_update_post([
                        'ID' => $checkout_id,
                        'post_content' => $shortcode,
                    ]);
                }
            }
        }

        // Disable block based cart & checkout setting
        update_option('woocommerce_enable_cart_checkout_blocks', 'no');
        update_option('woocommerce_blocks_cart_checkout_enabled', 'no');
        update_option('woocommerce_cart_checkout_blocks_enabled', 'no');
    }
    public static function deactivate(){
        remove_role('rider');
        flush_rewrite_rules();
    }

    public function __construct() {
        add_action('init', [$this,'register_statuses']);
        add_filter('wc_order_statuses', [$this,'add_statuses_to_list']);

        // Force classic cart and checkout templates
        add_filter('woocommerce_checkout_is_blocks_enabled', '__return_false');
        add_filter('woocommerce_cart_is_blocks_enabled', '__return_false');

        // Force new orders to awaiting approval
        add_action('woocommerce_checkout_order_processed', [$this,'set_order_awaiting'], 99, 3);
        add_action('woocommerce_new_order',               [$this,'force_awaiting_on_create'], 9999, 1);
        add_filter('woocommerce_payment_complete_order_status', [$this,'force_awaiting_on_payment_complete'], 9999, 3);
        add_action('woocommerce_order_status_changed',    [$this,'undo_auto_approval'], 9999, 4);
        // Prevent automatic capture on supported gateways
        add_filter('wcpay_should_use_manual_capture', [$this,'maybe_defer_wcpay_capture'], 10, 2);
        add_filter('woocommerce_paypal_payments_order_intent', [$this,'maybe_defer_paypal_capture'], 10, 2);
        add_filter('woocommerce_paypal_args', [$this,'maybe_defer_paypal_capture'], 10, 2);

        // Metabox + admin actions
        add_action('add_meta_boxes', [$this,'add_metabox']);
        add_action('admin_post_wcof_approve', [$this,'handle_approve']);
        add_action('admin_post_wcof_reject',  [$this,'handle_reject']);
        add_action('admin_post_wcof_set_eta', [$this,'handle_set_eta']);
        add_action('admin_post_wcof_out_for_delivery', [$this,'handle_out_for_delivery']);
        add_action('admin_post_wcof_complete', [$this,'handle_complete']);

        // Thank you page hero (live)
        add_action('woocommerce_before_thankyou', [$this,'thankyou_hero'], 5);

        // Orders board (front)
        add_shortcode('wcof_orders_admin',   [$this,'shortcode_orders_admin']);

        // Product manager (front)
        add_shortcode('wcof_product_manager', [$this,'shortcode_product_manager']);

        // Store settings + rider management (front)
        add_shortcode('wcof_store_settings', [$this,'shortcode_store_settings']);
        add_action('admin_post_wcof_save_store', [$this,'handle_save_store']);
        add_action('admin_post_wcof_add_rider', [$this,'handle_add_rider']);

        // Public menu shortcode
        add_shortcode('castle_menu', [$this,'shortcode_castle_menu']);

        // REST
        add_action('rest_api_init', [$this,'register_rest_routes']);

        // Settings + OneSignal
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('admin_init', [$this,'maybe_redirect_setup']);
        add_action('admin_post_wcof_finish_setup', [$this,'handle_finish_setup']);
        add_action('wp_enqueue_scripts', [$this,'maybe_inject_onesignal_sdk']);
        add_action('wp_enqueue_scripts', [$this,'enqueue_checkout_scripts']);

        add_action('woocommerce_checkout_before_customer_details', [$this,'render_checkout_address'], 5);
        add_action('woocommerce_checkout_process', [$this,'validate_checkout_address']);
        add_action('woocommerce_checkout_update_order_meta', [$this,'save_delivery_address']);
        add_filter('woocommerce_checkout_fields', [$this,'hide_billing_fields'], 999);

        add_action('woocommerce_admin_order_data_after_billing_address', [$this,'display_delivery_address_admin']);
        add_action('woocommerce_order_details_after_customer_details',   [$this,'display_delivery_address_front']);
        add_filter('woocommerce_email_order_meta_fields',               [$this,'email_delivery_address_meta'], 10, 3);


        add_action('woocommerce_new_order',                         [$this,'push_new_order'], 20);
        add_action('woocommerce_order_status_processing',           [$this,'push_approved'], 20);
        add_action('woocommerce_order_status_out-for-delivery',     [$this,'push_out_for_delivery'], 20);

        // Service worker at root via rewrites
        add_action('init', [$this,'register_sw_rewrite']);
        add_filter('query_vars', [$this,'add_query_vars']);
        add_action('template_redirect', [$this,'maybe_serve_sw']);
        add_filter('redirect_canonical', [$this,'prevent_sw_canonical'], 10, 2);

        // Push shortcodes (button + debug)
        add_shortcode('wcof_push_button', [$this,'shortcode_push_button']);
        add_shortcode('wcof_push_debug',  [$this,'shortcode_push_debug']);
    }

    /* ===== Service workers via rewrite to site root ===== */
    public function register_sw_rewrite(){
        add_rewrite_rule('^OneSignalSDKWorker\.js$', 'index.php?wcof_sw=worker', 'top');
        add_rewrite_rule('^OneSignalSDKUpdaterWorker\.js$', 'index.php?wcof_sw=updater', 'top');
    }
    public function add_query_vars($vars){ $vars[]='wcof_sw'; return $vars; }
    public function maybe_serve_sw(){
        $which = get_query_var('wcof_sw');
        if(!$which) return;
        http_response_code(200);
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        echo "importScripts('https://cdn.onesignal.com/sdks/OneSignalSDKWorker.js');\n";
        exit;
    }

    /* Avoid any 301/302 on SW files — redirects break registration */
    public function prevent_sw_canonical($redirect_url, $requested){
        if (isset($_GET['wcof_sw'])) return false;
        $path = wp_parse_url($requested, PHP_URL_PATH);
        if ($path === '/OneSignalSDKWorker.js' || $path === '/OneSignalSDKUpdaterWorker.js') return false;
        return $redirect_url;
    }

    /* ===== Status registration ===== */
    public function register_statuses(){
        register_post_status(self::STATUS_OUT_FOR_DELIVERY, [
            'label' => 'In consegna',
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('In consegna <span class="count">(%s)</span>','In consegna <span class="count">(%s)</span>')
        ]);
    }
    public function add_statuses_to_list($s){
        $n=[];
        foreach($s as $k=>$v){
            $n[$k] = ($k==='wc-on-hold') ? 'In attesa di approvazione' : $v;
        }
        $n['wc-cancelled']='Rifiutato';
        $n[self::STATUS_OUT_FOR_DELIVERY]='In consegna';
        return $n;
    }

    private function status_name($status){
        switch($status){
            case self::STATUS_AWAITING: return 'Waiting for approval';
            case 'wc-processing': return 'Preparing';
            case self::STATUS_OUT_FOR_DELIVERY: return 'Delivering';
            case 'wc-completed': return 'Completed';
            case 'wc-cancelled': return 'Canceled';
        }
        return $status;
    }

    /* ===== Force awaiting approval on creation ===== */
    public function set_order_awaiting($order_id, $posted_data, $order){
        if(!$order instanceof WC_Order) $order = wc_get_order($order_id);
        if(!$order) return;
        $order->update_status(str_replace('wc-','', self::STATUS_AWAITING), 'Ordine in attesa di approvazione.');
    }
    public function force_awaiting_on_create($order_id){
        $o = wc_get_order($order_id); if(!$o) return;
        if($o->get_meta(self::META_DECIDED)) return;
        if('wc-'.$o->get_status() !== self::STATUS_AWAITING){
            $o->update_status(str_replace('wc-','', self::STATUS_AWAITING),'Forzato: in attesa di approvazione.');
        }
    }
    public function force_awaiting_on_payment_complete($status, $order_id, $order){
        if(!$order) $order = wc_get_order($order_id);
        if($order && !$order->get_meta(self::META_DECIDED)) return 'on-hold';
        return $status;
    }
    public function undo_auto_approval($order_id, $old_status, $new_status, $order){
        if(!$order instanceof WC_Order) $order = wc_get_order($order_id);
        if(!$order || $order->get_meta(self::META_DECIDED)) return;
        if(in_array($new_status, ['processing','completed'], true)){
            $order->update_status(str_replace('wc-','', self::STATUS_AWAITING),'Forzato: in attesa di approvazione.');
        }
    }

    public function maybe_defer_wcpay_capture($manual, $order){
        if(!$order instanceof WC_Order) return $manual;
        if($order->get_meta(self::META_DECIDED)) return $manual;
        return true;
    }

    public function maybe_defer_paypal_capture($arg, $order){
        if(!$order instanceof WC_Order || $order->get_meta(self::META_DECIDED)) return $arg;
        if(is_array($arg)){
            $arg['paymentaction'] = 'authorization';
            return $arg;
        }
        return 'AUTHORIZE';
    }


    /* ===== Metabox ===== */
    public function add_metabox(){
        add_meta_box('wcof_metabox','Stato ordine (app style)',[$this,'render_metabox'],'shop_order','side','high');
    }
    public function render_metabox($post){
        $o = wc_get_order($post->ID); if(!$o) return;
        $eta = (int)$o->get_meta(self::META_ETA);
        $status = 'wc-'.$o->get_status();
        $approve = wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$post->ID),'wcof_approve_'.$post->ID);
        $reject  = wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$post->ID), 'wcof_reject_'.$post->ID);
        $seteta  = wp_nonce_url(admin_url('admin-post.php?action=wcof_set_eta&order_id='.$post->ID), 'wcof_set_eta_'.$post->ID);
        $outurl  = wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$post->ID), 'wcof_out_for_delivery_'.$post->ID);
        ?>
        <p><label for="wcof_eta"><strong>Tempo di attesa (minuti)</strong></label></p>
        <p><input type="number" min="0" step="1" id="wcof_eta" value="<?php echo esc_attr($eta?:15); ?>" style="width:100%"></p>
        <?php if($status===self::STATUS_AWAITING): ?>
        <p>
          <a class="button button-primary" href="<?php echo esc_url($approve); ?>" onclick="event.preventDefault();wcofSubmit(this);">Approva</a>
          <a class="button" href="<?php echo esc_url($reject); ?>" style="margin-left:6px" onclick="event.preventDefault();wcofSubmit(this);">Rifiuta</a>
        </p>
        <?php elseif($status==='wc-processing'): ?>
        <p>
          <a class="button button-primary" href="<?php echo esc_url($seteta); ?>" onclick="event.preventDefault();wcofSubmit(this);">Aggiorna ETA</a>
        </p>
        <?php endif; ?>
        <?php if($status!=='wc-out-for-delivery' && $status!=='wc-completed'): ?>
        <p><a class="button button-secondary" href="<?php echo esc_url($outurl); ?>" onclick="return confirm('Segnare come In consegna?');">Rider in consegna</a></p>
        <?php endif; ?>
        <script>
          function wcofSubmit(el){
            var f=document.createElement('form'); f.method='POST'; f.action=el.getAttribute('href');
            var i=document.createElement('input'); i.type='hidden'; i.name='eta'; i.value=document.getElementById('wcof_eta').value||0; f.appendChild(i);
            document.body.appendChild(f); f.submit();
          }
        </script>
        <?php
    }
    public function handle_approve(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_approve_'.$order_id);
        $o = wc_get_order($order_id);
        if($o){
            $eta = isset($_POST['eta']) ? absint($_POST['eta']) : 0;
            $arrival = current_time('timestamp') + $eta * 60;
            $o->update_meta_data(self::META_ETA, $eta);
            $o->update_meta_data(self::META_ARRIVAL, $arrival);
            $o->update_meta_data(self::META_DECIDED, 1);
            $o->save();
            $prev = $o->get_status();
            if(!$o->is_paid()){
                $gateway = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order($o) : null;
                if($gateway){
                    try{
                        if(method_exists($gateway,'capture_charge')){
                            $gateway->capture_charge($o);
                            $o->payment_complete($o->get_transaction_id());
                        }elseif(method_exists($gateway,'capture_payment')){
                            $gateway->capture_payment($o);
                            $o->payment_complete($o->get_transaction_id());
                        }elseif(method_exists($gateway,'process_capture')){
                            $gateway->process_capture($o);
                            $o->payment_complete($o->get_transaction_id());
                        }
                    }catch(\Exception $e){
                        $o->add_order_note($gateway->id.' capture failed: '.$e->getMessage());
                    }
                }
            }
            $o->update_status('processing', sprintf('Ordine approvato. ETA: %d minuti.',$eta));
            WC()->mailer();
            do_action("woocommerce_order_status_{$prev}_to_processing_notification", $order_id);
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }
    public function handle_reject(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_reject_'.$order_id);
        $o = wc_get_order($order_id);
        if($o){
            $gateway = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order($o) : null;
            if($gateway){
                try{
                    if(method_exists($gateway,'cancel_payment')){
                        $gateway->cancel_payment($o);
                    }elseif(method_exists($gateway,'void_payment')){
                        $gateway->void_payment($o);
                    }elseif(method_exists($gateway,'cancel_authorization')){
                        $gateway->cancel_authorization($o);
                    }elseif(method_exists($gateway,'void_charge')){
                        $gateway->void_charge($o);
                    }elseif(method_exists($gateway,'void_transaction')){
                        $gateway->void_transaction($o);
                    }elseif(method_exists($gateway,'cancel_charge')){
                        $gateway->cancel_charge($o);
                    }
                }catch(\Exception $e){
                    $o->add_order_note($gateway->id.' cancel failed: '.$e->getMessage());
                }
            }
            $o->update_status('cancelled','Ordine rifiutato dall’amministratore.');
            $o->update_meta_data(self::META_DECIDED,1);
            $o->save();
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }
    public function handle_set_eta(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_set_eta_'.$order_id);
        $o = wc_get_order($order_id);
        if($o && $o->has_status([ str_replace('wc-','', self::STATUS_AWAITING), 'processing' ])){
            $eta = isset($_POST['eta']) ? absint($_POST['eta']) : 0;
            $arrival = current_time('timestamp') + $eta * 60;
            $o->update_meta_data(self::META_ETA, $eta);
            $o->update_meta_data(self::META_ARRIVAL, $arrival);
            $o->save();
            $o->add_order_note(sprintf('ETA aggiornata a %d minuti.', $eta));
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }
    public function handle_out_for_delivery(){
        if(!(current_user_can('manage_woocommerce') || current_user_can('wcof_rider'))) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_out_for_delivery_'.$order_id);
        $o = wc_get_order($order_id);
        if($o){
            if(current_user_can('wcof_rider')){
                $set=$this->settings();
                if(empty($set['rider_see_processing']) && $o->has_status('processing')) wp_die('Non autorizzato');
            }
            $o->update_status(str_replace('wc-','', self::STATUS_OUT_FOR_DELIVERY),'Rider in consegna.');
            $o->update_meta_data(self::META_DECIDED,1);
            $o->save();
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }

    public function handle_complete(){
        if(!(current_user_can('manage_woocommerce') || current_user_can('wcof_rider'))) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_complete_'.$order_id);
        $o = wc_get_order($order_id);
        if($o){
            if(current_user_can('wcof_rider')){
                $set=$this->settings();
                if(empty($set['rider_see_processing']) && $o->has_status('processing')) wp_die('Non autorizzato');
            }
            $o->update_status('completed','Consegna completata.');
            $o->save();
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }

    /* ===== REST ===== */
    public function register_rest_routes(){
        register_rest_route('wcof/v1','/orders', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('manage_woocommerce') || current_user_can('wcof_rider'); },
            'callback' => function($req){
                $limit = min(200, max(1, intval($req->get_param('limit') ?: 40)));
                $after = intval($req->get_param('after_id') ?: 0);
                $allowed = ['pending','processing','completed','on-hold','cancelled','refunded','failed','out-for-delivery'];
                if(!current_user_can('manage_woocommerce')){
                    $set=$this->settings();
                    $allowed = !empty($set['rider_see_processing'])
                        ? ['processing','out-for-delivery','completed']
                        : ['out-for-delivery','completed'];
                }
                $orders = wc_get_orders([
                    'limit'=>$limit, 'orderby'=>'date','order'=>'DESC',
                    'type'=>'shop_order','return'=>'objects','status'=>$allowed
                ]);
                $latest_id=0; $out=[];
                foreach($orders as $o){
                    if(!$o instanceof WC_Order){ $o = wc_get_order($o); if(!$o) continue; }
                    $status_slug = $o->get_status();
                    if($status_slug === 'checkout-draft') continue;
                    $id=$o->get_id(); if($id>$latest_id) $latest_id=$id;
                    if($after && $id <= $after) continue;
                    $eta=(int)$o->get_meta(self::META_ETA);
                    $arrival_ts=(int)$o->get_meta(self::META_ARRIVAL);
                    $arrival=$arrival_ts?date_i18n('H:i',$arrival_ts):null;
                    $items=[]; foreach($o->get_items() as $it){ $items[]=['name'=>$it->get_name(),'qty'=>(int)$it->get_quantity()]; }
                    $total_raw = html_entity_decode( wp_strip_all_tags($o->get_formatted_order_total()), ENT_QUOTES, 'UTF-8' );
                    $resolved = get_post_meta($o->get_id(), '_wcof_delivery_resolved', true);
                    $typed    = get_post_meta($o->get_id(), '_wcof_delivery_address', true);
                    $coords   = get_post_meta($o->get_id(), '_wcof_delivery_coords', true);
                    $address  = $resolved;
                    if(!$address){
                        $address = trim(implode(', ', array_filter([
                            $o->get_shipping_address_1(), $o->get_shipping_address_2(),
                            trim($o->get_shipping_postcode().' '.$o->get_shipping_city())
                        ])));
                        if(!$address){
                            $address = trim(implode(', ', array_filter([
                                $o->get_billing_address_1(), $o->get_billing_address_2(),
                                trim($o->get_billing_postcode().' '.$o->get_billing_city())
                            ])));
                        }
                    }
                    $phone = $o->get_billing_phone();
                    $note  = $o->get_customer_note();
                    $payment = '';
                    if($o->get_payment_method() === 'cod'){
                        $payment = 'CASH ON DELIVERY!';
                    } elseif($o->is_paid()){
                        $payment = 'already paid online';
                    }
                    $meta  = [];
                    foreach ( $o->get_meta_data() as $md ) {
                        $data = $md->get_data();
                        $k = isset( $data['key'] ) ? $data['key'] : '';
                        if ( $k === '' ) {
                            continue;
                        }
                        $v = isset( $data['value'] ) ? $data['value'] : '';
                        if ( is_array( $v ) || is_object( $v ) ) {
                            $v = wp_json_encode( $v );
                        }
                        $meta[ $k ] = (string) $v;
                    }
                    $out[]=[
                        'id'=>$id,'number'=>$o->get_order_number(),
                        'status'=>'wc-'.$status_slug,'status_name'=>$this->status_name('wc-'.$status_slug),'eta'=>$eta,
                        'arrival'=>$arrival,
                        'total'=>$total_raw,
                        'customer'=>trim($o->get_formatted_billing_full_name()) ?: $o->get_billing_email(),
                        'items'=>$items,
                        'approve_url'=>wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$id),'wcof_approve_'.$id),
                        'reject_url' =>wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$id),'wcof_reject_'.$id),
                        'set_eta_url'=>wp_nonce_url(admin_url('admin-post.php?action=wcof_set_eta&order_id='.$id),'wcof_set_eta_'.$id),
                        'out_url'   =>wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$id),'wcof_out_for_delivery_'.$id),
                        'complete_url'=>wp_nonce_url(admin_url('admin-post.php?action=wcof_complete&order_id='.$id),'wcof_complete_'.$id),
                        'address'=>$address,
                        'address_typed'=>$typed,
                        'coords'=>$coords,
                        'phone'=>$phone,
                        'note'=>$note,
                        'payment'=>$payment,
                        'meta'=>$meta,
                    ];
                }
                return ['latest_id'=>$latest_id,'orders'=>$out];
            }
        ]);
        register_rest_route('wcof/v1','/order/(?P<id>\d+)', [
            'methods'=>'GET','permission_callback'=>'__return_true',
            'callback'=>function($req){
                $id=absint($req['id']); $o=wc_get_order($id);
                if(!$o) return new WP_Error('not_found','Ordine non trovato',['status'=>404]);
                $ok=false; $uid=get_current_user_id(); $owner=(int)$o->get_user_id();
                if(current_user_can('manage_woocommerce') || ($uid && $owner===$uid)) $ok=true;
                else { $k=isset($_GET['k'])?sanitize_text_field($_GET['k']):''; if($k && hash_equals($o->get_order_key(),$k)) $ok=true; }
                if(!$ok) return new WP_Error('forbidden','Non autorizzato',['status'=>403]);
                $eta=(int)$o->get_meta(self::META_ETA);
                $arrival_ts=(int)$o->get_meta(self::META_ARRIVAL);
                $arrival=$arrival_ts?date_i18n('H:i',$arrival_ts):null;
                return ['status'=>'wc-'.$o->get_status(),'status_name'=>$this->status_name('wc-'.$o->get_status()),'eta'=>$eta,'arrival'=>$arrival];
            }
        ]);
    }

    /* ===== Thank you page hero ===== */
    public function thankyou_hero($order_id){
        if(!$order_id) return; $o=wc_get_order($order_id); if(!$o) return; $key=$o->get_order_key();
        ?>
        <style>
          .wcof-sticky{position:relative;margin:12px 0}
          @media (min-width:700px){ .wcof-sticky{position:sticky; top:10px; z-index:5;} }
          .wcof-hero{display:flex;align-items:center;gap:16px;padding:16px;border:1px solid #e7e7e9;border-radius:14px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.05)}
          .wcof-spinner{width:64px;height:64px;border:6px solid #efeff3;border-top-color:#111;border-radius:50%;animation:wcof-spin 1s linear infinite}
          .wcof-icon{display:none;width:64px;height:64px}
          .wcof-title{font-size:18px;margin:0;color:#0f172a}
          .wcof-sub{margin:2px 0 0;color:#475569}
          .wcof-progress{height:6px;background:#eef2ff;border-radius:999px;overflow:hidden;margin-top:10px}
          .wcof-bar{height:100%;width:18%;background:linear-gradient(90deg,#2563eb,#22c55e);transition:width .4s ease}
          @keyframes wcof-spin{to{transform:rotate(360deg)}}
          @keyframes wcof-pop{0%{transform:scale(.6);opacity:0}60%{transform:scale(1.08)}100%{transform:scale(1);opacity:1}}
          .wcof-hide{display:none!important}
          .wcof-chip{display:inline-block;background:#eef2ff;color:#1e293b;border-radius:999px;padding:.2rem .55rem;font-size:12px;margin-left:6px}
          @media (max-width:640px){ .wcof-hero{padding:14px;border-radius:12px} .wcof-spinner, .wcof-icon{width:52px;height:52px} .wcof-title{font-size:16px} .wcof-sub{font-size:14px} }
          .woocommerce-order-overview, .woocommerce-order-details, .woocommerce-customer-details{display:none}
        </style>
        <div class="wcof-sticky">
          <div class="wcof-hero">
            <div class="wcof-spinner" id="wcof-spinner" aria-label="Esperando confirmación"></div>
            <svg id="wcof-icon" class="wcof-icon" viewBox="0 0 64 64" aria-hidden="true">
              <rect x="12" y="24" width="40" height="28" rx="2" fill="#FFD166" stroke="#333" stroke-width="2"></rect>
              <rect x="18" y="18" width="6" height="6" fill="#333"></rect>
              <rect x="40" y="18" width="6" height="6" fill="#333"></rect>
              <rect x="30" y="32" width="4" height="8" fill="#333"></rect>
              <circle cx="24" cy="40" r="2" fill="#333"></circle>
              <circle cx="40" cy="40" r="2" fill="#333"></circle>
              <ellipse cx="54" cy="46" rx="6" ry="2.5" fill="#fff" stroke="#333" stroke-width="1.5"></ellipse>
              <path d="M50 45c2-5 6-5 8 0" stroke="#333" stroke-width="1.5" fill="none"></path>
              <path d="M22 46q10 6 20 0" stroke="#E76F51" stroke-width="2" fill="none"></path>
            </svg>
            <div style="flex:1">
              <p class="wcof-title" id="wcof-title"><strong>Gracias!</strong> Estamos esperando la confirmación del administrador… <span class="wcof-chip">Nuevo pedido</span></p>
              <p class="wcof-sub" id="wcof-status">Aguardando aprobación.</p>
              <div class="wcof-progress"><div class="wcof-bar" id="wcof-bar"></div></div>
            </div>
          </div>
        </div>
        <script>
          (function(){
            var orderId=<?php echo (int)$order_id; ?>, k='<?php echo esc_js($key); ?>';
            var tries=0, bar=document.getElementById('wcof-bar');
            function setBar(p){ if(bar){ bar.style.width = Math.max(12, Math.min(100, p)) + '%'; } }
            function showConfirmed(eta, arrival){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='block'; ic.style.animation='wcof-pop .5s ease-out';}
              if(t){ t.innerHTML='<strong>Pedido confirmado.</strong> En preparación. <span class="wcof-chip">Llegada aprox. '+arrival+'</span>'; }
              if(s){ s.textContent='Tiempo estimado: '+(eta||'?')+' min'; }
              setBar(60);
            }
            function showOut(arrival){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='block';}
              if(t){ t.innerHTML='<strong>Rider en camino.</strong> <span class="wcof-chip">Llegada '+arrival+'</span>'; }
              if(s){ s.textContent='Puedes seguir el estado aquí.'; }
              setBar(90);
            }
            function showRejected(){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='none';}
              if(t){ t.innerHTML='<strong>Pedido rechazado.</strong>'; }
              if(s){ s.textContent='Lo sentimos, pedido rechazado.'; }
              setBar(100);
            }
            function showCompleted(){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='block';}
              if(t){ t.innerHTML='<strong>Enjoy!</strong> ☺️'; }
              if(s){ s.textContent=''; }
              setBar(100);
            }
            function check(){
              var url = '<?php echo esc_url( rest_url('wcof/v1/order/') ); ?>' + orderId + '?k=' + encodeURIComponent(k) + '&_=' + Date.now();
              fetch(url, {credentials:'include', cache:'no-store'})
                .then(function(r){ return r.json(); })
                .then(function(d){
                  if(d && d.status === 'wc-processing'){ showConfirmed(d.eta, d.arrival); setTimeout(check, 5000); }
                  else if(d && d.status === 'wc-out-for-delivery'){ showOut(d.arrival); setTimeout(check, 8000); }
                  else if(d && d.status === 'wc-completed'){ showCompleted(); }
                  else if(d && d.status === 'wc-cancelled'){ showRejected(); }
                  else { if(tries < 240){ tries++; setTimeout(check, 4500); } }
                }).catch(function(){ setTimeout(check, 6000); });
            }
            check();
          })();
        </script>
        <?php
    }

    /* ===== Orders board (front) ===== */
    public function shortcode_orders_admin($atts=[]){
        if(!(current_user_can('manage_woocommerce') || current_user_can('wcof_rider')))
            return '<div class="wcof-alert">Solo gli amministratori o i rider possono vedere questa pagina.</div>';
        $args=['limit'=>50,'orderby'=>'date','order'=>'DESC','type'=>'shop_order','return'=>'objects'];
        if(!current_user_can('manage_woocommerce')){
            $set=$this->settings();
            $args['status']=!empty($set['rider_see_processing'])
                ? ['processing','out-for-delivery','completed']
                : ['out-for-delivery','completed'];
        }
        $orders = wc_get_orders($args);
        // Sort orders: awaiting first (newest), then processing (oldest first),
        // then out for delivery and completed
        usort($orders, function($a,$b){
            if(!$a instanceof WC_Order) $a = wc_get_order($a);
            if(!$b instanceof WC_Order) $b = wc_get_order($b);
            if(!$a || !$b) return 0;
            $order = [
                self::STATUS_AWAITING       => 0,
                'wc-processing'             => 1,
                self::STATUS_OUT_FOR_DELIVERY => 2,
                'wc-completed'              => 3,
            ];
            $sa = 'wc-'.$a->get_status();
            $sb = 'wc-'.$b->get_status();
            $pa = $order[$sa] ?? 99;
            $pb = $order[$sb] ?? 99;
            if($pa !== $pb) return $pa <=> $pb;
            $ida = (int)$a->get_id();
            $idb = (int)$b->get_id();
            if($sa === self::STATUS_AWAITING) return $idb <=> $ida; // newest first
            return $ida <=> $idb; // oldest first
        });
        $last_id=0;
        ob_start(); ?>
        <style>
          :root{ --wcf-card:#ffffff; --wcf-border:#e5e7eb; --wcf-shadow:0 6px 24px rgba(15,23,42,.06); --wcf-muted:#475569;}
          .wcof-wrap{display:flex;flex-direction:column;gap:18px}
          .wcof-card{background:var(--wcf-card);border:1px solid var(--wcf-border);border-radius:18px;box-shadow:var(--wcf-shadow);overflow:hidden}
          .wcof-head{display:grid;grid-template-columns:8px 1fr auto auto auto;gap:14px;align-items:center;padding:16px}
          .wcof-left{grid-column:1/2;width:6px;height:100%;border-radius:6px;grid-row:1/span 3}
          .st-await{background:linear-gradient(180deg,#fef08a,#facc15)}
          .st-proc {background:linear-gradient(180deg,#fed7aa,#fb923c)}
          .st-out  {background:linear-gradient(180deg,#bfdbfe,#60a5fa)}
          .st-comp {background:linear-gradient(180deg,#a7f3d0,#10b981)}
          .st-rej  {background:linear-gradient(180deg,#fecaca,#ef4444)}
          .wcof-title{margin:0;font-weight:600}
          .wcof-badge{display:inline-block;padding:.25rem .6rem;border-radius:8px;background:#f1f5f9;border:1px solid #cbd5e1;color:#1e293b;font-size:12px;margin-left:6px;font-weight:500}
          .wcof-arrival{display:inline-block;padding:.35rem .6rem;border-radius:8px;background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;font-weight:600}
          .wcof-items{grid-column:2/6;padding:12px 16px;background:#f9fafb;border-top:1px dashed var(--wcf-border)}
          .wcof-actions{display:flex;gap:8px;flex-wrap:wrap;justify-self:end;grid-column:2/6}
          .wcof-eta{width:90px;border:1px solid var(--wcf-border);border-radius:8px;padding:.5rem .55rem;font-size:14px}
          .btn{border:none;border-radius:8px;padding:.5rem .85rem;font-weight:600;font-size:14px;color:#fff;cursor:pointer;transition:background .2s;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;text-decoration:none}
          .btn-approve{background:#3b82f6} .btn-approve:hover{background:#2563eb}
          .btn-reject{background:#94a3b8} .btn-reject:hover{background:#6b7280}
          .btn-out{background:#f59e0b} .btn-out:hover{background:#d97706}
          .btn-complete{background:#10b981} .btn-complete:hover{background:#059669}
          .btn-toggle{background:#6b7280} .btn-toggle:hover{background:#4b5563}
          .btn-map{background:#3b82f6} .btn-map:hover{background:#2563eb}
          .btn-phone{background:#10b981} .btn-phone:hover{background:#059669}
          .wcof-info{margin-top:8px;font-size:14px;color:#334155}
          .wcof-info div{margin-top:4px}
          .wcof-address-extra{font-size:12px;color:#64748b;margin-top:2px}
          .wcof-map-buttons{margin-top:4px;display:flex;gap:4px;flex-wrap:wrap}
          .wcof-item{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #ececec}
          .wcof-item:last-child{border-bottom:0}
          .wcof-new{animation:wcofPulse 1s ease-in-out 5;background:#ecfdf5}
          @keyframes wcofPulse{0%{background:#d1fae5}50%{background:#ecfdf5}100%{background:#d1fae5}}

          @media (max-width: 760px){
            .wcof-head{display:flex;flex-direction:column;align-items:stretch;gap:10px;padding:14px}
            .wcof-left{display:none}
            .wcof-eta{width:100%}
            .btn{width:100%;padding:12px 14px;font-size:16px}
            .wcof-actions{display:grid;grid-template-columns:1fr;gap:8px;width:100%}
            .wcof-arrival{align-self:flex-start}
            .wcof-title{font-size:16px}
          }
          @media (max-width: 380px){
            .wcof-title{font-size:15px}
          }
          .wcof-sound{position:fixed;right:14px;bottom:14px;background:#111;color:#fff;border-radius:24px;padding:.6rem .95rem;cursor:pointer;opacity:.9;z-index:9999;display:none}
        </style>
        <div id="wcof-order-list" class="wcof-wrap">
        <?php foreach($orders as $o): if(!$o instanceof WC_Order){ $o=wc_get_order($o); if(!$o) continue; }
            $id=$o->get_id(); if($id>$last_id)$last_id=$id; $status='wc-'.$o->get_status();
            $eta=(int)$o->get_meta(self::META_ETA);
            $arrival_ts=(int)$o->get_meta(self::META_ARRIVAL);
            $arrival=$arrival_ts?date_i18n('H:i', $arrival_ts):null;
            $bar=$status===self::STATUS_AWAITING?'st-await':($status==='wc-processing'?'st-proc':($status===self::STATUS_OUT_FOR_DELIVERY?'st-out':($status==='wc-completed'?'st-comp':'st-rej')));
            $status_name = $this->status_name($status);
            $typed    = get_post_meta($o->get_id(), '_wcof_delivery_address', true);
            $resolved = get_post_meta($o->get_id(), '_wcof_delivery_resolved', true);
            $coords   = get_post_meta($o->get_id(), '_wcof_delivery_coords', true);
            $address  = $resolved;
            if(!$address){
                $address = trim(implode(', ', array_filter([
                    $o->get_shipping_address_1(), $o->get_shipping_address_2(),
                    trim($o->get_shipping_postcode().' '.$o->get_shipping_city())
                ])));
                if(!$address){
                    $address = trim(implode(', ', array_filter([
                        $o->get_billing_address_1(), $o->get_billing_address_2(),
                        trim($o->get_billing_postcode().' '.$o->get_billing_city())
                    ])));
                }
            }
            $phone = $o->get_billing_phone();
            $note  = $o->get_customer_note();
            $payment = '';
            if($o->get_payment_method() === 'cod'){
                $payment = 'CASH ON DELIVERY!';
            } elseif($o->is_paid()){
                $payment = 'already paid online';
            }
        ?>
          <div class="wcof-card" data-id="<?php echo esc_attr($id); ?>" data-status="<?php echo esc_attr($status); ?>">
            <div class="wcof-head">
              <div class="wcof-left <?php echo $bar; ?>"></div>
              <div class="wcof-meta">
                <p class="wcof-title">#<?php echo esc_html($o->get_order_number()); ?> <span class="wcof-badge"><?php echo esc_html($status_name); ?></span></p>
                <p style="color:var(--wcf-muted)"><?php echo esc_html( trim($o->get_formatted_billing_full_name()) ?: $o->get_billing_email() ); ?></p>
              </div>
              <div class="wcof-total"><strong><?php echo wp_kses_post($o->get_formatted_order_total()); ?></strong></div>
              <div class="wcof-arrival-wrap"><?php echo $arrival?'<span class="wcof-arrival">'.$arrival.'</span>':'—'; ?></div>
              <div class="wcof-items" <?php echo $status==='wc-completed'?'style="display:none"':''; ?>>
              <?php foreach($o->get_items() as $it): ?>
                <div class="wcof-item"><span><?php echo esc_html($it->get_name()); ?></span> <strong>× <?php echo (int)$it->get_quantity(); ?></strong></div>
              <?php endforeach; ?>
              <div class="wcof-info">
                <?php if($typed): ?>
                  <div>
                    <strong>Indirizzo digitato:</strong> <?php echo esc_html($typed); ?>
                    <?php if($address): ?><div class="wcof-address-extra">(<?php echo esc_html($address); ?>)</div><?php endif; ?>
                    <div class="wcof-map-buttons">
                      <a class="btn btn-map" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr(rawurlencode($typed)); ?>">Mappa indirizzo</a>
                      <?php if($coords): ?><a class="btn btn-map" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr(rawurlencode($coords)); ?>">Mappa coord</a><?php endif; ?>
                    </div>
                  </div>
                <?php elseif($address): ?>
                  <div>
                    <strong>Indirizzo mappa:</strong> <?php echo esc_html($address); ?>
                    <div class="wcof-map-buttons">
                      <a class="btn btn-map" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr(rawurlencode($address)); ?>">Mappa indirizzo</a>
                      <?php if($coords): ?><a class="btn btn-map" target="_blank" href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr(rawurlencode($coords)); ?>">Mappa coord</a><?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>
                <div><strong>Telefono:</strong> <?php echo esc_html($phone); ?><?php if($phone): ?> <a class="btn btn-phone" target="_blank" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>">📞</a><?php endif; ?></div>
                <?php if($payment): ?><div><strong>Payment:</strong> <?php echo esc_html($payment); ?></div><?php endif; ?>
                <?php if($note): ?><div><strong>Note:</strong> <?php echo esc_html($note); ?></div><?php endif; ?>
              </div>
              </div>
              <div class="wcof-actions">
                <?php if($status===self::STATUS_AWAITING): ?>
                  <input type="number" min="0" step="1" placeholder="ETA min" class="wcof-eta">
                  <button class="btn btn-approve" data-action="approve" data-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$id),'wcof_approve_'.$id) ); ?>">Approva</button>
                  <button class="btn btn-reject" data-action="reject" data-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$id),'wcof_reject_'.$id) ); ?>">Rifiuta</button>
                <?php elseif($status==='wc-processing'): ?>
                  <input type="number" min="0" step="1" placeholder="ETA min" value="<?php echo esc_attr($eta); ?>" class="wcof-eta">
                  <button class="btn btn-approve" data-action="approve" data-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_set_eta&order_id='.$id),'wcof_set_eta_'.$id) ); ?>">Aggiorna ETA</button>
                  <a class="btn btn-out" data-action="out" data-complete-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_complete&order_id='.$id),'wcof_complete_'.$id) ); ?>" href="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$id),'wcof_out_for_delivery_'.$id) ); ?>">In Consegna</a>
                <?php elseif($status===self::STATUS_OUT_FOR_DELIVERY): ?>
                  <a class="btn btn-complete" data-action="complete" href="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_complete&order_id='.$id),'wcof_complete_'.$id) ); ?>">Complete</a>
                <?php elseif($status==='wc-completed'): ?>
                  <button class="btn btn-toggle" data-action="toggle">Dettagli</button>
                <?php else: ?><em style="color:#94a3b8">—</em><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <button id="wcof-sound" class="wcof-sound" type="button" title="Sound">🔔 Sound</button>
        <?php
        wp_enqueue_script('wcof-orders', plugins_url('assets/orders-admin.js', __FILE__), [], '1.9.2', true);
        wp_localize_script('wcof-orders','WCOF_ORD',[
            'rest'=>esc_url_raw(rest_url('wcof/v1')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'last_id'=>$last_id
        ]);
        return ob_get_clean();
    }

    /* ===== Product manager (front) ===== */
    public function shortcode_product_manager($atts=[]){
        if(!current_user_can('manage_woocommerce')) return '';
        wp_enqueue_script('wcof-product-manager', plugins_url('assets/product-manager.js', __FILE__), [], '1.9.1', true);
        wp_localize_script('wcof-product-manager', 'WCOF_PM', [
            'root'  => esc_url_raw( rest_url('wc/v3/') ),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
        ob_start(); ?>
        <style>
          :root{--wcf-card:#ffffff;--wcf-border:#e5e7eb;--wcf-shadow:0 6px 24px rgba(15,23,42,.06);--wcf-accent:#3b82f6;--wcf-accent-light:#e0f2fe}
          #wcof-product-manager{font-family:sans-serif;display:flex;flex-direction:column;gap:18px}
          .wcof-cat{background:var(--wcf-card);border:1px solid var(--wcf-border);border-radius:18px;box-shadow:var(--wcf-shadow);overflow:hidden}
          .wcof-cat-header{display:flex;justify-content:space-between;align-items:center;padding:14px;background:var(--wcf-accent-light);font-weight:700}
          .wcof-prod-list{display:flex;flex-direction:column;gap:10px;padding:14px;background:var(--wcf-accent-light);border-top:1px dashed var(--wcf-border)}
          .wcof-prod{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid var(--wcf-border);border-radius:12px;padding:10px}
          .wcof-prod-title{font-weight:600}
          .wcof-active{display:flex;align-items:center;gap:6px}
          .btn{border:none;border-radius:12px;padding:.55rem .9rem;font-weight:700;color:#fff;cursor:pointer}
          .btn-add{background:var(--wcf-accent)}
          .btn-del{background:#ef4444}
          .btn-edit{background:#10b981}
          .wcof-form-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);display:flex;justify-content:center;align-items:center;padding:20px;z-index:9999}
          .wcof-prod-form{display:flex;flex-direction:column;gap:14px;background:#fff;padding:24px;border-radius:16px;box-shadow:var(--wcf-shadow);width:100%;max-width:600px}
          .wcof-prod-form input,.wcof-prod-form textarea,.wcof-prod-form select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1rem;box-sizing:border-box}
          .wcof-prod-form textarea{min-height:120px}
          .wcof-prod-form textarea.wcof-allergens{min-height:80px}
          .wcof-img-field{text-align:center;display:flex;flex-direction:column;gap:10px}
          .wcof-img-preview{width:200px;height:200px;border-radius:12px;object-fit:cover;display:none;margin:0 auto}
          .wcof-upload-btn{background:var(--wcf-accent);color:#fff;border:none;border-radius:8px;padding:.55rem .9rem;font-weight:700;cursor:pointer}
          .wcof-prod-form button{padding:12px;background:var(--wcf-accent);color:#fff;border:none;border-radius:8px;font-size:1rem}
          .wcof-switch{position:relative;display:inline-block;width:40px;height:22px}
          .wcof-switch input{opacity:0;width:0;height:0}
          .wcof-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#cbd5e1;transition:.2s;border-radius:22px}
          .wcof-slider:before{position:absolute;content:"";height:18px;width:18px;left:2px;bottom:2px;background:#fff;transition:.2s;border-radius:50%}
          .wcof-switch input:checked + .wcof-slider{background:#22c55e}
          .wcof-switch input:checked + .wcof-slider:before{transform:translateX(18px)}
        </style>
        <div id="wcof-product-manager"></div>
        <?php return ob_get_clean();
    }

    /* ===== Store settings + rider management (front) ===== */
    public function shortcode_store_settings($atts=[]){
        if(!current_user_can('manage_woocommerce')) return '';
        $s=$this->settings();
        ob_start(); ?>
        <style>
          :root{--wcf-card:#ffffff;--wcf-border:#e5e7eb;--wcf-shadow:0 6px 24px rgba(15,23,42,.06);--wcf-accent:#3b82f6;--wcf-accent-light:#e0f2fe}
          .wcof-store-settings{font-family:sans-serif;max-width:600px;margin:0 auto;background:var(--wcf-accent-light);padding:24px;border-radius:16px;box-shadow:var(--wcf-shadow)}
          .wcof-store-settings input[type=text],
          .wcof-store-settings input[type=email],
          .wcof-store-settings input[type=password],
          .wcof-store-settings input[type=number],
          .wcof-store-settings input[type=time],
          .wcof-store-settings select{width:100%;padding:12px;border:1px solid var(--wcf-border);border-radius:8px;box-sizing:border-box}
          .wcof-store-settings input[type=time]{width:auto;display:inline-block}
          .wcof-store-settings button{padding:12px;background:var(--wcf-accent);color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer}
        </style>
        <div class="wcof-store-settings">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px">
            <?php wp_nonce_field('wcof_save_store'); ?>
            <input type="hidden" name="action" value="wcof_save_store"/>
            <h2>Store settings</h2>
            <p><label>Address<br/><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address]" value="<?php echo esc_attr($s['address']); ?>"/></label></p>
            <p>Opening days:<br/>
              <?php foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $k=>$lbl): ?>
                <label style="margin-right:8px"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_days][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k,$s['open_days'],true)); ?>/> <?php echo esc_html($lbl); ?></label>
              <?php endforeach; ?>
            </p>
            <p><label>Opening time <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_time]" value="<?php echo esc_attr($s['open_time']); ?>"/> – <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[close_time]" value="<?php echo esc_attr($s['close_time']); ?>"/></label></p>
            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_closed]" value="1" <?php checked($s['store_closed'],1); ?>/> Store closed</label></p>
            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rider_see_processing]" value="1" <?php checked($s['rider_see_processing'],1); ?>/> Riders can see processing</label></p>
            <p><button type="submit">Save settings</button></p>
          </form>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wcof_add_rider'); ?>
            <input type="hidden" name="action" value="wcof_add_rider"/>
            <h2>Add rider</h2>
            <p><input type="text" name="rider_user" placeholder="Username" required/></p>
            <p><input type="email" name="rider_email" placeholder="Email" required/></p>
            <p><input type="password" name="rider_pass" placeholder="Password" required/></p>
            <p><button type="submit">Add rider</button></p>
          </form>
        </div>
        <?php return ob_get_clean();
    }

    public function handle_save_store(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        check_admin_referer('wcof_save_store');
        $current=$this->settings();
        $input=$_POST[self::OPTION_KEY]??[];
        $opts=array_merge($current,$this->sanitize_settings($input));
        update_option(self::OPTION_KEY,$opts);
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url());
        exit;
    }

    public function handle_add_rider(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        check_admin_referer('wcof_add_rider');
        $user=sanitize_user($_POST['rider_user']??'');
        $email=sanitize_email($_POST['rider_email']??'');
        $pass=$_POST['rider_pass']??wp_generate_password(12,true);
        if($user && $email && !username_exists($user) && !email_exists($email)){
            $uid=wp_create_user($user,$pass,$email);
            if(!is_wp_error($uid)){
                $u=new WP_User($uid);
                $u->set_role('rider');
            }
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url());
        exit;
    }

    /* ===== Settings page ===== */
    public function admin_menu(){
        add_submenu_page('woocommerce','ReeservaFood','ReeservaFood','manage_woocommerce','wcof-settings',[$this,'settings_page']);
        add_submenu_page(null,'ReeservaFood Setup','ReeservaFood Setup','manage_woocommerce','wcof-setup',[$this,'setup_page']);
    }
    public function register_settings(){
        register_setting(self::OPTION_KEY, self::OPTION_KEY, ['sanitize_callback'=>[$this,'sanitize_settings']]);
    }
    public function sanitize_settings($v){
        $v=is_array($v)?$v:[];
        $out=[
            'enable'=>!empty($v['enable'])?1:0,
            'app_id'=>isset($v['app_id'])?sanitize_text_field($v['app_id']):'',
            'rest_key'=>isset($v['rest_key'])?sanitize_text_field($v['rest_key']):'',
            'notify_admin_new'=>!empty($v['notify_admin_new'])?1:0,
            'notify_user_processing'=>!empty($v['notify_user_processing'])?1:0,
            'notify_user_out'=>!empty($v['notify_user_out'])?1:0,
            'address'=>isset($v['address'])?sanitize_text_field($v['address']):'',
            'open_time'=>isset($v['open_time'])?sanitize_text_field($v['open_time']):'',
            'close_time'=>isset($v['close_time'])?sanitize_text_field($v['close_time']):'',
            'store_closed'=>!empty($v['store_closed'])?1:0,
            'rider_see_processing'=>!empty($v['rider_see_processing'])?1:0,
            'postal_codes'=>isset($v['postal_codes'])?sanitize_text_field($v['postal_codes']):''
        ];
        $days=['mon','tue','wed','thu','fri','sat','sun'];
        $out['open_days']=[];
        if(!empty($v['open_days']) && is_array($v['open_days'])){
            foreach($v['open_days'] as $d){ if(in_array($d,$days,true)) $out['open_days'][]=$d; }
        }
        return $out;
    }
    public function settings(){
        $d=get_option(self::OPTION_KEY,[]);
        return wp_parse_args($d,[
            'enable'=>0,'app_id'=>'','rest_key'=>'',
            'notify_admin_new'=>1,'notify_user_processing'=>1,'notify_user_out'=>1,
            'address'=>'','open_days'=>[],'open_time'=>'09:00','close_time'=>'17:00','store_closed'=>0,'rider_see_processing'=>1,
            'postal_codes'=>''
        ]);
    }

    public function delivery_postal_codes(){
        $codes = array_map('trim', explode(',', $this->settings()['postal_codes']));
        $codes = array_filter($codes, function($c){ return $c!==''; });
        return $codes;
    }

    public function enqueue_checkout_scripts(){
        if( !function_exists('is_checkout') || !is_checkout() ) return;
        $codes = $this->delivery_postal_codes();
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('wcof-stripe-checkout', plugins_url('assets/stripe-checkout.css', __FILE__), [], '1.0');
        wp_enqueue_script('wcof-checkout-address', plugins_url('assets/checkout-address.js', __FILE__), ['leaflet'], '1.0', true);
        wp_localize_script('wcof-checkout-address', 'wcofCheckoutAddress', [
            'postalCodes' => $codes,
        ]);
    }



    public function render_checkout_address($checkout = null){
        if( !$checkout instanceof WC_Checkout ){
            $checkout = WC()->checkout();
        }
        $value = '';
        if( $checkout && method_exists($checkout, 'get_value') ){
            $value = $checkout->get_value('wcof_delivery_address');
        }
        echo '<div id="wcof-checkout-address">';
        woocommerce_form_field('wcof_delivery_address', [
            'type'     => 'text',
            'class'    => [ 'form-row-wide' ],
            'required' => true,

            'label'    => __('Address','wc-order-flow'),
        ], $value);
        // Error message container shown when the typed address is
        // outside the delivery area or cannot be resolved.
        echo '<p id="wcof-delivery-error" style="color:#dc2626;display:none;margin-top:4px"></p>';
        // Hidden field filled with the resolved address from the map.
        echo '<p><label for="wcof_delivery_resolved">'.esc_html__('Resolved address','wc-order-flow').'</label>\n'
            .'<input type="text" id="wcof_delivery_resolved" name="wcof_delivery_resolved" value="" style="width:100%" /></p>';
        // Hidden field used to prevent checkout unless a valid address
        // has been selected. It is filled via JS when the marker resolves
        // to an allowed postal code.
        echo '<input type="text" id="wcof_delivery_valid" name="wcof_delivery_valid" value="" style="position:absolute;left:-9999px;width:1px;height:1px;" />';
        // Hidden field that stores the marker coordinates.
        echo '<p><label for="wcof_delivery_coords">'.esc_html__('Coordinates','wc-order-flow').'</label>\n'
            .'<input type="text" id="wcof_delivery_coords" name="wcof_delivery_coords" value="" required style="width:100%" /></p>';
        // Allow users to correct the marker position manually.
        echo '<p class="wcof-move-marker"><a href="#" id="wcof-move-marker">'.esc_html__('Marker is wrong / let me set the marker','wc-order-flow').'</a></p>';
        echo '<div id="wcof-delivery-map" style="height:300px;margin-top:10px"></div>';
        echo '</div>';
    }

    public function hide_billing_fields($fields){
        // Hide unused address fields but keep city, postcode and phone visible
        $base = ['first_name','last_name','company','address_1','address_2','state','country','phone'];
        foreach(['billing','shipping'] as $section){
            foreach($base as $part){
                $key = $section . '_' . $part;
                if(isset($fields[$section][$key])){
                    $fields[$section][$key]['type'] = 'hidden';
                    $fields[$section][$key]['label'] = '';
                    $fields[$section][$key]['required'] = false;
                }
            }
        }
        // Unhide and require the billing phone field
        if(isset($fields['billing']['billing_phone'])){
            $fields['billing']['billing_phone']['type'] = 'tel';
            $fields['billing']['billing_phone']['label'] = __('Phone number','wc-order-flow');
            $fields['billing']['billing_phone']['required'] = true;
        }
        return $fields;
    }

    public function render_delivery_map(){
        echo '<div id="wcof-delivery-map" style="height:300px;margin-top:10px"></div>';
    }
    public function validate_checkout_address(){
        $codes = $this->delivery_postal_codes();
        $postcode = isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '';
        $address  = isset($_POST['wcof_delivery_address']) ? sanitize_text_field($_POST['wcof_delivery_address']) : '';
        $valid    = isset($_POST['wcof_delivery_valid']) ? sanitize_text_field($_POST['wcof_delivery_valid']) : '';
        $coords   = isset($_POST['wcof_delivery_coords']) ? sanitize_text_field($_POST['wcof_delivery_coords']) : '';
        if($valid !== '1'){
            wc_add_notice(__('Please confirm a valid address before paying.','wc-order-flow'), 'error');
            return;
        }elseif($address===''){
            wc_add_notice(__('Please enter a delivery address.','wc-order-flow'), 'error');
        }elseif($coords===''){
            wc_add_notice(__('Please select a valid address from the map.','wc-order-flow'), 'error');
        }elseif( !empty($codes) && !in_array($postcode, $codes, true) ){
            wc_add_notice(__('The address is outside of our delivery area.','wc-order-flow'), 'error');
        }
    }

    public function save_delivery_address($order_id){
        if(isset($_POST['wcof_delivery_address'])){
            $addr = sanitize_text_field($_POST['wcof_delivery_address']);
            if($addr !== ''){
                update_post_meta($order_id, '_wcof_delivery_address', $addr);
            }
        }
        if(isset($_POST['wcof_delivery_resolved'])){
            $resolved = sanitize_text_field($_POST['wcof_delivery_resolved']);
            if($resolved !== ''){
                update_post_meta($order_id, '_wcof_delivery_resolved', $resolved);
            }
        }
        if(isset($_POST['wcof_delivery_coords'])){
            $coords = sanitize_text_field($_POST['wcof_delivery_coords']);
            if($coords !== ''){
                update_post_meta($order_id, '_wcof_delivery_coords', $coords);
            }
        }
    }

    public function display_delivery_address_admin($order){
        $typed    = get_post_meta($order->get_id(), '_wcof_delivery_address', true);
        $resolved = get_post_meta($order->get_id(), '_wcof_delivery_resolved', true);
        $coords   = get_post_meta($order->get_id(), '_wcof_delivery_coords', true);
        if($typed){
            echo '<p><strong>'.esc_html__('Address','wc-order-flow').':</strong> '.esc_html($typed).'</p>';
        }
        if($resolved){
            echo '<p><strong>'.esc_html__('Map address','wc-order-flow').':</strong> '.esc_html($resolved).'</p>';
        }
        if($coords){
            echo '<p><strong>'.esc_html__('Coordinates','wc-order-flow').':</strong> '.esc_html($coords).'</p>';
        }
    }

    public function display_delivery_address_front($order){
        $typed  = $order->get_meta('_wcof_delivery_address');
        $coords = $order->get_meta('_wcof_delivery_coords');
        if(!$typed && !$coords) return;
        echo '<section class="woocommerce-order-delivery-address">';
        echo '<h2>'.esc_html__('Delivery address','wc-order-flow').'</h2>';
        if($typed){
            echo '<p><strong>'.esc_html__('Address','wc-order-flow').':</strong> '.esc_html($typed).'</p>';
        }
        if($coords){
            echo '<p><strong>'.esc_html__('Coordinates','wc-order-flow').':</strong> '.esc_html($coords).'</p>';
        }
        echo '</section>';
    }

    public function email_delivery_address_meta($fields, $sent_to_admin, $order){
        $typed    = $order->get_meta('_wcof_delivery_address');
        $resolved = $order->get_meta('_wcof_delivery_resolved');
        $coords   = $order->get_meta('_wcof_delivery_coords');
        if($typed){
            $fields['wcof_delivery_address'] = [
                'label' => __('Address','wc-order-flow'),
                'value' => $typed,
            ];
        }
        if($resolved){
            $fields['wcof_delivery_resolved'] = [
                'label' => __('Map address','wc-order-flow'),
                'value' => $resolved,
            ];
        }
        if($coords){
            $fields['wcof_delivery_coords'] = [
                'label' => __('Coordinates','wc-order-flow'),
                'value' => $coords,
            ];
        }
        return $fields;
    }

    public function settings_page(){
        $s=$this->settings(); ?>
        <div class="wrap">
          <h1>ReeservaFood Settings</h1>
          <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_KEY); ?>
            <h2>Store</h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row">Address</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address]" value="<?php echo esc_attr($s['address']); ?>"/></td></tr>
              <tr><th scope="row">Delivery postal codes</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[postal_codes]" value="<?php echo esc_attr($s['postal_codes']); ?>" placeholder="e.g. 00100,00101"/></td></tr>
              <tr><th scope="row">Opening days</th><td>
                <?php foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $k=>$lbl): ?>
                  <label style="margin-right:8px"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_days][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k,$s['open_days'],true)); ?>/> <?php echo esc_html($lbl); ?></label>
                <?php endforeach; ?>
              </td></tr>
              <tr><th scope="row">Opening time</th><td><input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_time]" value="<?php echo esc_attr($s['open_time']); ?>"/> – <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[close_time]" value="<?php echo esc_attr($s['close_time']); ?>"/></td></tr>
              <tr><th scope="row">Store closed</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_closed]" value="1" <?php checked($s['store_closed'],1); ?>/> Yes</label></td></tr>
              <tr><th scope="row">Riders see processing</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rider_see_processing]" value="1" <?php checked($s['rider_see_processing'],1); ?>/> Yes</label></td></tr>
            </table>
            <h2>OneSignal</h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row">Enable push</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable]" value="1" <?php checked($s['enable'],1); ?>/> On</label></td></tr>
              <tr><th scope="row">OneSignal App ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[app_id]" value="<?php echo esc_attr($s['app_id']); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"/></td></tr>
              <tr><th scope="row">OneSignal REST API Key</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[rest_key]" value="<?php echo esc_attr($s['rest_key']); ?>" placeholder="REST API Key (server)"/></td></tr>
            </table>
            <h2>Notify on</h2>
            <table class="form-table" role="presentation">
              <tr><th>Admin</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_admin_new]" value="1" <?php checked($s['notify_admin_new'],1); ?>/> New order</label></td></tr>
              <tr><th>User</th><td>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_user_processing]" value="1" <?php checked($s['notify_user_processing'],1); ?>/> Approved</label><br/>
                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_user_out]" value="1" <?php checked($s['notify_user_out'],1); ?>/> Rider on the way</label>
              </td></tr>
            </table>
            <?php submit_button(); ?>
          </form>
          <p><em>Service worker files are auto-served at <code>/OneSignalSDKWorker.js</code> and <code>/OneSignalSDKUpdaterWorker.js</code> via WordPress rewrites (no physical files needed).</em></p>
          <p><strong>Shortcodes</strong>: <code>[wcof_orders_admin]</code> (orders board), <code>[wcof_product_manager]</code> (product manager), <code>[wcof_push_button]</code> (subscribe button), <code>[wcof_push_debug]</code> (admin diagnostics).</p>
        </div>
        <?php
    }

    public function maybe_redirect_setup(){
        if( !current_user_can('manage_woocommerce') ) return;
        if( get_option('wcof_setup_done') ) return;
        global $pagenow;
        if( $pagenow === 'plugins.php' ) return;
        if( isset($_GET['action']) && $_GET['action'] === 'deactivate' ) return;
        if( isset($_GET['page']) && $_GET['page']==='wcof-setup' ) return;
        add_action('admin_notices', [$this,'setup_notice']);
    }

    public function setup_notice(){
        $url = esc_url(admin_url('admin.php?page=wcof-setup'));
        echo '<div class="notice notice-warning"><p>';
        echo 'Please complete the <a href="'.$url.'">ReeservaFood setup</a>.';
        echo '</p></div>';
    }

    public function setup_page(){
        $s=$this->settings(); ?>
        <div class="wrap">
          <h1>Welcome to ReeservaFood</h1>
          <p>Let's configure your store.</p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wcof_finish_setup'); ?>
            <input type="hidden" name="action" value="wcof_finish_setup"/>
            <table class="form-table" role="presentation">
              <tr><th scope="row">Shop address</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address]" value="<?php echo esc_attr($s['address']); ?>"/></td></tr>
              <tr><th scope="row">Opening days</th><td>
                <?php foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $k=>$lbl): ?>
                  <label style="margin-right:8px"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_days][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k,$s['open_days'],true)); ?>/> <?php echo esc_html($lbl); ?></label>
                <?php endforeach; ?>
              </td></tr>
              <tr><th scope="row">Opening time</th><td><input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[open_time]" value="<?php echo esc_attr($s['open_time']); ?>"/> – <input type="time" name="<?php echo esc_attr(self::OPTION_KEY); ?>[close_time]" value="<?php echo esc_attr($s['close_time']); ?>"/></td></tr>
              <tr><th scope="row">Products</th><td><a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="button">Manage products</a></td></tr>
            </table>
            <?php submit_button('Start Selling now','primary','start'); ?>
            <?php submit_button('Keep the store closed for now','secondary','keep'); ?>
          </form>
        </div>
        <?php
    }

    public function handle_finish_setup(){
        if(!current_user_can('manage_woocommerce')) wp_die('Non autorizzato');
        check_admin_referer('wcof_finish_setup');
        $current=$this->settings();
        $input=$_POST[self::OPTION_KEY]??[];
        $opts=array_merge($current,$this->sanitize_settings($input));
        $opts['store_closed']=isset($_POST['start'])?0:1;
        update_option(self::OPTION_KEY,$opts);
        update_option('wcof_setup_done',1);
        wp_safe_redirect(admin_url('admin.php?page=wcof-settings'));
        exit;
    }

    /* ===== OneSignal init + push senders ===== */
    public function maybe_inject_onesignal_sdk(){
        $s = $this->settings();
        if( empty($s['enable']) || empty($s['app_id']) ) return;
        wp_enqueue_script('wcof-onesignal', plugins_url('assets/onesignal-init.js', __FILE__), [], '1.9.0', true);
        wp_localize_script('wcof-onesignal', 'WCOF_PUSH', [
            'appId' => $s['app_id'],
            'userId' => get_current_user_id(),
            'isAdmin' => current_user_can('manage_woocommerce') ? 1 : 0,
        ]);
    }

    private function push_send($payload){
        $s = $this->settings();
        if( empty($s['enable']) || empty($s['app_id']) || empty($s['rest_key']) ) return;
        $payload['app_id'] = $s['app_id'];
        $res = wp_remote_post('https://onesignal.com/api/v1/notifications', [
            'headers' => [
                'Authorization' => 'Basic '.$s['rest_key'],
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'timeout'=> 15,
            'body' => wp_json_encode($payload)
        ]);
        if( is_wp_error($res) ) error_log('WCOF push error: '.$res->get_error_message());
    }
    public function push_new_order($order_id){
        $s = $this->settings(); if( empty($s['notify_admin_new']) ) return;
        $o = wc_get_order($order_id); if(!$o) return;
        $title = '🛎️ Nuevo pedido #'.$o->get_order_number();
        $url   = admin_url('post.php?post='.$order_id.'&action=edit');
        $this->push_send([
            'headings' => [ 'en'=>$title, 'es'=>$title, 'it'=>$title ],
            'contents' => [ 'en'=>'Total '.$o->get_formatted_order_total(), 'es'=>'Total '.$o->get_formatted_order_total(), 'it'=>'Totale '.$o->get_formatted_order_total() ],
            'url'      => $url,
            'filters'  => [ [ 'field'=>'tag','key'=>'wcof_role','relation'=>'=','value'=>'admin' ] ],
            'ttl'      => 120
        ]);
    }
    public function push_approved($order_id){
        $s = $this->settings(); if( empty($s['notify_user_processing']) ) return;
        $o = wc_get_order($order_id); if(!$o) return; $uid = (int)$o->get_user_id(); if(!$uid) return;
        $eta = (int)$o->get_meta(self::META_ETA);
        $title = '✅ Pedido confirmado #'.$o->get_order_number();
        $url = wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount'));
        $this->push_send([
            'headings'=>['en'=>$title,'es'=>$title,'it'=>$title],
            'contents'=>['en'=>'ETA ~ '.$eta.' min','es'=>'ETA ~ '.$eta.' min','it'=>'ETA ~ '.$eta.' min'],
            'url'=>$url,
            'include_external_user_ids' => [ (string)$uid ],
            'ttl'=>300
        ]);
    }
    public function push_out_for_delivery($order_id){
        $s = $this->settings(); if( empty($s['notify_user_out']) ) return;
        $o = wc_get_order($order_id); if(!$o) return; $uid = (int)$o->get_user_id(); if(!$uid) return;
        $title = '🚴 Rider en camino #'.$o->get_order_number();
        $url = wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount'));
        $this->push_send([
            'headings'=>['en'=>$title,'es'=>$title,'it'=>$title],
            'contents'=>['en'=>'Entrega en curso','es'=>'Entrega en curso','it'=>'Consegna in corso'],
            'url'=>$url,
            'include_external_user_ids' => [ (string)$uid ],
            'ttl'=>300
        ]);
    }

    /* ===== Frontend menu shortcode ===== */
    public function shortcode_castle_menu($atts=[]){
        if(!function_exists('wc_get_products')) return '';
        $args=[
            'status'=>'publish',
            'limit'=>-1,
            'orderby'=>'menu_order',
            'order'=>'ASC',
        ];
        $products=wc_get_products($args);
        if(empty($products)) return '<p>No products found.</p>';
        $currency=get_woocommerce_currency_symbol();
        $items=[];
        foreach($products as $p){
            if(!$p instanceof WC_Product) $p=wc_get_product($p);
            if(!$p) continue;
            $terms=get_the_terms($p->get_id(),'product_cat');
            if(!$terms || is_wp_error($terms)) continue;
            $section=$terms[0]->name;
            $items[]=[
                'id'=>$p->get_id(),
                'section'=>$section,
                'title'=>$p->get_name(),
                'desc'=>wp_strip_all_tags($p->get_short_description()),
                'price'=>wc_get_price_to_display($p),
                'img'=>wp_get_attachment_image_url($p->get_image_id(),'large'),
                'cart'=>$p->add_to_cart_url(),
                'ingredientes'=>wp_strip_all_tags($p->get_description()),
                'alergenos'=>[],
                'valores'=>'',
            ];
        }
        if(empty($items)) return '<p>No products found.</p>';
        wp_enqueue_script('wc-add-to-cart');
        ob_start(); ?>
        <style>
        .container {width:100%!important;}
        :root{--bg:#0f1115;--card:#171a21;--muted:#8b93a7;--text:#e9eef8;--brand:#6cf0c2;--accent:#00d1b2;--chip:#202532;--shadow:0 8px 24px rgba(0,0,0,.35);--radius:16px;}
        *{box-sizing:border-box}
        html,body{margin:0;height:100%;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Helvetica,Arial,sans-serif}
        a{color:inherit;text-decoration:none}
        .wrap{max-width:80%;margin:0 auto;padding:18px}
        header{position:sticky;top:0;z-index:20;background:linear-gradient(180deg,rgba(15,17,21,.98),rgba(15,17,21,.92) 60%,rgba(15,17,21,0));backdrop-filter:blur(8px)}
        .titlebar{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:10px 0;color:white;}
        .brand{display:flex;align-items:center;gap:12px}
        .brand .dot{width:14px;height:14px;border-radius:50%;background:var(--brand);box-shadow:0 0 0 6px rgba(108,240,194,.15)}
        h1{margin:0;font-size:20px;font-weight:700;letter-spacing:.3px}
        .search{position:relative;max-width:420px;width:100%;display:none;}
        .search input{width:100%;border:1px solid #2a3040;background:#121621;color:var(--text);border-radius:12px;padding:12px 40px;outline:none;box-shadow:var(--shadow)}
        .search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.7}
        .chips{display:flex;gap:10px;overflow:auto;padding:8px 0 14px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
        .chip{flex:0 0 auto;background:var(--chip);border:1px solid #2a3040;color:#d8deea;padding:8px 14px;border-radius:999px;cursor:pointer;transition:.2s;font-size:9px;white-space:nowrap}
        .chip.active{background:var(--accent);border-color:transparent;color:#041a16}
        .grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
        @media (max-width:640px){ .grid{grid-template-columns:1fr} }
        .section-title{margin:14px 0 10px;font-size:18px;font-weight:800;letter-spacing:.3px;color:#e8edf7;}
        .card{background:var(--card);border:1px solid #23293a;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
        .thumb{position:relative;background:#0c0f14;aspect-ratio:16/10}
        .thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
        .content{padding:14px}
        .title{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-bottom:6px}
        .title h3{margin:0;font-size:16px;line-height:1.25;color:white;}
        .price{font-weight:700;background:#0f131c;border:1px solid #2a3040;padding:6px 10px;border-radius:10px}
        .desc{color:var(--muted);font-size:13px;line-height:1.4;min-height:36px;margin:4px 0 10px}
        .row{display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap}
        .btn{border:1px solid #2a3040;background:#0f131c;color:#dfe7f6;border-radius:10px;padding:8px 10px;font-size:13px;cursor:pointer}
        .btn.primary{background:var(--accent);color:#011b15;border-color:transparent;font-weight:700}
        .btn:disabled{opacity:.6;cursor:not-allowed}
        .pill{display:inline-flex;align-items:center;gap:6px;border:1px dashed #2f364a;background:#121621;padding:6px 8px;border-radius:10px;font-size:12px;cursor:pointer}
        .pill:hover{border-style:solid}
        .modal-back{position:fixed;inset:0;background:rgba(4,7,12,.6);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:40}
        .modal{width:min(720px,96vw);max-height:90dvh;overflow:auto;background:#0f131c;border:1px solid #2a3040;border-radius:16px;box-shadow:var(--shadow)}
        .modal header{position:sticky;top:0;background:#0f131c;padding:12px;border-bottom:1px solid #23293a;display:flex;justify-content:space-between;align-items:center}
        .modal .body{padding:14px}
        .meta{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media (max-width:720px){.meta{display:block}}
        .meta section{border:1px solid #23293a;border-radius:12px;padding:10px;background:#0b0e14}
        .meta h4{margin:0 0 6px;font-size:13px;color:#b2bdd4;text-transform:uppercase}
        .alrg-icons{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
        .alrg-icons .ico{border:1px solid #2a3040;border-radius:9px;padding:6px 8px;display:flex;gap:6px;align-items:center}
        .ico.major{outline:2px solid var(--accent);outline-offset:2px}
        .close-x{border:none;background:transparent;color:#bcc7de;font-size:22px;cursor:pointer}
        @media (max-width:640px){.wrap{max-width:100%;padding:12px}.titlebar{flex-direction:column;align-items:stretch;gap:10px;padding:8px 0}.brand{justify-content:flex-start}.brand h1{font-size:18px}.search{max-width:none}.chips{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px 10px;overflow:visible;padding:10px 0 12px}.chip{width:100%;text-align:center;white-space:normal;padding:10px 12px}}
        </style>
        <header>
          <div class="wrap titlebar">
            <div class="brand">
              <h1 style="color:white;text-align:center!important;width:100%;font-family:cursive;padding:20px;">The Castle — La Carta</h1>
            </div>
            <div class="search">
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79L20 21.5 21.5 20 15.5 14zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
              <input style="display:none!important;" id="q" type="search" placeholder="Buscar platos o alérgenos…">
            </div>
          </div>
          <div class="wrap"><div id="chips" class="chips"></div></div>
        </header>
        <main class="wrap" id="top"><div id="sections"></div></main>
        <div id="back" class="modal-back" role="dialog" aria-modal="true">
          <div class="modal">
            <header><strong id="m-title"></strong><button class="close-x" id="m-close" aria-label="Cerrar">×</button></header>
            <div class="body">
              <div class="thumb" style="margin-bottom:12px"><img id="m-img" alt=""></div>
              <div class="price" id="m-price"></div>
              <p id="m-desc" class="desc"></p>
              <div class="alrg-icons" id="m-icons"></div>
              <div class="meta">
                <section><h4>Ingredientes</h4><div id="m-ingredientes" class="muted"></div></section>
                <section><h4>Alérgenos (texto completo)</h4><div id="m-alergenos" class="muted"></div></section>
                <section style="grid-column:1/-1"><h4>Valores nutricionales (100 g)</h4><div id="m-valores" class="muted"></div></section>
              </div>
            </div>
          </div>
        </div>
        <script>
        const MENU = <?php echo wp_json_encode($items, JSON_UNESCAPED_SLASHES); ?>;
        const CURRENCY = <?php echo json_encode($currency); ?>;
        const chipsEl = document.getElementById('chips');
        const sectionsEl = document.getElementById('sections');
        const sectionOrder = [...new Set(MENU.map(m=>m.section))];
        const slug = s => 'sec-' + s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
        function renderChips(){
          chipsEl.innerHTML='';
          const all=document.createElement('button');
          all.className='chip active';
          all.textContent='Todo';
          all.onclick=()=> document.getElementById('top').scrollIntoView({behavior:'smooth',block:'start'});
          chipsEl.appendChild(all);
          sectionOrder.forEach(s=>{
            const b=document.createElement('button');
            b.className='chip';
            b.textContent=s;
            b.onclick=()=>{const el=document.getElementById(slug(s)); if(el) el.scrollIntoView({behavior:'smooth',block:'start'});};
            chipsEl.appendChild(b);
          });
        }
        function cardHTML(m){
          const thumb = m.img ? `<div class="thumb"><img loading="lazy" src="${m.img}" alt="" onerror="this.closest('.thumb').remove()"></div>` : '';
          return `<div class="card">${thumb}<div class="content"><div class="title"><h3>${m.title}</h3><span class="price">${CURRENCY}${Number(m.price).toFixed(2)}</span></div><p class="desc">${m.desc||''}</p><div class="row"><button class="btn" onclick="openModal('${m.id}')">Alérgenos e info</button><a href="${m.cart}" data-product_id="${m.id}" class="btn primary add_to_cart_button ajax_add_to_cart">Añadir</a><span class="pill">${m.section}</span></div></div></div>`;
        }
        function render(){
          sectionsEl.innerHTML='';
          sectionOrder.forEach(sec=>{
            const secId=slug(sec);
            const group=MENU.filter(m=>m.section===sec);
            if(!group.length) return;
            const wrapper=document.createElement('section');
            wrapper.id=secId;
            const h=document.createElement('h2');
            h.className='section-title';
            h.textContent=sec;
            wrapper.appendChild(h);
            const grid=document.createElement('div');
            grid.className='grid';
            group.forEach(m=>grid.insertAdjacentHTML('beforeend', cardHTML(m)));
            wrapper.appendChild(grid);
            sectionsEl.appendChild(wrapper);
          });
        }
        function openModal(id){
          const m=MENU.find(x=>x.id==id); if(!m) return;
          document.getElementById('m-title').textContent=m.title;
          const imgEl=document.getElementById('m-img');
          imgEl.src=m.img||'';
          imgEl.parentElement.style.display=m.img?'':'none';
          document.getElementById('m-price').textContent=CURRENCY+Number(m.price).toFixed(2);
          document.getElementById('m-desc').textContent=m.desc||'';
          document.getElementById('m-ingredientes').textContent=m.ingredientes||'—';
          document.getElementById('m-alergenos').textContent=(m.alergenos||[]).join(', ')||'—';
          document.getElementById('m-valores').textContent=m.valores||'—';
          document.getElementById('back').style.display='flex';
        }
        document.getElementById('m-close').onclick=()=>document.getElementById('back').style.display='none';
        document.getElementById('back').onclick=e=>{ if(e.target.id==='back') e.currentTarget.style.display='none'; };
        renderChips();
        render();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ===== Push shortcodes ===== */
    public function shortcode_push_button($atts=[]){
        if( empty($this->settings()['enable']) ) return '';
        wp_enqueue_script('wcof-push-btn', plugins_url('assets/push-button.js', __FILE__), [], '1.9.0', true);
        ob_start(); ?>
        <style>.wcof-push-wrap{display:flex;align-items:center;gap:10px;margin:8px 0}.wcof-push-btn{background:#111;color:#fff;border:none;border-radius:999px;padding:.6rem 1rem;font-weight:700;cursor:pointer}.wcof-push-status{font-size:.9rem;color:#475569}</style>
        <div class="wcof-push-wrap">
          <button id="wcof-push-btn" class="wcof-push-btn">🔔 Enable notifications</button>
          <span id="wcof-push-status" class="wcof-push-status">Not subscribed</span>
        </div>
        <?php return ob_get_clean();
    }
    public function shortcode_push_debug($atts=[]){
        if(!current_user_can('manage_woocommerce')) return '';
        if( empty($this->settings()['enable']) ) return '<em>Enable push in settings first.</em>';
        wp_enqueue_script('wcof-push-debug', plugins_url('assets/push-debug.js', __FILE__), [], '1.9.0', true);
        return '<div id="wcof-push-debug" style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc"></div>';
    }
}
register_activation_hook(__FILE__, ['WCOF_Plugin','activate']);
register_deactivation_hook(__FILE__, ['WCOF_Plugin','deactivate']);
add_action('plugins_loaded', function(){ if(class_exists('WooCommerce')){ new WCOF_Plugin(); } });
