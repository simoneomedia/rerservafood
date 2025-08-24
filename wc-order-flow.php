<?php
/**
 * Plugin Name: Reeservafood
 * Description: App‑style order approvals for WooCommerce with ETA, “Rider on the way”, live order board, and integrated OneSignal Web Push (no extra plugin). Mobile‑first UI.
 * Author: Reeserva
 * Version: 1.8.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: wc-order-flow
 */

if (!defined('ABSPATH')) exit;

final class WCOF_Plugin {
    const OPTION_KEY = 'wcof_settings';
    const META_ETA   = '_wcof_eta_minutes';
    const META_DECIDED = '_wcof_decided';
    const META_LOCK    = '_wcof_lock';
    const STATUS_AWAITING = 'wc-awaiting-approval';
    const STATUS_REJECTED = 'wc-rejected';
    const STATUS_OUT_FOR_DELIVERY = 'wc-out-for-delivery';

    public static function activate(){
        $self = new self();
        $self->register_sw_rewrite();
        add_role('rider', 'Rider', ['read'=>true,'wcof_rider'=>true]);
        flush_rewrite_rules();
    }
    public static function deactivate(){
        remove_role('rider');
        flush_rewrite_rules();
    }

    public function __construct() {
        add_action('init', [$this,'register_statuses']);
        add_filter('wc_order_statuses', [$this,'add_statuses_to_list']);

        // Force new orders to awaiting approval
        add_action('woocommerce_checkout_order_processed', [$this,'set_order_awaiting'], 99, 3);
        add_action('woocommerce_new_order',               [$this,'force_awaiting_on_create'], 9999, 1);
        add_filter('woocommerce_payment_complete_order_status', [$this,'force_awaiting_on_payment_complete'], 9999, 3);
        add_action('woocommerce_order_status_changed',    [$this,'undo_auto_approval'], 9999, 4);

        // Metabox + admin actions
        add_action('add_meta_boxes', [$this,'add_metabox']);
        add_action('admin_post_wcof_approve', [$this,'handle_approve']);
        add_action('admin_post_wcof_reject',  [$this,'handle_reject']);
        add_action('admin_post_wcof_out_for_delivery', [$this,'handle_out_for_delivery']);
        add_action('admin_post_wcof_complete', [$this,'handle_complete']);

        // Thank you page hero (live)
        add_action('woocommerce_thankyou', [$this,'thankyou_hero'], 5);

        // Orders board (front)
        add_shortcode('wcof_orders_admin',   [$this,'shortcode_orders_admin']);

        // REST
        add_action('rest_api_init', [$this,'register_rest_routes']);

        // Settings + OneSignal
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_action('wp_enqueue_scripts', [$this,'maybe_inject_onesignal_sdk']);

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
        register_post_status(self::STATUS_AWAITING, [
            'label' => 'In attesa di approvazione',
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('In attesa di approvazione <span class="count">(%s)</span>','In attesa di approvazione <span class="count">(%s)</span>')
        ]);
        register_post_status(self::STATUS_REJECTED, [
            'label' => 'Rifiutato',
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Rifiutato <span class="count">(%s)</span>','Rifiutato <span class="count">(%s)</span>')
        ]);
        register_post_status(self::STATUS_OUT_FOR_DELIVERY, [
            'label' => 'In consegna',
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('In consegna <span class="count">(%s)</span>','In consegna <span class="count">(%s)</span>')
        ]);
    }
    public function add_statuses_to_list($s){
        $n=[]; foreach($s as $k=>$v){ $n[$k]=$v; if($k==='wc-pending'){ $n[self::STATUS_AWAITING]='In attesa di approvazione'; } }
        $n[self::STATUS_REJECTED]='Rifiutato'; $n[self::STATUS_OUT_FOR_DELIVERY]='In consegna'; return $n;
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
        if($order && !$order->get_meta(self::META_DECIDED)) return 'awaiting-approval';
        return $status;
    }
    public function undo_auto_approval($order_id, $old_status, $new_status, $order){
        if(!$order instanceof WC_Order) $order = wc_get_order($order_id);
        if(!$order || $order->get_meta(self::META_DECIDED)) return;
        if(in_array($new_status, ['processing','completed'], true)){
            $order->update_status(str_replace('wc-','', self::STATUS_AWAITING),'Forzato: in attesa di approvazione.');
        }
    }

    /* ===== Metabox ===== */
    public function add_metabox(){
        add_meta_box('wcof_metabox','Stato ordine (app style)',[$this,'render_metabox'],'shop_order','side','high');
    }
    public function render_metabox($post){
        $o = wc_get_order($post->ID); if(!$o) return;
        $eta = (int)$o->get_meta(self::META_ETA);
        $approve = wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$post->ID),'wcof_approve_'.$post->ID);
        $reject  = wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$post->ID), 'wcof_reject_'.$post->ID);
        $outurl  = wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$post->ID), 'wcof_out_for_delivery_'.$post->ID);
        ?>
        <p><label for="wcof_eta"><strong>Tempo di attesa (minuti)</strong></label></p>
        <p><input type="number" min="0" step="1" id="wcof_eta" value="<?php echo esc_attr($eta?:15); ?>" style="width:100%"></p>
        <p>
          <a class="button button-primary" href="<?php echo esc_url($approve); ?>" onclick="event.preventDefault();wcofSubmit(this);">Approva</a>
          <a class="button" href="<?php echo esc_url($reject); ?>" style="margin-left:6px" onclick="event.preventDefault();wcofSubmit(this);">Rifiuta</a>
        </p>
        <p><a class="button button-secondary" href="<?php echo esc_url($outurl); ?>" onclick="return confirm('Segnare come In consegna?');">Rider in consegna</a></p>
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
            $o->update_meta_data(self::META_ETA, $eta);
            $o->update_meta_data(self::META_DECIDED, 1);
            $o->save();
            $prev = $o->get_status();
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
            $o->update_status(str_replace('wc-','', self::STATUS_REJECTED),'Ordine rifiutato dall’amministratore.');
            $o->update_meta_data(self::META_DECIDED,1);
            $o->save();
        }
        wp_safe_redirect(wp_get_referer()?wp_get_referer():admin_url('post.php?post='.$order_id.'&action=edit')); exit;
    }
    public function handle_out_for_delivery(){
        if(!(current_user_can('manage_woocommerce') || current_user_can('wcof_rider'))) wp_die('Non autorizzato');
        $order_id = absint($_GET['order_id']??0);
        check_admin_referer('wcof_out_for_delivery_'.$order_id);
        $o = wc_get_order($order_id);
        if($o){
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
                $allowed = ['pending','processing','completed','on-hold','cancelled','refunded','failed','awaiting-approval','out-for-delivery','rejected'];
                if(!current_user_can('manage_woocommerce')){
                    $allowed = ['processing','out-for-delivery'];
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
                    $items=[]; foreach($o->get_items() as $it){ $items[]=['name'=>$it->get_name(),'qty'=>(int)$it->get_quantity()]; }
                    $total_raw = html_entity_decode( wp_strip_all_tags($o->get_formatted_order_total()), ENT_QUOTES, 'UTF-8' );
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
                    $phone = $o->get_billing_phone();
                    $note  = $o->get_customer_note();
                    $out[]=[
                        'id'=>$id,'number'=>$o->get_order_number(),
                        'status'=>'wc-'.$status_slug,'eta'=>$eta,
                        'arrival'=>$eta ? date_i18n('H:i', current_time('timestamp') + $eta*60) : null,
                        'total'=>$total_raw,
                        'customer'=>trim($o->get_formatted_billing_full_name()) ?: $o->get_billing_email(),
                        'items'=>$items,
                        'approve_url'=>wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$id),'wcof_approve_'.$id),
                        'reject_url' =>wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$id),'wcof_reject_'.$id),
                        'out_url'   =>wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$id),'wcof_out_for_delivery_'.$id),
                        'complete_url'=>wp_nonce_url(admin_url('admin-post.php?action=wcof_complete&order_id='.$id),'wcof_complete_'.$id),
                        'address'=>$address,
                        'phone'=>$phone,
                        'note'=>$note,
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
                return ['status'=>'wc-'.$o->get_status(),'eta'=>(int)$o->get_meta(self::META_ETA)];
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
            function showConfirmed(eta){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='block'; ic.style.animation='wcof-pop .5s ease-out';}
              var dt=new Date(Date.now()+ (parseInt(eta||0)*60000)); var hh=('0'+dt.getHours()).slice(-2); var mm=('0'+dt.getMinutes()).slice(-2);
              if(t){ t.innerHTML='<strong>Pedido confirmado.</strong> En preparación. <span class="wcof-chip">Llegada aprox. '+hh+':'+mm+'</span>'; }
              if(s){ s.textContent='Tiempo estimado: '+(eta||'?')+' min'; }
              setBar(60);
            }
            function showOut(eta){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='block';}
              var dt=new Date(Date.now()+ (parseInt(eta||0)*60000)); var hh=('0'+dt.getHours()).slice(-2); var mm=('0'+dt.getMinutes()).slice(-2);
              if(t){ t.innerHTML='<strong>Rider en camino.</strong> <span class="wcof-chip">Llegada '+hh+':'+mm+'</span>'; }
              if(s){ s.textContent='Puedes seguir el estado aquí.'; }
              setBar(90);
            }
            function showRejected(){
              var sp=document.getElementById('wcof-spinner'), ic=document.getElementById('wcof-icon'), t=document.getElementById('wcof-title'), s=document.getElementById('wcof-status');
              if(sp){sp.classList.add('wcof-hide');} if(ic){ic.style.display='none';}
              if(t){ t.innerHTML='<strong>Pedido rechazado.</strong>'; }
              if(s){ s.textContent='Lo sentimos, contacta con el establecimiento.'; }
              setBar(100);
            }
            function check(){
              var url = '<?php echo esc_url( rest_url('wcof/v1/order/') ); ?>' + orderId + '?k=' + encodeURIComponent(k) + '&_=' + Date.now();
              fetch(url, {credentials:'include', cache:'no-store'})
                .then(function(r){ return r.json(); })
                .then(function(d){
                  if(d && d.status === 'wc-processing'){ showConfirmed(d.eta); setTimeout(check, 5000); }
                  else if(d && d.status === 'wc-out-for-delivery'){ showOut(d.eta); setTimeout(check, 8000); }
                  else if(d && d.status === 'wc-rejected'){ showRejected(); }
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
            $args['status']=['processing','out-for-delivery'];
        }
        $orders = wc_get_orders($args);
        $last_id=0;
        ob_start(); ?>
        <style>
          :root{ --wcf-card:#ffffff; --wcf-border:#e5e7eb; --wcf-shadow:0 6px 24px rgba(15,23,42,.06); --wcf-muted:#475569;}
          .wcof-wrap{display:flex;flex-direction:column;gap:18px}
          .wcof-card{background:var(--wcf-card);border:1px solid var(--wcf-border);border-radius:18px;box-shadow:var(--wcf-shadow);overflow:hidden}
          .wcof-head{display:grid;grid-template-columns:8px 1fr auto auto auto;gap:14px;align-items:center;padding:16px}
          .wcof-left{grid-column:1/2;width:6px;height:100%;border-radius:6px}
          .st-await{background:linear-gradient(180deg,#93c5fd,#60a5fa)}
          .st-proc {background:linear-gradient(180deg,#86efac,#22c55e)}
          .st-out  {background:linear-gradient(180deg,#fef08a,#f59e0b)}
          .st-rej  {background:linear-gradient(180deg,#fecaca,#ef4444)}
          .wcof-title{margin:0;font-weight:700}
          .wcof-badge{display:inline-block;padding:.25rem .6rem;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#1e293b;font-size:12px;margin-left:6px}
          .wcof-arrival{display:inline-block;padding:.35rem .6rem;border-radius:10px;background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;font-weight:700}
          .wcof-actions{display:flex;gap:8px;flex-wrap:wrap;justify-self:end}
          .wcof-eta{width:90px;border:1px solid var(--wcf-border);border-radius:10px;padding:.55rem .6rem}
          .btn{border:none;border-radius:12px;padding:.6rem .9rem;font-weight:700;color:#fff;cursor:pointer}
          .btn-approve{background:#2563eb} .btn-reject{background:#94a3b8} .btn-complete{background:#10b981}
          .wcof-items{padding:12px 16px;background:#f9fafb;border-top:1px dashed var(--wcf-border)}
          .wcof-info{margin-top:8px;font-size:14px;color:#334155}
          .wcof-info div{margin-top:4px}
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
            $arrival=$eta?date_i18n('H:i', current_time('timestamp')+$eta*60):null;
            $bar=$status===self::STATUS_AWAITING?'st-await':($status==='wc-processing'?'st-proc':($status===self::STATUS_OUT_FOR_DELIVERY?'st-out':'st-rej')); ?>
          <div class="wcof-card" data-id="<?php echo esc_attr($id); ?>" data-status="<?php echo esc_attr($status); ?>">
            <div class="wcof-head">
              <div class="wcof-left <?php echo $bar; ?>"></div>
              <div class="wcof-meta">
                <p class="wcof-title">#<?php echo esc_html($o->get_order_number()); ?> <span class="wcof-badge"><?php echo esc_html($status); ?></span></p>
                <p style="color:var(--wcf-muted)"><?php echo esc_html( trim($o->get_formatted_billing_full_name()) ?: $o->get_billing_email() ); ?></p>
              </div>
              <div class="wcof-total"><strong><?php echo wp_kses_post($o->get_formatted_order_total()); ?></strong></div>
              <div class="wcof-arrival-wrap"><?php echo $arrival?'<span class="wcof-arrival">'.$arrival.'</span>':'—'; ?></div>
              <div class="wcof-actions">
                <?php if($status===self::STATUS_AWAITING): ?>
                  <input type="number" min="0" step="1" placeholder="ETA min" class="wcof-eta">
                  <button class="btn btn-approve" data-action="approve" data-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_approve&order_id='.$id),'wcof_approve_'.$id) ); ?>">Approva</button>
                  <button class="btn btn-reject" data-action="reject" data-url="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_reject&order_id='.$id),'wcof_reject_'.$id) ); ?>">Rifiuta</button>
                <?php elseif($status==='wc-processing'): ?>
                  <a class="btn btn-complete" data-action="out" href="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_out_for_delivery&order_id='.$id),'wcof_out_for_delivery_'.$id) ); ?>">In Consegna</a>
                <?php elseif($status===self::STATUS_OUT_FOR_DELIVERY): ?>
                  <a class="btn btn-complete" data-action="complete" href="<?php echo esc_attr( wp_nonce_url(admin_url('admin-post.php?action=wcof_complete&order_id='.$id),'wcof_complete_'.$id) ); ?>">Complete</a>
                <?php else: ?><em style="color:#94a3b8">—</em><?php endif; ?>
              </div>
            </div>
            <?php
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
                $phone = $o->get_billing_phone();
                $note  = $o->get_customer_note();
            ?>
            <div class="wcof-items">
              <?php foreach($o->get_items() as $it): ?>
                <div class="wcof-item"><span><?php echo esc_html($it->get_name()); ?></span> <strong>× <?php echo (int)$it->get_quantity(); ?></strong></div>
              <?php endforeach; ?>
              <div class="wcof-info">
                <div><strong>Indirizzo:</strong> <?php echo esc_html($address); ?></div>
                <div><strong>Telefono:</strong> <?php echo esc_html($phone); ?></div>
                <?php if($note): ?><div><strong>Note:</strong> <?php echo esc_html($note); ?></div><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <button id="wcof-sound" class="wcof-sound" type="button" title="Sound">🔔 Sound</button>
        <?php
        wp_enqueue_script('wcof-orders', plugins_url('assets/orders-admin.js', __FILE__), [], '1.8.2', true);
        wp_localize_script('wcof-orders','WCOF_ORD',[
            'rest'=>esc_url_raw(rest_url('wcof/v1')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'last_id'=>$last_id
        ]);
        return ob_get_clean();
    }

    /* ===== Settings page ===== */
    public function admin_menu(){
        add_submenu_page('woocommerce','Order Flow — Push','Order Flow — Push','manage_woocommerce','wcof-push',[$this,'settings_page']);
    }
    public function register_settings(){
        register_setting(self::OPTION_KEY, self::OPTION_KEY, ['sanitize_callback'=>[$this,'sanitize_settings']]);
    }
    public function sanitize_settings($v){
        $v=is_array($v)?$v:[];
        return [
            'enable'=>!empty($v['enable'])?1:0,
            'app_id'=>isset($v['app_id'])?sanitize_text_field($v['app_id']):'',
            'rest_key'=>isset($v['rest_key'])?sanitize_text_field($v['rest_key']):'',
            'notify_admin_new'=>!empty($v['notify_admin_new'])?1:0,
            'notify_user_processing'=>!empty($v['notify_user_processing'])?1:0,
            'notify_user_out'=>!empty($v['notify_user_out'])?1:0,
        ];
    }
    public function settings(){
        $d=get_option(self::OPTION_KEY,[]);
        return wp_parse_args($d,[
            'enable'=>0,'app_id'=>'','rest_key'=>'',
            'notify_admin_new'=>1,'notify_user_processing'=>1,'notify_user_out'=>1
        ]);
    }
    public function settings_page(){
        $s=$this->settings(); ?>
        <div class="wrap">
          <h1>Order Flow — Web Push (OneSignal)</h1>
          <form method="post" action="options.php">
            <?php settings_fields(self::OPTION_KEY); ?>
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
          <p><strong>Shortcodes</strong>: <code>[wcof_orders_admin]</code> (orders board), <code>[wcof_push_button]</code> (subscribe button), <code>[wcof_push_debug]</code> (admin diagnostics).</p>
        </div>
        <?php
    }

    /* ===== OneSignal init + push senders ===== */
    public function maybe_inject_onesignal_sdk(){
        $s = $this->settings();
        if( empty($s['enable']) || empty($s['app_id']) ) return;
        wp_enqueue_script('wcof-onesignal', plugins_url('assets/onesignal-init.js', __FILE__), [], '1.8.2', true);
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

    /* ===== Push shortcodes ===== */
    public function shortcode_push_button($atts=[]){
        if( empty($this->settings()['enable']) ) return '';
        wp_enqueue_script('wcof-push-btn', plugins_url('assets/push-button.js', __FILE__), [], '1.8.2', true);
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
        wp_enqueue_script('wcof-push-debug', plugins_url('assets/push-debug.js', __FILE__), [], '1.8.2', true);
        return '<div id="wcof-push-debug" style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc"></div>';
    }
}
register_activation_hook(__FILE__, ['WCOF_Plugin','activate']);
register_deactivation_hook(__FILE__, ['WCOF_Plugin','deactivate']);
add_action('plugins_loaded', function(){ if(class_exists('WooCommerce')){ new WCOF_Plugin(); } });
